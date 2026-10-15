<?php
/**
 * Ownership checks, explicit approval and WooCommerce download permission CRUD.
 *
 * @package CoocadevDownloadRescue
 */

namespace Coocadev\DownloadRescue;

defined( 'ABSPATH' ) || exit;

/** Requests never grant entitlement. Only an authorized merchant can approve. */
final class Plugin {
	/**
	 * Locks retained until native order deletion finishes.
	 *
	 * @var array<int,string>
	 */
	private static $deletion_locks = array();

	/**
	 * Require explicit per-site activation; no network schema or broad cleanup is promised.
	 *
	 * @param bool $network_wide Whether this is a network-wide activation.
	 */
	public static function activate( $network_wide = false ) {
		if ( $network_wide ) {
			wp_die( esc_html__( 'Activate DownloadRescue separately on each site.', 'coocadev-downloadrescue' ) );
		}
	}

	/** This plugin changes neither checkout nor order storage. */
	public static function compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', dirname( __DIR__ ) . '/coocadev-downloadrescue.php', true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', dirname( __DIR__ ) . '/coocadev-downloadrescue.php', true );
		}
	}

	/** Register only when WooCommerce is present and supported. */
	public static function boot() {
		if ( ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, '10.2', '<' ) ) {
			return;
		}
		add_action( 'woocommerce_order_details_after_order_table', array( self::class, 'customer_screen' ) );
		add_action( 'admin_post_ccdr_request', array( self::class, 'handle_request' ) );
		add_action( 'admin_post_ccdr_decide', array( self::class, 'handle_decision' ) );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( self::class, 'merchant_screen' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
		add_action( 'admin_init', array( self::class, 'privacy' ) );
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'woocommerce_before_delete_order', array( self::class, 'before_delete' ) );
		add_action( 'woocommerce_delete_order', array( self::class, 'after_delete' ) );
		add_action( 'before_delete_post', array( self::class, 'before_legacy_delete' ) );
		add_action( 'deleted_post', array( self::class, 'after_delete' ) );
	}

	/**
	 * Serialize mutations and reload the order inside the lock to reject stale requests.
	 *
	 * @param int      $order_id Order to protect.
	 * @param callable $callback Work requiring a fresh WC_Order.
	 * @return mixed|\WP_Error Callback result or busy error.
	 */
	public static function locked( $order_id, $callback ) {
		global $wpdb;
		$name = 'ccdr_' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix . ':' . $order_id ), 0, 50 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Connection-owned lock spans WC CRUD and one mail invocation; no unsafe expiring lease.
		$held = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 2 ) );
		if ( '1' !== (string) $held ) {
			return new \WP_Error( 'ccdr_busy', __( 'This order is being updated. Please try again.', 'coocadev-downloadrescue' ) );
		}
		try {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->read_meta_data( true );
			}
			return $callback( $order );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Release the exact lock acquired above, including error paths.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	/** Paid and download-permitted orders only; any refund requires native manual review.
	 *
	 * @param \WC_Order $order WooCommerce order being processed.
	 */
	public static function eligible_order( $order ) {
		return $order instanceof \WC_Order && $order->is_paid() && $order->is_download_permitted() && ! $order->get_refunds();
	}

	/**
	 * WC_Order::save() can catch storage exceptions and return the old ID. Confirm
	 * the request and queue enum through forced metadata readback before any grant
	 * or resend. An in-memory order object is never evidence of persistence.
	 *
	 * @param \WC_Order $order Order held under its request mutex.
	 * @param array     $state Exact request generation and state.
	 * @return bool Whether both fields are durably stored.
	 */
	private static function persist_state( $order, $state ) {
		$order->update_meta_data( '_ccdr_request', $state );
		$order->update_meta_data( '_ccdr_status', $state['status'] );
		$order->save();
		$fresh = wc_get_order( $order->get_id() );
		if ( ! $fresh ) {
			return false;
		}
		$fresh->read_meta_data( true );
		return $state === $fresh->get_meta( '_ccdr_request', true ) && $state['status'] === $fresh->get_meta( '_ccdr_status', true );
	}

	/**
	 * Find expired/exhausted existing permissions, never an arbitrary file or new URL.
	 * A permission must still correspond to a purchased product and its current file.
	 *
	 * @param \WC_Order $order Order being inspected.
	 * @return array<\WC_Customer_Download> Existing renewable permission objects.
	 */
	public static function expired_permissions( $order ) {
		if ( ! self::eligible_order( $order ) ) {
			return array();
		}
		$products = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product && $product->is_downloadable() ) {
				$products[ $product->get_id() ] = $product;
			}
		}
		$store       = \WC_Data_Store::load( 'customer-download' );
		$permissions = $store->get_downloads(
			array(
				'order_id' => $order->get_id(),
				'limit'    => 101,
			)
		);
		if ( count( $permissions ) > 100 ) {
			return array(); // Large custom orders remain in the native WooCommerce workflow.
		}
		$expired = array();
		foreach ( $permissions as $permission ) {
			$product = $products[ $permission->get_product_id() ] ?? null;
			$files   = $product ? $product->get_downloads() : array();
			$expires = $permission->get_access_expires();
			$empty   = '0' === (string) $permission->get_downloads_remaining();
			if ( ! $product || ! isset( $files[ $permission->get_download_id() ] ) || ! $files[ $permission->get_download_id() ]->get_enabled() || (int) $permission->get_user_id() !== (int) $order->get_customer_id() || $permission->get_order_key() !== $order->get_order_key() || $permission->get_user_email() !== $order->get_billing_email() ) {
				continue;
			}
			// WooCommerce download expiry is a calendar date, valid through that date.
			if ( $empty || ( $expires && $expires->getTimestamp() < strtotime( 'midnight', time() ) ) ) {
				$expired[] = $permission;
			}
		}
		return $expired;
	}

	/**
	 * Record one pending request for the authenticated purchaser. No mail or grant occurs.
	 *
	 * @param int $order_id Order requested by the signed-in customer.
	 * @return array|\WP_Error Pending status or an ownership/eligibility/rate error.
	 */
	public static function request( $order_id ) {
		return self::locked(
			$order_id,
			static function ( $order ) {
				if ( ! is_user_logged_in() || ! $order || ! $order->get_customer_id() || (int) $order->get_customer_id() !== get_current_user_id() ) {
					return new \WP_Error( 'ccdr_owner', __( 'This order is not available to your account.', 'coocadev-downloadrescue' ) );
				}
				if ( ! self::eligible_order( $order ) ) {
					return new \WP_Error( 'ccdr_status', __( 'Contact the store for help with this order.', 'coocadev-downloadrescue' ) );
				}
				$previous = $order->get_meta( '_ccdr_request', true );
				if ( is_array( $previous ) && 'pending' === ( $previous['status'] ?? '' ) ) {
					return $previous;
				}
				if ( is_array( $previous ) && (int) ( $previous['at'] ?? 0 ) > time() - DAY_IN_SECONDS ) {
					return new \WP_Error( 'ccdr_rate', __( 'Please wait 24 hours before another request for this order.', 'coocadev-downloadrescue' ) );
				}
				$expired = self::expired_permissions( $order );
				if ( ! $expired ) {
					return new \WP_Error( 'ccdr_no_expired', __( 'No renewable expired downloads were found. Contact the store if you still need help.', 'coocadev-downloadrescue' ) );
				}
				$state = array(
					'status'         => 'pending',
					'at'             => time(),
					'token'          => wp_generate_uuid4(),
					'permission_ids' => array_map(
						static function ( $permission ) {
							return $permission->get_id();
						},
						$expired
					),
				);
				if ( ! self::persist_state( $order, $state ) ) {
					return new \WP_Error( 'ccdr_storage', __( 'The renewal request could not be saved. Please try again.', 'coocadev-downloadrescue' ) );
				}
				return $state;
			}
		);
	}

	/**
	 * Approve a specific pending generation, or reject it. Approval extends only the
	 * eligible permissions that were requested; payment, status and URLs never change.
	 *
	 * @param int    $order_id Order to review.
	 * @param string $token Pending request generation.
	 * @param bool   $approve Whether to explicitly grant seven days and five downloads.
	 * @return array|\WP_Error Decision result.
	 */
	public static function decide( $order_id, $token, $approve ) {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_shop_order', $order_id ) || ! is_string( $token ) || ! is_bool( $approve ) ) {
			return new \WP_Error( 'ccdr_forbidden', __( 'Not allowed.', 'coocadev-downloadrescue' ) );
		}
		return self::locked(
			$order_id,
			static function ( $order ) use ( $token, $approve ) {
				if ( ! self::eligible_order( $order ) ) {
					return new \WP_Error( 'ccdr_status', __( 'The order no longer permits downloads. Review it in WooCommerce.', 'coocadev-downloadrescue' ) );
				}
				$state = $order->get_meta( '_ccdr_request', true );
				if ( ! is_array( $state ) || 'pending' !== ( $state['status'] ?? '' ) || ! hash_equals( $state['token'], $token ) ) {
					return new \WP_Error( 'ccdr_stale', __( 'This request has already changed. Reload the order.', 'coocadev-downloadrescue' ) );
				}
				if ( ! $approve ) {
					$state['status'] = 'rejected';
					if ( ! self::persist_state( $order, $state ) ) {
						return new \WP_Error( 'ccdr_storage', __( 'The decision could not be saved. Please try again.', 'coocadev-downloadrescue' ) );
					}
					return $state;
				}
				$permissions = array_filter(
					self::expired_permissions( $order ),
					static function ( $permission ) use ( $state ) {
						return in_array( $permission->get_id(), $state['permission_ids'], true );
					}
				);
				if ( ! $permissions ) {
					return new \WP_Error( 'ccdr_changed', __( 'The requested download permissions have changed. Review them in WooCommerce.', 'coocadev-downloadrescue' ) );
				}
				// Persist the action claim first: after a crash, retries cannot duplicate a grant or mail.
				$state['status']     = 'granting';
				$state['decided_at'] = time();
				if ( ! self::persist_state( $order, $state ) ) {
					return new \WP_Error( 'ccdr_storage', __( 'The grant claim could not be saved. No permissions were changed or email sent.', 'coocadev-downloadrescue' ) );
				}
				// Native WC permission updates persist only Y-m-d, so verify the same calendar-date boundary.
				$expiry = strtotime( 'midnight', time() + 7 * DAY_IN_SECONDS );
				foreach ( $permissions as $permission ) {
					$permission->set_access_expires( $expiry );
					if ( '' !== $permission->get_downloads_remaining() && (int) $permission->get_downloads_remaining() < 5 ) {
						$permission->set_downloads_remaining( 5 );
					}
					$permission->save();
					$confirmed = new \WC_Customer_Download( $permission->get_id() );
					if ( ! $confirmed->get_access_expires() || $confirmed->get_access_expires()->getTimestamp() !== $expiry || (string) $confirmed->get_downloads_remaining() !== (string) $permission->get_downloads_remaining() ) {
						return new \WP_Error( 'ccdr_storage', __( 'A permission could not be verified. Review native WooCommerce download permissions; no email was sent.', 'coocadev-downloadrescue' ) );
					}
				}
				$state['status'] = 'approved';
				if ( ! self::persist_state( $order, $state ) ) {
					return new \WP_Error( 'ccdr_storage', __( 'The approval could not be saved. Review the native permissions; the durable claim prevents resending.', 'coocadev-downloadrescue' ) );
				}
				// Native order email keeps WooCommerce's customer links, templates and recipient policy.
				$emails = WC()->mailer()->get_emails();
				$class  = $order->has_status( 'completed' ) ? 'WC_Email_Customer_Completed_Order' : 'WC_Email_Customer_Processing_Order';
				if ( isset( $emails[ $class ] ) ) {
					$emails[ $class ]->trigger( $order->get_id(), $order );
				}
				return $state;
			}
		);
	}

	/** Show private controls only inside the signed-in purchaser's own account.
	 *
	 * @param \WC_Order $order WooCommerce order being processed.
	 */
	public static function customer_screen( $order ) {
		if ( ! is_account_page() || ! is_user_logged_in() || ! $order->get_customer_id() || (int) $order->get_customer_id() !== get_current_user_id() || ! self::eligible_order( $order ) ) {
			return;
		}
		nocache_headers();
		$state = $order->get_meta( '_ccdr_request', true );
		echo '<section><h2>' . esc_html__( 'Download help', 'coocadev-downloadrescue' ) . '</h2>';
		if ( is_array( $state ) && 'pending' === ( $state['status'] ?? '' ) ) {
			echo '<p>' . esc_html__( 'Your renewal request is waiting for the store to review it.', 'coocadev-downloadrescue' ) . '</p>';
		} elseif ( self::expired_permissions( $order ) ) {
			echo '<p>' . esc_html__( 'Request a review of expired or used-up downloads. The store must approve any extension.', 'coocadev-downloadrescue' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ccdr_request"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">';
			wp_nonce_field( 'ccdr_request_' . $order->get_id() );
			echo '<button type="submit" class="button">' . esc_html__( 'Request download renewal', 'coocadev-downloadrescue' ) . '</button></form>';
		} else {
			echo '<p>' . esc_html__( 'Your current downloads do not need renewal.', 'coocadev-downloadrescue' ) . '</p>';
		}
		echo '</section>';
	}

	/** Render explicit merchant approval controls on both Woo order screens.
	 *
	 * @param \WC_Order $order WooCommerce order being processed.
	 */
	public static function merchant_screen( $order ) {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			return;
		}
		$state = $order->get_meta( '_ccdr_request', true );
		if ( ! is_array( $state ) ) {
			return;
		}
		echo '<div><h3>' . esc_html__( 'DownloadRescue request', 'coocadev-downloadrescue' ) . '</h3><p>' . esc_html( $state['status'] ) . '</p>';
		if ( 'pending' === $state['status'] ) {
			// Associate buttons with a separate footer form so normal WooCommerce save actions remain intact.
			$form_id = 'ccdr-decision-' . $order->get_id();
			echo '<p>' . esc_html__( 'Approve extends the requested existing permissions for seven days and restores at least five downloads. It resends the native customer order email once.', 'coocadev-downloadrescue' ) . '</p>';
			echo '<button type="submit" class="button" form="' . esc_attr( $form_id ) . '" name="ccdr_decision" value="approve">' . esc_html__( 'Approve and resend', 'coocadev-downloadrescue' ) . '</button> <button type="submit" class="button" form="' . esc_attr( $form_id ) . '" name="ccdr_decision" value="reject">' . esc_html__( 'Reject request', 'coocadev-downloadrescue' ) . '</button>';
			add_action(
				'admin_footer',
				static function () use ( $form_id, $order, $state ) {
					echo '<form id="' . esc_attr( $form_id ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ccdr_decide"><input type="hidden" name="ccdr_order_id" value="' . esc_attr( $order->get_id() ) . '"><input type="hidden" name="ccdr_token" value="' . esc_attr( $state['token'] ) . '">';
					wp_nonce_field( 'ccdr_decide_' . $order->get_id(), 'ccdr_nonce' );
					echo '</form>';
				}
			);
		}
		echo '</div>';
	}

	/** Accept only decimal, scalar order identifiers.
	 *
	 * @param string $name Allowlisted internal input field name.
	 */
	private static function posted_id( $name ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decimal regex plus absint validates the ID solely to bind the nonce verified immediately by both callers.
		return isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) && preg_match( '/^[1-9][0-9]{0,17}$/D', wp_unslash( $_POST[ $name ] ) ) ? absint( wp_unslash( $_POST[ $name ] ) ) : 0;
	}

	/** Add a bounded merchant queue so older orders can be found for review. */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'DownloadRescue', 'coocadev-downloadrescue' ), __( 'DownloadRescue', 'coocadev-downloadrescue' ), 'manage_woocommerce', 'ccdr', array( self::class, 'queue_screen' ) );
	}

	/**
	 * Read matching orders through WooCommerce CRUD in both storage engines.
	 *
	 * @param int $page One-based result page.
	 * @return array Matching orders, never download URLs.
	 */
	public static function pending_orders( $page = 1 ) {
		return wc_get_orders(
			array(
				'limit'      => 50,
				'page'       => max( 1, (int) $page ),
				'orderby'    => 'ID',
				'order'      => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded native WC order API query; this queue enum is supported by both HPOS and legacy storage.
				'meta_key'   => '_ccdr_status',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Exact pending enum, 50 orders per page; no user-controlled search.
				'meta_value' => 'pending',
			)
		);
	}

	/** Render only request IDs and edit links for authorized merchants. */
	public static function queue_screen() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination changes no state.
		$page   = isset( $_GET['ccdr_page'] ) && is_string( $_GET['ccdr_page'] ) ? max( 1, absint( wp_unslash( $_GET['ccdr_page'] ) ) ) : 1;
		$orders = self::pending_orders( $page );
		echo '<div class="wrap"><h1>' . esc_html__( 'DownloadRescue requests', 'coocadev-downloadrescue' ) . '</h1><p>' . esc_html__( 'Open an order to review its request. Approval extends existing expired or exhausted permissions for seven days and resends the native customer order email.', 'coocadev-downloadrescue' ) . '</p><ul>';
		foreach ( $orders as $order ) {
			if ( current_user_can( 'edit_shop_order', $order->get_id() ) ) {
				echo '<li><a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html__( 'Review order', 'coocadev-downloadrescue' ) . ' #' . esc_html( $order->get_order_number() ) . '</a></li>';
			}
		}
		echo '</ul><p>';
		if ( $page > 1 ) {
			echo '<a class="button" href="' . esc_url(
				add_query_arg(
					array(
						'page'      => 'ccdr',
						'ccdr_page' => $page - 1,
					),
					admin_url( 'admin.php' )
				)
			) . '">' . esc_html__( 'Previous', 'coocadev-downloadrescue' ) . '</a> ';
		}
		if ( count( $orders ) === 50 ) {
			echo '<a class="button" href="' . esc_url(
				add_query_arg(
					array(
						'page'      => 'ccdr',
						'ccdr_page' => $page + 1,
					),
					admin_url( 'admin.php' )
				)
			) . '">' . esc_html__( 'Next', 'coocadev-downloadrescue' ) . '</a>';
		}
		echo '</p></div>';
	}

	/**
	 * Prevent request writes while Core permanently deletes the matching order.
	 *
	 * @param int $order_id Order being permanently deleted.
	 * @throws \RuntimeException When safe serialization cannot be obtained.
	 */
	public static function before_delete( $order_id ) {
		global $wpdb;
		if ( isset( self::$deletion_locks[ $order_id ] ) ) {
			return;
		}
		$name = 'ccdr_' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix . ':' . $order_id ), 0, 50 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Same connection-owned mutex used by request, approval and erasure.
		$held = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 2 ) );
		if ( '1' !== (string) $held ) {
			throw new \RuntimeException( esc_html__( 'DownloadRescue is updating this order. Please retry deletion.', 'coocadev-downloadrescue' ) );
		}
		self::$deletion_locks[ $order_id ] = $name;
	}

	/**
	 * Cover direct WordPress permanent deletion in legacy order storage.
	 *
	 * @param int $post_id WordPress object about to be deleted.
	 */
	public static function before_legacy_delete( $post_id ) {
		if ( 'shop_order' === get_post_type( $post_id ) ) {
			self::before_delete( $post_id );
		}
	}

	/**
	 * Release only a lock held by a matching deletion hook in this process.
	 *
	 * @param int $order_id Permanently deleted order ID.
	 */
	public static function after_delete( $order_id ) {
		global $wpdb;
		if ( isset( self::$deletion_locks[ $order_id ] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- End this process's scoped deletion mutex.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::$deletion_locks[ $order_id ] ) );
			unset( self::$deletion_locks[ $order_id ] );
		}
	}

	/** Customer POST adapter: authentication, nonce, then the ownership domain check. */
	public static function handle_request() {
		if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) || ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Not allowed.', 'coocadev-downloadrescue' ), '', array( 'response' => 403 ) );
		}
		$id = self::posted_id( 'order_id' );
		check_admin_referer( 'ccdr_request_' . $id );
		$result = self::request( $id );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 409 ) );
		}
		nocache_headers();
		wp_safe_redirect( wc_get_endpoint_url( 'view-order', $id, wc_get_page_permalink( 'myaccount' ) ) );
		exit;
	}

	/** Merchant POST adapter includes its own nonce, independent of Woo's order save. */
	public static function handle_decision() {
		if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'coocadev-downloadrescue' ), '', array( 'response' => 403 ) );
		}
		$id = self::posted_id( 'ccdr_order_id' );
		check_admin_referer( 'ccdr_decide_' . $id, 'ccdr_nonce' );
		if ( ! isset( $_POST['ccdr_token'], $_POST['ccdr_decision'] ) || ! is_string( $_POST['ccdr_token'] ) || ! is_string( $_POST['ccdr_decision'] ) || ! in_array( $_POST['ccdr_decision'], array( 'approve', 'reject' ), true ) ) {
			wp_die( esc_html__( 'Invalid decision.', 'coocadev-downloadrescue' ), '', array( 'response' => 400 ) );
		}
		$result = self::decide( $id, sanitize_text_field( wp_unslash( $_POST['ccdr_token'] ) ), 'approve' === $_POST['ccdr_decision'] );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 409 ) );
		}
		$order = wc_get_order( $id );
		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}

	/** Register the standard WordPress exporter.
	 *
	 * @param array $items Existing WordPress privacy callbacks.
	 */
	public static function exporters( $items ) {
		$items['ccdr'] = array(
			'exporter_friendly_name' => __( 'DownloadRescue requests', 'coocadev-downloadrescue' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $items;
	}

	/** Query a bounded page of matching WooCommerce orders through its public API.
	 *
	 * @param string $email Requested address or email object.
	 * @param int    $page One-based bounded privacy page.
	 */
	private static function privacy_orders( $email, $page ) {
		return wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => 50,
				'page'          => max( 1, (int) $page ),
				'orderby'       => 'ID',
				'order'         => 'ASC',
			)
		);
	}

	/** Include request state; never include download URLs or order keys.
	 *
	 * @param string $email Requested address or email object.
	 * @param int    $page One-based bounded privacy page.
	 */
	public static function export( $email, $page = 1 ) {
		$orders = self::privacy_orders( $email, $page );
		$data   = array();
		foreach ( $orders as $order ) {
			$state = $order->get_meta( '_ccdr_request', true );
			if ( is_array( $state ) ) {
				$data[] = array(
					'group_id'    => 'ccdr',
					'group_label' => __( 'Download renewal requests', 'coocadev-downloadrescue' ),
					'item_id'     => 'ccdr-' . $order->get_id(),
					'data'        => array(
						array(
							'name'  => __( 'Order ID', 'coocadev-downloadrescue' ),
							'value' => $order->get_id(),
						),
						array(
							'name'  => __( 'Status', 'coocadev-downloadrescue' ),
							'value' => $state['status'],
						),
						array(
							'name'  => __( 'Requested at (UTC)', 'coocadev-downloadrescue' ),
							'value' => gmdate( 'c', $state['at'] ),
						),
					),
				);
			}
		}
		return array(
			'data' => $data,
			'done' => count( $orders ) < 50,
		);
	}

	/** Register an eraser for request metadata, independent of download entitlement.
	 *
	 * @param array $items Existing WordPress privacy callbacks.
	 */
	public static function erasers( $items ) {
		$items['ccdr'] = array(
			'eraser_friendly_name' => __( 'DownloadRescue requests', 'coocadev-downloadrescue' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $items;
	}

	/** Erasure never revokes or grants a customer's native WooCommerce downloads.
	 *
	 * @param string $email Requested address or email object.
	 * @param int    $page One-based bounded privacy page.
	 */
	public static function erase( $email, $page = 1 ) {
		$orders   = self::privacy_orders( $email, $page );
		$removed  = false;
		$retained = false;
		foreach ( $orders as $order ) {
			$result   = self::locked(
				$order->get_id(),
				static function ( $fresh ) {
					$exists = $fresh && $fresh->meta_exists( '_ccdr_request' );
					if ( $exists ) {
						$fresh->delete_meta_data( '_ccdr_request' );
						$fresh->delete_meta_data( '_ccdr_status' );
						$fresh->save();
						$confirmed = wc_get_order( $fresh->get_id() );
						if ( $confirmed ) {
							$confirmed->read_meta_data( true );
						}
						if ( $confirmed && ( $confirmed->meta_exists( '_ccdr_request' ) || $confirmed->meta_exists( '_ccdr_status' ) ) ) {
							return new \WP_Error( 'ccdr_storage', __( 'The request could not be erased. Please retry.', 'coocadev-downloadrescue' ) );
						}
					}
					return $exists;
				}
			);
			$removed  = $removed || true === $result;
			$retained = $retained || is_wp_error( $result );
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $retained ? array( __( 'An order was busy. Retry erasure to remove its request.', 'coocadev-downloadrescue' ) ) : array(),
			'done'           => ! $retained && count( $orders ) < 50,
		);
	}

	/** Describe signed-in scope and merchant retention. */
	public static function privacy() {
		wp_add_privacy_policy_content( 'Coocadev DownloadRescue', __( 'DownloadRescue stores renewal request status and timestamps with the existing WooCommerce order. Only its signed-in purchaser can request a review; guests must contact the store. A merchant can approve a limited renewal and resend the existing customer order email. No file content, arbitrary URLs, IP addresses or analytics are collected. Requests can be exported or erased through WordPress privacy tools. Uninstall preserves requests and WooCommerce permissions; no data goes to Coocadev.', 'coocadev-downloadrescue' ) );
	}
}
