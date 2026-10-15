<?php
/**
 * Separate checkout input adapters from the immutable accounting receipt.
 *
 * @package CoocadevInvoiceRoute
 */

namespace Coocadev\InvoiceRoute;

defined( 'ABSPATH' ) || exit;

/** Only the customer_invoice recipient filter is changed. */
final class Plugin {
	/**
	 * Order deletion locks held until Core completes deletion.
	 *
	 * @var array<int,string>
	 */
	private static $deletion_locks = array();

	/**
	 * Install an append-only receipt table with an atomic primary key.
	 *
	 * @param bool $network_wide Whether this is a network-wide activation.
	 */
	public static function activate( $network_wide = false ) {
		if ( $network_wide ) {
			wp_die( esc_html__( 'Activate InvoiceRoute separately on each site.', 'coocadev-invoiceroute' ) );
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $wpdb->prefix . 'ccir_receipts';
		$charset = $wpdb->get_charset_collate();
		// Table identifiers are generated exclusively from the trusted site prefix.
		dbDelta(
			"CREATE TABLE $table (
				order_id bigint(20) unsigned NOT NULL,
				email varchar(254) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (order_id)
			) $charset;"
		);
	}

	/** Declare the two WooCommerce surfaces implemented by this release. */
	public static function compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', dirname( __DIR__ ) . '/coocadev-invoiceroute.php', true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', dirname( __DIR__ ) . '/coocadev-invoiceroute.php', true );
		}
	}

	/** Register adapters only when the supported WooCommerce dependency exists. */
	public static function boot() {
		if ( ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, '10.2', '<' ) ) {
			return;
		}
		add_filter( 'woocommerce_checkout_fields', array( self::class, 'classic_field' ) );
		add_action( 'woocommerce_after_checkout_validation', array( self::class, 'classic_validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'classic_prepare' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_created', array( self::class, 'classic_freeze' ) );
		add_action( 'woocommerce_init', array( self::class, 'block_field' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'block_freeze' ) );
		add_filter( 'woocommerce_email_recipient_customer_invoice', array( self::class, 'recipient' ), 10, 3 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( self::class, 'admin_receipt' ) );
		add_action( 'woocommerce_delete_order', array( self::class, 'delete_order' ) );
		add_action( 'woocommerce_before_delete_order', array( self::class, 'before_delete' ) );
		add_action( 'before_delete_post', array( self::class, 'legacy_delete' ) );
		add_action( 'deleted_post', array( self::class, 'after_legacy_delete' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
		add_action( 'admin_init', array( self::class, 'privacy' ) );
	}

	/**
	 * A blank optional address is a valid, immutable choice to use the purchaser.
	 * Sanitizing a malformed email into a different valid address would be unsafe.
	 *
	 * @param mixed $value Untrusted field value.
	 * @return string|\WP_Error Valid address, blank, or validation error.
	 */
	public static function validate( $value ) {
		if ( ! is_string( $value ) || strlen( $value ) > 254 || ( '' !== $value && ! is_email( $value ) ) ) {
			return new \WP_Error( 'ccir_email', __( 'Enter one valid accounting email, or leave it empty.', 'coocadev-invoiceroute' ) );
		}
		return $value;
	}

	/** Add an optional classic checkout field without changing billing_email.
	 *
	 * @param array $fields Existing native checkout fields.
	 */
	public static function classic_field( $fields ) {
		$fields['billing']['ccir_accounting_email'] = array(
			'type'        => 'email',
			'label'       => __( 'Accounting email', 'coocadev-invoiceroute' ),
			'description' => __( 'Manual order-detail invoices go here. Other order emails still go to the purchaser.', 'coocadev-invoiceroute' ),
			'required'    => false,
			'class'       => array( 'form-row-wide' ),
			'priority'    => 125,
			'maxlength'   => 254,
		);
		return $fields;
	}

	/** Validate the already-unslashed classic checkout data.
	 *
	 * @param array     $data Normalized checkout values.
	 * @param \WP_Error $errors Native validation error collection.
	 */
	public static function classic_validate( $data, $errors ) {
		$result = self::validate( $data['ccir_accounting_email'] ?? '' );
		if ( is_wp_error( $result ) ) {
			$errors->add( $result->get_error_code(), $result->get_error_message() );
		}
	}

	/** Hold validated input on the unsaved order until Woo assigns its ID.
	 *
	 * @param \WC_Order $order WooCommerce order being processed.
	 * @param array     $data Normalized checkout values.
	 * @throws \Exception When the immutable checkout receipt cannot be persisted.
	 */
	public static function classic_prepare( $order, $data ) {
		$result = self::validate( $data['ccir_accounting_email'] ?? '' );
		if ( is_wp_error( $result ) ) {
			throw new \Exception( esc_html( $result->get_error_message() ) );
		}
		$order->update_meta_data( '_ccir_checkout_input', $result );
	}

	/** Freeze after save and before the classic payment/email flow.
	 *
	 * @param \WC_Order $order WooCommerce order being processed.
	 */
	public static function classic_freeze( $order ) {
		self::freeze_or_throw( $order, $order->get_meta( '_ccir_checkout_input', true ) );
	}

	/** Register the native Checkout Blocks additional field; no custom JS is needed. */
	public static function block_field() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		woocommerce_register_additional_checkout_field(
			array(
				'id'                         => 'coocadev-invoiceroute/accounting-email',
				'label'                      => __( 'Accounting email for manual invoices', 'coocadev-invoiceroute' ),
				'location'                   => 'order',
				'type'                       => 'text',
				'required'                   => false,
				'attributes'                 => array( 'maxLength' => 254 ),
				'show_in_order_confirmation' => false,
				'sanitize_callback'          => array( self::class, 'block_sanitize' ),
				'validate_callback'          => array( self::class, 'block_validate' ),
			)
		);
	}

	/** Preserve the value for strict validation instead of silently fixing it.
	 *
	 * @param mixed $value Untrusted optional field value.
	 */
	public static function block_sanitize( $value ) {
		return $value;
	}

	/** Return a native checkout field validation error.
	 *
	 * @param mixed $value Untrusted optional field value.
	 */
	public static function block_validate( $value ) {
		$result = self::validate( $value );
		return is_wp_error( $result ) ? $result : null;
	}

	/** Freeze on final checkout processing, rather than on an editable block draft.
	 *
	 * @param \WC_Order $order WooCommerce order being processed.
	 */
	public static function block_freeze( $order ) {
		self::freeze_or_throw( $order, $order->get_meta( '_wc_other/coocadev-invoiceroute/accounting-email', true ) );
	}

	/** Do not continue to payment if the chosen routing receipt cannot be saved.
	 *
	 * @param \WC_Order $order WooCommerce order being processed.
	 * @param string    $email Requested address or email object.
	 * @throws \Exception When the immutable checkout receipt cannot be persisted.
	 */
	private static function freeze_or_throw( $order, $email ) {
		$result = self::freeze( $order, $email );
		if ( is_wp_error( $result ) ) {
			throw new \Exception( esc_html( $result->get_error_message() ) );
		}
	}

	/**
	 * Insert once. A second request reuses the database winner, including blank.
	 * Order metadata is a readable mirror; the table is the routing authority.
	 *
	 * @param \WC_Order $order Persisted WooCommerce order.
	 * @param mixed     $email Optional accounting address.
	 * @return string|\WP_Error Saved receipt or a fail-closed error.
	 */
	public static function freeze( $order, $email ) {
		global $wpdb;
		$email = self::validate( $email );
		if ( is_wp_error( $email ) || ! $order instanceof \WC_Order || ! $order->get_id() ) {
			return is_wp_error( $email ) ? $email : new \WP_Error( 'ccir_order', __( 'A saved order is required.', 'coocadev-invoiceroute' ) );
		}
		$name = self::lock_name( $order->get_id() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The same connection-owned lock also protects permanent deletion.
		$held = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 2 ) );
		if ( '1' !== (string) $held ) {
			return new \WP_Error( 'ccir_busy', __( 'The order is being updated. Please try again.', 'coocadev-invoiceroute' ) );
		}
		try {
			if ( ! wc_get_order( $order->get_id() ) ) {
				return new \WP_Error( 'ccir_deleted', __( 'The order is no longer available.', 'coocadev-invoiceroute' ) );
			}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Prepared append-only plugin receipt, not WooCommerce storage; atomic primary key prevents retry races.
			$result = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (order_id, email, created_at) VALUES (%d, %s, %s)', $wpdb->prefix . 'ccir_receipts', $order->get_id(), $email, current_time( 'mysql', true ) ) );
			if ( false === $result ) {
				return new \WP_Error( 'ccir_storage', __( 'The accounting address could not be saved. Please try again.', 'coocadev-invoiceroute' ) );
			}
			$saved = self::receipt( $order->get_id() );
			if ( null === $saved ) {
				return new \WP_Error( 'ccir_storage', __( 'The accounting address could not be read back.', 'coocadev-invoiceroute' ) );
			}
			$order->update_meta_data( '_ccir_accounting_email', $saved );
			$order->delete_meta_data( '_ccir_checkout_input' );
			$order->save();
			return $saved;
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Release the exact scoped lock above on every path.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	/**
	 * Build a server-local, site-specific advisory lock name.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return string Bounded database lock identifier.
	 */
	private static function lock_name( $order_id ) {
		global $wpdb;
		return 'ccir_' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix . ':' . $order_id ), 0, 50 );
	}

	/**
	 * Hold the receipt mutex across WooCommerce's permanent deletion operation.
	 *
	 * @param int $order_id Order about to be deleted.
	 * @throws \RuntimeException When safe serialization cannot be obtained.
	 */
	public static function before_delete( $order_id ) {
		global $wpdb;
		if ( isset( self::$deletion_locks[ $order_id ] ) ) {
			return;
		}
		$name = self::lock_name( $order_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Serialize actual Core deletion with receipt writes; a post-delete cleanup alone leaves an orphan race.
		$held = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 2 ) );
		if ( '1' !== (string) $held ) {
			throw new \RuntimeException( esc_html__( 'InvoiceRoute could not safely lock this order for deletion. Please try again.', 'coocadev-invoiceroute' ) );
		}
		self::$deletion_locks[ $order_id ] = $name;
	}

	/** Return null for historical orders with no receipt; blank is intentional.
	 *
	 * @param int $order_id Persisted WooCommerce order ID.
	 */
	public static function receipt( $order_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Authoritative plugin receipt must bypass object cache during concurrent checkout retries.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT email FROM %i WHERE order_id = %d', $wpdb->prefix . 'ccir_receipts', $order_id ), ARRAY_A );
		// wpdb::get_var deliberately maps an empty string to null, so read the row to preserve a blank immutable choice.
		return is_array( $row ) ? $row['email'] : null;
	}

	/** This filter cannot affect processing, completed or shipping email IDs.
	 *
	 * @param string    $recipient Existing native email recipient.
	 * @param \WC_Order $order WooCommerce order being processed.
	 * @param string    $email Requested address or email object.
	 */
	public static function recipient( $recipient, $order, $email = null ) {
		if ( ! $order instanceof \WC_Order || ( $email && 'customer_invoice' !== $email->id ) ) {
			return $recipient;
		}
		$saved = self::receipt( $order->get_id() );
		return is_string( $saved ) && '' !== $saved && ! is_wp_error( self::validate( $saved ) ) ? $saved : $recipient;
	}

	/** Show a read-only receipt to the merchant on both order storage screens.
	 *
	 * @param \WC_Order $order WooCommerce order being processed.
	 */
	public static function admin_receipt( $order ) {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			return;
		}
		$email = self::receipt( $order->get_id() );
		echo '<p><strong>' . esc_html__( 'Accounting invoice recipient:', 'coocadev-invoiceroute' ) . '</strong> ' . esc_html( $email ? $email : __( 'Purchaser email (no separate address)', 'coocadev-invoiceroute' ) ) . '</p>';
	}

	/** Remove only this plugin's receipt after WooCommerce deletes an order.
	 *
	 * @param int $order_id Persisted WooCommerce order ID.
	 */
	public static function delete_order( $order_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table has no WordPress CRUD abstraction.
		$wpdb->delete( $wpdb->prefix . 'ccir_receipts', array( 'order_id' => (int) $order_id ), array( '%d' ) );
		if ( isset( self::$deletion_locks[ $order_id ] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- End the connection-owned lock acquired before Core's permanent deletion.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::$deletion_locks[ $order_id ] ) );
			unset( self::$deletion_locks[ $order_id ] );
		}
	}

	/** Legacy permanent deletion, not ordinary order trashing.
	 *
	 * @param int $post_id WordPress object being permanently deleted.
	 */
	public static function legacy_delete( $post_id ) {
		if ( 'shop_order' === get_post_type( $post_id ) ) {
			self::before_delete( $post_id );
		}
	}

	/**
	 * Legacy Core deletion can also occur outside WooCommerce's CRUD delete method.
	 *
	 * @param int $post_id Permanently deleted WordPress object.
	 */
	public static function after_legacy_delete( $post_id ) {
		if ( isset( self::$deletion_locks[ $post_id ] ) ) {
			self::delete_order( $post_id );
		}
	}

	/** Register WordPress's standard personal data workflow.
	 *
	 * @param array $exporters Registered privacy exporters.
	 */
	public static function exporters( $exporters ) {
		$exporters['ccir'] = array(
			'exporter_friendly_name' => __( 'InvoiceRoute accounting receipts', 'coocadev-invoiceroute' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	/** Match accounting addresses without exposing receipts to a public endpoint.
	 *
	 * @param string $email Requested address or email object.
	 * @param int    $page One-based bounded privacy page.
	 */
	public static function export( $email, $page = 1 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Prepared, bounded query on the plugin's own table for Core privacy export.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT order_id, email, created_at FROM %i WHERE email = %s ORDER BY order_id ASC LIMIT %d OFFSET %d', $wpdb->prefix . 'ccir_receipts', $email, 50, max( 0, ( (int) $page - 1 ) * 50 ) ), ARRAY_A );
		$data = array();
		foreach ( $rows as $row ) {
			$data[] = array(
				'group_id'    => 'ccir',
				'group_label' => __( 'Accounting invoice receipts', 'coocadev-invoiceroute' ),
				'item_id'     => 'ccir-' . $row['order_id'],
				'data'        => array(
					array(
						'name'  => __( 'Order ID', 'coocadev-invoiceroute' ),
						'value' => $row['order_id'],
					),
					array(
						'name'  => __( 'Accounting email', 'coocadev-invoiceroute' ),
						'value' => $row['email'],
					),
					array(
						'name'  => __( 'Captured at (UTC)', 'coocadev-invoiceroute' ),
						'value' => $row['created_at'],
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => count( $rows ) < 50,
		);
	}

	/** Orders may be retained for commercial recordkeeping; report it honestly.
	 *
	 * @param array $erasers Registered privacy erasers.
	 */
	public static function erasers( $erasers ) {
		$erasers['ccir'] = array(
			'eraser_friendly_name' => __( 'InvoiceRoute accounting receipts', 'coocadev-invoiceroute' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/** Retain immutable receipts until the corresponding order is permanently deleted.
	 *
	 * @param string $email Requested address or email object.
	 * @param int    $page One-based bounded privacy page.
	 */
	public static function erase( $email, $page = 1 ) {
		$export = self::export( $email, $page );
		return array(
			'items_removed'  => false,
			'items_retained' => ! empty( $export['data'] ),
			'messages'       => empty( $export['data'] ) ? array() : array( __( 'Accounting receipts follow the merchant order-retention policy. Permanently delete the associated order to remove its receipt.', 'coocadev-invoiceroute' ) ),
			'done'           => $export['done'],
		);
	}

	/** Provide accurate policy text rather than a compliance guarantee. */
	public static function privacy() {
		wp_add_privacy_policy_content( 'Coocadev InvoiceRoute', __( 'InvoiceRoute stores an optional accounting email with an immutable checkout receipt and uses it only for manually sent WooCommerce order-detail invoices. Such invoices may contain order details and payment links: supply an address authorized to receive them. Other order emails use the purchaser address. Receipts follow order retention and are removed when the order is permanently deleted while this plugin is active. Uninstall preserves historical receipts. No data is sent to Coocadev.', 'coocadev-invoiceroute' ) );
	}
}
