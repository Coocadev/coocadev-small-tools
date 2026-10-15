<?php
/**
 * Daily digest orchestration and its small merchant screen.
 *
 * @package CoocadevStockDigest
 */

namespace Coocadev\StockDigest;

defined( 'ABSPATH' ) || exit;

/** Independent runtime. No shared Coocadev dependency is required. */
final class Plugin {

	/** Product CRUD is independent of order storage and does not modify checkout. */
	public static function compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', dirname( __DIR__ ) . '/coocadev-stockdigest.php', true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', dirname( __DIR__ ) . '/coocadev-stockdigest.php', true );
		}
	}

	/** Register only after WooCommerce has loaded. */
	public static function boot() {
		if ( ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, '10.2', '<' ) ) {
			return;
		}
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_ccsd_save', array( self::class, 'save' ) );
		add_action( 'admin_post_ccsd_ordered', array( self::class, 'ordered' ) );
		add_action( 'ccsd_daily', array( self::class, 'run' ) );
		add_action( 'ccsd_continue', array( self::class, 'run' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
		add_action( 'admin_init', array( self::class, 'privacy' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
	}

	/**
	 * Install defaults without enabling mail or choosing a recipient.
	 *
	 * @param bool $network_wide Whether this is a network-wide activation.
	 */
	public static function activate( $network_wide = false ) {
		if ( $network_wide ) {
			wp_die( esc_html__( 'Activate StockDigest separately on each site.', 'coocadev-stockdigest' ) );
		}
		add_option(
			'ccsd_settings',
			array(
				'enabled'   => false,
				'recipient' => '',
			),
			'',
			false
		);
	}

	/** Retain merchant settings and product suppression dates. */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'ccsd_daily' );
		wp_clear_scheduled_hook( 'ccsd_continue' );
	}

	/**
	 * Read a fail-closed configuration.
	 *
	 * @param bool $fresh Whether the mutex holder requires authoritative database state.
	 * @return array Validated mail configuration.
	 */
	public static function settings( $fresh = false ) {
		$value = $fresh ? self::fresh_option( 'ccsd_settings', array() ) : get_option( 'ccsd_settings', array() );
		return array(
			'enabled'   => isset( $value['enabled'] ) && true === $value['enabled'],
			'recipient' => isset( $value['recipient'] ) && is_string( $value['recipient'] ) && is_email( $value['recipient'] ) ? $value['recipient'] : '',
		);
	}

	/**
	 * Read this plugin's stored value after acquiring its mutex, bypassing a stale
	 * per-request options cache left over from init or another cron callback.
	 *
	 * @param string $name Internal option name.
	 * @param mixed  $fallback Value for a missing row.
	 * @return mixed Actual stored value.
	 */
	private static function fresh_option( $name, $fallback ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Authoritative mutex-protected plugin state cannot rely on an earlier request-local option cache.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name ), ARRAY_A );
		return is_array( $row ) ? maybe_unserialize( $row['option_value'] ) : $fallback;
	}

	/**
	 * Verify durable state instead of assuming update_option succeeded. A false
	 * return can mean either unchanged data or failed persistence; readback decides.
	 *
	 * @param string $name Internal, non-autoloaded plugin option.
	 * @param mixed  $value Exact state to persist.
	 * @return bool Whether the database now contains this exact state.
	 */
	private static function persist_option( $name, $value ) {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		update_option( $name, $value, false );
		return self::fresh_option( $name, null ) === $value;
	}

	/** WP-Cron is traffic-dependent; real server cron can invoke due events. */
	public static function schedule() {
		$settings = self::settings();
		if ( $settings['enabled'] && $settings['recipient'] && ! wp_next_scheduled( 'ccsd_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ccsd_daily' );
		}
		if ( ! $settings['enabled'] ) {
			self::deactivate();
		}
	}

	/**
	 * Serialize workers for this site using a connection-owned database lock.
	 * No timed lease can expire while the digest worker is sending its email.
	 *
	 * @param callable $callback Work to execute while holding the lock.
	 * @return mixed|\WP_Error Result, or a fail-closed busy response.
	 */
	public static function locked( $callback ) {
		global $wpdb;
		$name = 'ccsd_' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix ), 0, 50 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Connection-scoped mutex; options cannot provide an expiring lock with safe mail semantics.
		$held = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 2 ) );
		if ( '1' !== (string) $held ) {
			return new \WP_Error( 'ccsd_busy', __( 'Another digest operation is running. Please try again.', 'coocadev-stockdigest' ) );
		}
		try {
			return $callback();
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Release exactly the connection-owned mutex acquired above.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	/**
	 * Process at most 100 products per invocation and retain at most 500 rows.
	 * A send claim is persisted BEFORE wp_mail: ambiguous failures never auto-resend.
	 * At-most-once invocation avoids duplicates, but cannot guarantee delivery.
	 *
	 * @return mixed|\WP_Error Run state or a busy error.
	 */
	public static function run() {
		return self::locked(
			static function () {
				$settings = self::settings( true );
				if ( ! $settings['enabled'] || ! $settings['recipient'] ) {
					return array( 'status' => 'disabled' );
				}
				$day   = current_time( 'Y-m-d' );
				$state = self::fresh_option( 'ccsd_run', array() );
				if ( empty( $state['day'] ) || $day !== $state['day'] ) {
					$state = array(
						'day'       => $day,
						'page'      => 1,
						'rows'      => array(),
						'truncated' => false,
						'status'    => 'scanning',
					);
				}
				if ( 'scanning' !== $state['status'] ) {
					return $state;
				}
				$query = new \WP_Query(
					array(
						'post_type'      => array( 'product', 'product_variation' ),
						'post_status'    => 'publish',
						'posts_per_page' => 100,
						'paged'          => (int) $state['page'],
						'orderby'        => 'ID',
						'order'          => 'ASC',
						'fields'         => 'ids',
						'no_found_rows'  => true,
					)
				);
				foreach ( $query->posts as $id ) {
					$product = wc_get_product( $id );
					if ( ! self::needs_stock( $product ) ) {
						continue;
					}
					if ( count( $state['rows'] ) < 500 ) {
						$state['rows'][ $id ] = array(
							'id'    => $id,
							'name'  => wp_strip_all_tags( $product->get_name() ),
							'stock' => $product->get_stock_quantity(),
						);
					} else {
						$state['truncated'] = true;
					}
				}
				++$state['page'];
				$more = 100 === count( $query->posts ) && $state['page'] <= 100;
				if ( $more ) {
					if ( ! self::persist_option( 'ccsd_run', $state ) ) {
						return new \WP_Error( 'ccsd_storage', __( 'Digest progress could not be saved. No email was sent.', 'coocadev-stockdigest' ) );
					}
					if ( ! wp_next_scheduled( 'ccsd_continue' ) ) {
						wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'ccsd_continue' );
					}
					return $state;
				}
				$state['truncated'] = $state['truncated'] || 100 === count( $query->posts );
				// Recheck quantities and suppression immediately before composing the digest.
				$rows = array();
				foreach ( array_keys( $state['rows'] ) as $id ) {
					$product = wc_get_product( $id );
					if ( self::needs_stock( $product ) ) {
						$rows[] = sprintf( '#%d %s: %s', $id, wp_strip_all_tags( $product->get_name() ), $product->get_stock_quantity() );
					}
				}
				$state['rows']   = array();
				$state['status'] = $rows ? 'claimed' : 'empty';
				if ( ! self::persist_option( 'ccsd_run', $state ) ) {
					return new \WP_Error( 'ccsd_storage', __( 'The email claim could not be saved. No email was sent.', 'coocadev-stockdigest' ) );
				}
				if ( ! $rows ) {
					return $state;
				}
				$body = __( 'Products at or below their WooCommerce low-stock threshold:', 'coocadev-stockdigest' ) . "\n\n" . implode( "\n", $rows );
				if ( $state['truncated'] ) {
					$body .= "\n\n" . __( 'This report reached its safety limit. Review the complete stock list in WooCommerce.', 'coocadev-stockdigest' );
				}
				$state['status'] = wp_mail( $settings['recipient'], __( 'Daily low-stock digest', 'coocadev-stockdigest' ), $body ) ? 'sent' : 'mail_failed';
				if ( ! self::persist_option( 'ccsd_run', $state ) ) {
					return new \WP_Error( 'ccsd_storage', __( 'The email result could not be saved. The durable claim prevents automatic resending.', 'coocadev-stockdigest' ) );
				}
				return $state;
			}
		);
	}

	/**
	 * Products using a parent's stock are represented by the parent, not repeated.
	 *
	 * @param \WC_Product|false $product Product to inspect.
	 * @return bool Whether the merchant should replenish it.
	 */
	public static function needs_stock( $product ) {
		return $product && 'publish' === $product->get_status() && true === $product->get_manage_stock() && null !== $product->get_stock_quantity() && $product->get_stock_quantity() <= wc_get_low_stock_amount( $product ) && (int) $product->get_meta( '_ccsd_ordered_until', true ) <= time();
	}

	/** Register a WooCommerce submenu, rather than a global notice. */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'StockDigest', 'coocadev-stockdigest' ), __( 'StockDigest', 'coocadev-stockdigest' ), 'manage_woocommerce', 'ccsd', array( self::class, 'screen' ) );
	}

	/** Render settings and an explicitly limited product list. */
	public static function screen() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$settings = self::settings();
		echo '<div class="wrap"><h1>' . esc_html__( 'StockDigest', 'coocadev-stockdigest' ) . '</h1><p>' . esc_html__( 'One daily email. Existing WooCommerce immediate stock emails remain unchanged. WP-Cron depends on site visits.', 'coocadev-stockdigest' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ccsd_save' );
		echo '<input type="hidden" name="action" value="ccsd_save"><p><label><input type="checkbox" name="enabled" value="1" ' . checked( $settings['enabled'], true, false ) . '> ' . esc_html__( 'Enable the daily digest', 'coocadev-stockdigest' ) . '</label></p><p><label for="ccsd-recipient">' . esc_html__( 'Digest recipient', 'coocadev-stockdigest' ) . '</label> <input id="ccsd-recipient" type="email" name="recipient" maxlength="254" value="' . esc_attr( $settings['recipient'] ) . '"></p>';
		submit_button();
		echo '</form><h2>' . esc_html__( 'Replenishment reminders', 'coocadev-stockdigest' ) . '</h2><p>' . esc_html__( 'Inventory is shown in pages of 100, including variations. Mark ordered to exclude a product until the selected date.', 'coocadev-stockdigest' ) . '</p><ul>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only inventory pagination changes no data.
		$page  = isset( $_GET['ccsd_page'] ) && is_string( $_GET['ccsd_page'] ) ? max( 1, absint( wp_unslash( $_GET['ccsd_page'] ) ) ) : 1;
		$query = new \WP_Query(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'paged'          => $page,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $query->posts as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product || ! $product->managing_stock() ) {
				continue;
			}
			echo '<li><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ccsd_ordered"><input type="hidden" name="product_id" value="' . esc_attr( $product->get_id() ) . '">';
			wp_nonce_field( 'ccsd_ordered_' . $product->get_id() );
			echo '<strong>' . esc_html( $product->get_name() ) . '</strong> <label>' . esc_html__( 'Ordered until (UTC date; empty clears)', 'coocadev-stockdigest' ) . ' <input type="date" name="until" value="' . esc_attr( $product->get_meta( '_ccsd_ordered_until', true ) ? gmdate( 'Y-m-d', (int) $product->get_meta( '_ccsd_ordered_until', true ) - 1 ) : '' ) . '"></label> <button class="button">' . esc_html__( 'Save reminder', 'coocadev-stockdigest' ) . '</button></form></li>';
		}
		echo '</ul><p>';
		if ( $page > 1 ) {
			echo '<a class="button" href="' . esc_url(
				add_query_arg(
					array(
						'page'      => 'ccsd',
						'ccsd_page' => $page - 1,
					),
					admin_url( 'admin.php' )
				)
			) . '">' . esc_html__( 'Previous', 'coocadev-stockdigest' ) . '</a> ';
		}
		if ( 100 === count( $query->posts ) ) {
			echo '<a class="button" href="' . esc_url(
				add_query_arg(
					array(
						'page'      => 'ccsd',
						'ccsd_page' => $page + 1,
					),
					admin_url( 'admin.php' )
				)
			) . '">' . esc_html__( 'Next', 'coocadev-stockdigest' ) . '</a>';
		}
		echo '</p></div>';
	}

	/** Check permission and method before reading submitted fields. */
	private static function authorize() {
		if ( ! current_user_can( 'manage_woocommerce' ) || 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_die( esc_html__( 'Not allowed.', 'coocadev-stockdigest' ), '', array( 'response' => 403 ) );
		}
	}

	/** Validate a complete settings value; malformed checkbox data never disables it. */
	public static function save() {
		self::authorize();
		check_admin_referer( 'ccsd_save' );
		if ( ( isset( $_POST['enabled'] ) && '1' !== $_POST['enabled'] ) || ! isset( $_POST['recipient'] ) || ! is_string( $_POST['recipient'] ) ) {
			wp_die( esc_html__( 'Invalid settings.', 'coocadev-stockdigest' ), '', array( 'response' => 400 ) );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict email validation below rejects changed/malformed addresses; sanitizing could silently choose another recipient.
		$email = wp_unslash( $_POST['recipient'] );
		if ( strlen( $email ) > 254 || ( '' !== $email && ! is_email( $email ) ) || ( isset( $_POST['enabled'] ) && '' === $email ) ) {
			wp_die( esc_html__( 'Enter one valid recipient email.', 'coocadev-stockdigest' ), '', array( 'response' => 400 ) );
		}
		$enabled = isset( $_POST['enabled'] );
		$result  = self::locked(
			static function () use ( $email, $enabled ) {
				return self::persist_option(
					'ccsd_settings',
					array(
						'enabled'   => $enabled,
						'recipient' => $email,
					),
				);
			}
		);
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 409 ) );
		}
		if ( ! $result ) {
			wp_die( esc_html__( 'The settings could not be saved. Please try again.', 'coocadev-stockdigest' ), '', array( 'response' => 500 ) );
		}
		self::schedule();
		wp_safe_redirect( admin_url( 'admin.php?page=ccsd' ) );
		exit;
	}

	/** Save a merchant's explicit reminder for an editable product. */
	public static function ordered() {
		self::authorize();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decimal-only validation then absint; derive the nonce scope and verify it on the following line before any operation.
		$id = isset( $_POST['product_id'] ) && is_string( $_POST['product_id'] ) && ctype_digit( wp_unslash( $_POST['product_id'] ) ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		check_admin_referer( 'ccsd_ordered_' . $id );
		if ( ! $id || ! current_user_can( 'edit_post', $id ) || ! isset( $_POST['until'] ) || ! is_string( $_POST['until'] ) || ! wc_get_product( $id ) ) {
			wp_die( esc_html__( 'Invalid product or date.', 'coocadev-stockdigest' ), '', array( 'response' => 400 ) );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact date format and range are validated below; malformed data must not be converted into another date.
		$date = wp_unslash( $_POST['until'] );
		$dt   = preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date ) ? \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new \DateTimeZone( 'UTC' ) ) : false;
		if ( '' !== $date && ( ! $dt || $dt->format( 'Y-m-d' ) !== $date || $dt->getTimestamp() > time() + 366 * DAY_IN_SECONDS || $dt->getTimestamp() < strtotime( 'today UTC' ) ) ) {
			wp_die( esc_html__( 'Choose today or a date within one year.', 'coocadev-stockdigest' ), '', array( 'response' => 400 ) );
		}
		$result = self::locked(
			static function () use ( $id, $dt, $date ) {
				$product = wc_get_product( $id );
				if ( ! $product || ! current_user_can( 'edit_post', $id ) ) {
					return new \WP_Error( 'ccsd_product', __( 'The product is no longer available to edit.', 'coocadev-stockdigest' ) );
				}
				$product->update_meta_data( '_ccsd_ordered_until', '' === $date ? 0 : $dt->getTimestamp() + DAY_IN_SECONDS );
				$product->save();
				$confirmed = wc_get_product( $id );
				if ( ! $confirmed ) {
					return new \WP_Error( 'ccsd_product', __( 'The product was deleted before its reminder could be confirmed.', 'coocadev-stockdigest' ) );
				}
				$confirmed->read_meta_data( true );
				if ( (int) $confirmed->get_meta( '_ccsd_ordered_until', true ) !== ( '' === $date ? 0 : $dt->getTimestamp() + DAY_IN_SECONDS ) ) {
					return new \WP_Error( 'ccsd_storage', __( 'The reminder could not be saved. Please try again.', 'coocadev-stockdigest' ) );
				}
				return true;
			}
		);
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 409 ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=ccsd' ) );
		exit;
	}

	/** Explain that the merchant opts into email delivery through the site's mailer. */
	public static function privacy() {
		wp_add_privacy_policy_content( 'Coocadev StockDigest', __( 'StockDigest stores a merchant-selected recipient address, product replenishment dates and a daily run status. When enabled it sends stock summaries through the site mail service. It sends no analytics to Coocadev. Deactivation stops scheduled work; uninstall removes plugin settings but preserves product reminder dates.', 'coocadev-stockdigest' ) );
	}

	/**
	 * Register the merchant-recipient exporter.
	 *
	 * @param array $items Registered WordPress exporters.
	 * @return array Updated callbacks.
	 */
	public static function exporters( $items ) {
		$items['ccsd'] = array(
			'exporter_friendly_name' => __( 'StockDigest recipient', 'coocadev-stockdigest' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $items;
	}

	/**
	 * Return only the matching address; stock rows contain no customer information.
	 *
	 * @param string $email Confirmed address from the Core privacy workflow.
	 * @return array Core exporter result.
	 */
	public static function export( $email ) {
		$settings = self::settings();
		$data     = array();
		if ( $settings['recipient'] && 0 === strcasecmp( $email, $settings['recipient'] ) ) {
			$data[] = array(
				'group_id'    => 'ccsd',
				'group_label' => __( 'StockDigest recipient', 'coocadev-stockdigest' ),
				'item_id'     => 'ccsd-recipient',
				'data'        => array(
					array(
						'name'  => __( 'Email address', 'coocadev-stockdigest' ),
						'value' => $settings['recipient'],
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Register an eraser which disables the digest when its recipient requests erasure.
	 *
	 * @param array $items Registered WordPress erasers.
	 * @return array Updated callbacks.
	 */
	public static function erasers( $items ) {
		$items['ccsd'] = array(
			'eraser_friendly_name' => __( 'StockDigest recipient', 'coocadev-stockdigest' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $items;
	}

	/**
	 * Serialize erasure with sending; an already-sent email cannot be recalled.
	 *
	 * @param string $email Confirmed address from Core privacy.
	 * @return array Core eraser result.
	 */
	public static function erase( $email ) {
		$result = self::locked(
			static function () use ( $email ) {
				$settings = self::settings( true );
				if ( ! $settings['recipient'] || 0 !== strcasecmp( $email, $settings['recipient'] ) ) {
					return false;
				}
				if ( ! self::persist_option(
					'ccsd_settings',
					array(
						'enabled'   => false,
						'recipient' => '',
					)
				) ) {
					return new \WP_Error( 'ccsd_storage', __( 'The recipient could not be erased. Please try again.', 'coocadev-stockdigest' ) );
				}
				self::deactivate();
				return true;
			}
		);
		return array(
			'items_removed'  => true === $result,
			'items_retained' => is_wp_error( $result ),
			'messages'       => is_wp_error( $result ) ? array( $result->get_error_message() ) : array(),
			'done'           => ! is_wp_error( $result ),
		);
	}
}
