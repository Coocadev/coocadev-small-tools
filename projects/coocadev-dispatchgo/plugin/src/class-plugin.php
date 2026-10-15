<?php
/**
 * WooCommerce adapters and administration.
 *
 * Coordinates Woo settings, classic checkout and Store API adapters.
 * Both checkout paths validate current terms and freeze their receipt
 * before Woo reaches its usual gateway boundary. Order and email views
 * read the saved receipt so a resend cannot invent a different promise.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevDispatchGo
 */

namespace Coocadev\DispatchGo;

defined( 'ABSPATH' ) || exit;

/** Adapts the shared domain to classic checkout, Blocks and Woo administration. */
final class Plugin {
	/**
	 * Main plugin file for asset locations.
	 *
	 * @var string
	 */
	private static string $file;
	/**
	 * Server terms validated during this classic checkout request.
	 *
	 * @var array|null
	 */
	private static ?array $validated = null;

	/**
	 * Register integrations after WooCommerce dependencies are available.
	 *
	 * @param string $file Main plugin file.
	 */
	public static function boot( string $file ): void {
		self::$file = $file;
		add_filter( 'woocommerce_get_sections_shipping', array( self::class, 'sections' ) );
		add_filter( 'woocommerce_get_settings_shipping', array( self::class, 'settings_fields' ), 10, 2 );
		add_action( 'woocommerce_admin_field_ccdg_settings', array( self::class, 'settings_screen' ) );
		add_action( 'woocommerce_update_options_shipping', array( self::class, 'save_settings' ), 20 );
		add_action( 'woocommerce_review_order_before_submit', array( self::class, 'classic_preview' ) );
		add_action( 'woocommerce_before_cart', array( self::class, 'cart_preview' ) );
		add_action( 'woocommerce_after_checkout_validation', array( self::class, 'classic_validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_created', array( self::class, 'classic_freeze' ), 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'blocks_freeze' ), 1, 2 );
		add_action( 'woocommerce_blocks_loaded', array( self::class, 'store_api' ) );
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::store_api();
		}
		add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ), 30 );
		add_action( 'woocommerce_order_details_after_order_table', array( self::class, 'order_notice' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( self::class, 'admin_order_notice' ) );
		add_action( 'woocommerce_email_after_order_table', array( self::class, 'email_notice' ), 10, 4 );
		add_filter( 'woocommerce_privacy_export_order_personal_data', array( self::class, 'privacy_export' ), 10, 2 );
		add_action( 'woocommerce_delete_order', array( Repository::class, 'deleted_order' ) );
		add_action( 'admin_init', array( self::class, 'privacy_policy' ) );
	}

	/**
	 * Add a subsection without adding a top-level administration menu.
	 *
	 * @param array $sections Shipping sections.
	 * @return array Shipping sections.
	 */
	public static function sections( array $sections ): array {
		$sections['ccdg'] = __( 'DispatchGo', 'coocadev-dispatchgo' );
		return $sections;
	}

	/**
	 * Supply one custom settings renderer.
	 *
	 * @param array  $fields Woo settings fields.
	 * @param string $section Current section.
	 * @return array Woo settings fields.
	 */
	public static function settings_fields( array $fields, string $section ): array {
		return 'ccdg' === $section ? array(
			array(
				'type' => 'ccdg_settings',
				'id'   => 'ccdg_settings_screen',
			),
		) : $fields;
	}

	/** Render the settings inside WooCommerce's existing POST form. */
	public static function settings_screen(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s = Repository::settings();
		echo '<h2>' . esc_html__( 'DispatchGo — dispatch pause', 'coocadev-dispatchgo' ) . '</h2><p>' . esc_html__( 'Customers see when dispatch resumes, not a guaranteed delivery date. Each checkout keeps its original notice.', 'coocadev-dispatchgo' ) . '</p>';
		if ( wp_timezone_string() !== $s['saved_timezone'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The site timezone changed. Your saved campaign still uses its original timezone. Saving reinterprets the dates in the current site timezone.', 'coocadev-dispatchgo' ) . ' ' . esc_html( $s['saved_timezone'] ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'Current store timezone:', 'coocadev-dispatchgo' ) . ' <strong>' . esc_html( wp_timezone_string() ) . '</strong></p>';
		wp_nonce_field( 'ccdg_save_settings', 'ccdg_nonce' );
		echo '<input type="hidden" name="ccdg_revision" value="' . esc_attr( (string) $s['revision'] ) . '"><table class="form-table"><tbody>';
		self::checkbox( 'enabled', __( 'Enable dispatch notice', 'coocadev-dispatchgo' ), $s['enabled'] );
		echo '<tr><th><label for="ccdg_starts_local">' . esc_html__( 'Notice starts', 'coocadev-dispatchgo' ) . '</label></th><td><input id="ccdg_starts_local" name="ccdg_starts_local" type="datetime-local" value="' . esc_attr( $s['starts_local'] ) . '"><p class="description">' . esc_html__( 'Times skipped or repeated by daylight saving are rejected. Choose an unambiguous time.', 'coocadev-dispatchgo' ) . '</p></td></tr>';
		echo '<tr><th><label for="ccdg_resumes_local_date">' . esc_html__( 'Dispatch resumes', 'coocadev-dispatchgo' ) . '</label></th><td><input id="ccdg_resumes_local_date" name="ccdg_resumes_local_date" type="date" value="' . esc_attr( $s['resumes_local_date'] ) . '"><p class="description">' . esc_html__( 'The notice ends at the start of this day in the saved store timezone.', 'coocadev-dispatchgo' ) . '</p></td></tr>';
		echo '<tr><th><label for="ccdg_customer_message">' . esc_html__( 'Customer message', 'coocadev-dispatchgo' ) . '</label></th><td><textarea id="ccdg_customer_message" name="ccdg_customer_message" rows="3" cols="60" maxlength="300">' . esc_textarea( $s['customer_message'] ) . '</textarea><p class="description">' . esc_html__( 'Optional plain text, up to 300 characters. Do not enter customer or payment information.', 'coocadev-dispatchgo' ) . '</p></td></tr>';
		self::checkbox( 'show_cart', __( 'Also show in cart', 'coocadev-dispatchgo' ), $s['show_cart'] );
		self::checkbox( 'uninstall_remove_settings', __( 'Remove settings when deleting the plugin', 'coocadev-dispatchgo' ), $s['uninstall_remove_settings'] );
		echo '</tbody></table><p>' . esc_html__( 'Historical order receipts are retained after deletion. Reinstalling restores their display. No external services or tracking are used.', 'coocadev-dispatchgo' ) . '</p>';
		if ( $s['resumes_local_date'] ) {
			echo '<p><strong>' . esc_html__( 'Saved preview:', 'coocadev-dispatchgo' ) . '</strong> ' . esc_html(
				self::text(
					array(
						'applicable'         => true,
						'resumes_local_date' => $s['resumes_local_date'],
						'timezone'           => $s['saved_timezone'],
						'message_plain'      => $s['customer_message'],
					)
				)
			) . '</p>';
		}
	}

	/**
	 * Render a labelled boolean setting.
	 *
	 * @param string $key Allowlisted field key.
	 * @param string $label Translated label.
	 * @param bool   $checked Current value.
	 */
	private static function checkbox( string $key, string $label, bool $checked ): void {
		echo '<tr><th><label for="ccdg_' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input id="ccdg_' . esc_attr( $key ) . '" name="ccdg_' . esc_attr( $key ) . '" type="checkbox" value="1" ' . checked( $checked, true, false ) . '></td></tr>';
	}

	/** Validate authorization and atomically save the complete settings revision. */
	public static function save_settings(): void {
		global $current_section;
		if ( 'ccdg' !== $current_section ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You cannot change these settings.', 'coocadev-dispatchgo' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ccdg_save_settings', 'ccdg_nonce' );
		$revision = isset( $_POST['ccdg_revision'] ) && is_string( $_POST['ccdg_revision'] ) ? sanitize_text_field( wp_unslash( $_POST['ccdg_revision'] ) ) : '';
		if ( ! preg_match( '/^[0-9]{1,9}$/D', $revision ) ) {
			\WC_Admin_Settings::add_error( __( 'Invalid settings revision. Reload this page.', 'coocadev-dispatchgo' ) );
			return;
		}
		$input = array();
		foreach ( array( 'starts_local', 'resumes_local_date', 'customer_message' ) as $key ) {
			$input[ $key ] = isset( $_POST[ 'ccdg_' . $key ] ) && is_string( $_POST[ 'ccdg_' . $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ 'ccdg_' . $key ] ) ) : null;
		}
		foreach ( array( 'enabled', 'show_cart', 'uninstall_remove_settings' ) as $key ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact literal comparison rejects malformed checkbox tokens rather than repairing them into an accepted value.
			if ( array_key_exists( 'ccdg_' . $key, $_POST ) && ( ! is_string( $_POST[ 'ccdg_' . $key ] ) || '1' !== sanitize_text_field( wp_unslash( $_POST[ 'ccdg_' . $key ] ) ) || '1' !== wp_unslash( $_POST[ 'ccdg_' . $key ] ) ) ) {
				wp_die( esc_html__( 'Invalid checkbox value. Reload the form and try again.', 'coocadev-dispatchgo' ), '', array( 'response' => 400 ) );
			}
			$input[ $key ] = isset( $_POST[ 'ccdg_' . $key ] );
		}
		try {
			$next = Schedule::normalize( $input, wp_timezone_string(), time() );
			if ( ! Repository::save_settings( $next, (int) $revision ) ) {
				\WC_Admin_Settings::add_error( __( 'Settings changed in another tab, or saving failed. Reload and review before saving again.', 'coocadev-dispatchgo' ) );
			}
		} catch ( \InvalidArgumentException $e ) {
			\WC_Admin_Settings::add_error( __( 'Settings were not saved. Use valid, unambiguous dates; the resume date must follow the start and be in the future when enabled. Keep the message within 300 characters.', 'coocadev-dispatchgo' ) );
		}
	}

	/**
	 * A pending order in Woo's own session keeps its original receipt on retry.
	 *
	 * @return array|null Current pending receipt, if it belongs to this cart.
	 */
	private static function pending_receipt(): ?array {
		if ( ! WC()->session ) {
			return null;
		}
		foreach ( array( 'order_awaiting_payment', 'store_api_draft_order' ) as $key ) {
			$id = absint( WC()->session->get( $key ) );
			if ( $id ) {
				$order = wc_get_order( $id );
				if ( $order && $order->has_status( array( 'pending', 'failed', 'checkout-draft' ) ) && WC()->cart && $order->get_cart_hash() === WC()->cart->get_cart_hash() ) {
					$receipt = Repository::receipt( $id );
					if ( $receipt ) {
						return $receipt;
					}
				}
			}
		}
		return null;
	}

	/**
	 * Calculate current terms using only server-controlled state.
	 *
	 * @return array Dispatch terms.
	 */
	public static function current_terms(): array {
		$receipt = self::pending_receipt();
		if ( $receipt ) {
			return $receipt['terms'];
		}
		$cart    = WC()->cart;
		$methods = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods', array() ) : array();
		return Schedule::terms( Repository::settings(), $cart && $cart->needs_shipping(), $methods, time() );
	}

	/**
	 * Format terms as plain text; callers escape for their final output context.
	 *
	 * @param array $terms Dispatch terms.
	 * @return string Plain notice text.
	 */
	public static function text( array $terms ): string {
		if ( empty( $terms['applicable'] ) ) {
			return '';
		}
		try {
			$date  = new \DateTimeImmutable( $terms['resumes_local_date'] . 'T12:00:00', new \DateTimeZone( $terms['timezone'] ) );
			$label = wp_date( get_option( 'date_format' ), $date->getTimestamp(), $date->getTimezone() );
		} catch ( \Exception $e ) {
			return __( 'The original dispatch notice is unavailable. Contact the store.', 'coocadev-dispatchgo' );
		}
		/* translators: %s: the store's local dispatch-resumption date. */
		$message  = sprintf( __( 'Dispatch resumes on %s.', 'coocadev-dispatchgo' ), $label );
		$message .= ' ' . __( 'This is when the store resumes dispatch, not a delivery or stock-availability guarantee.', 'coocadev-dispatchgo' );
		if ( ! empty( $terms['message_plain'] ) ) {
			$message .= ' ' . $terms['message_plain'];
		}
		return $message;
	}

	/**
	 * Public cart preview and its signed integrity receipt.
	 *
	 * @return array Store API extension data.
	 */
	public static function preview(): array {
		$terms = self::current_terms();
		return array(
			'text'      => self::text( $terms ),
			'token'     => Schedule::token( $terms, wp_salt( 'auth' ), time() ),
			'show_cart' => (bool) Repository::settings()['show_cart'],
		);
	}

	/** Render the notice and matching receipt inside Woo's refreshed payment section. */
	public static function classic_preview(): void {
		$preview = self::preview();
		echo '<div class="ccdg-checkout-notice" aria-live="polite">';
		if ( $preview['text'] ) {
			echo '<p class="woocommerce-info">' . esc_html( $preview['text'] ) . '</p>';
		}
		echo '<input type="hidden" name="ccdg_preview_token" value="' . esc_attr( $preview['token'] ) . '"></div>';
	}

	/** Render the optional classic cart notice. */
	public static function cart_preview(): void {
		$preview = self::preview();
		if ( $preview['text'] && $preview['show_cart'] ) {
			echo '<div class="woocommerce-info">' . esc_html( $preview['text'] ) . '</div>';
		}
	}

	/**
	 * Verify the preview before saving a checkout receipt.
	 *
	 * @param string         $token Untrusted preview token.
	 * @param \WC_Order|null $order Existing Store API order.
	 * @return array Verified server terms.
	 * @throws \RuntimeException When the customer must review updated information.
	 */
	private static function validated_terms( string $token, ?\WC_Order $order = null ): array {
		$receipt = $order ? Repository::receipt( $order->get_id() ) : null;
		$terms   = $receipt ? $receipt['terms'] : self::current_terms();
		if ( ( $terms['applicable'] || '' !== $token ) && ! Schedule::verify( $token, $terms, wp_salt( 'auth' ), time() ) ) {
			throw new \RuntimeException( esc_html__( 'Dispatch information changed or expired. Review the updated notice and place your order again.', 'coocadev-dispatchgo' ) );
		}
		return $terms;
	}

	/**
	 * Add a classic checkout validation error before order creation or payment.
	 *
	 * @param array     $data Normalized Woo checkout fields; not used for dispatch dates.
	 * @param \WP_Error $errors Woo validation errors.
	 */
	public static function classic_validate( array $data, \WP_Error $errors ): void {
		self::$validated = null;
		// WooCommerce verifies this before the hook; verify again at our own boundary.
		$nonce = wc_get_post_data_by_key( 'woocommerce-process-checkout-nonce', wc_get_post_data_by_key( '_wpnonce', '' ) );
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'woocommerce-process_checkout' ) ) {
			$errors->add( 'ccdg_invalid_nonce', __( 'Your checkout session expired. Reload checkout and try again.', 'coocadev-dispatchgo' ) );
			return;
		}
		$token = isset( $_POST['ccdg_preview_token'] ) && is_string( $_POST['ccdg_preview_token'] ) ? sanitize_text_field( wp_unslash( $_POST['ccdg_preview_token'] ) ) : '';
		try {
			self::$validated = self::validated_terms( $token );
		} catch ( \RuntimeException $e ) {
			$errors->add( 'ccdg_notice_changed', $e->getMessage() );
		}
	}

	/**
	 * Build an immutable receipt without copying customer or payment fields.
	 *
	 * @param array  $terms Validated server terms.
	 * @param string $source Checkout adapter identifier.
	 * @return array Candidate receipt.
	 */
	private static function candidate( array $terms, string $source ): array {
		return array(
			'version'            => 1,
			'terms'              => $terms,
			'campaign_revision'  => (int) Repository::settings()['revision'],
			'captured_at_utc'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'source'             => $source,
			'locale_at_checkout' => determine_locale(),
		);
	}

	/**
	 * Persist classic checkout's validated receipt before the payment handler.
	 *
	 * @param \WC_Order $order Newly saved or resumed order.
	 * @throws \Exception When an applicable notice cannot be safely recorded.
	 */
	public static function classic_freeze( \WC_Order $order ): void {
		if ( null === self::$validated ) {
			return;
		}
		try {
			Repository::freeze( $order, self::candidate( self::$validated, 'classic' ) );
		} catch ( \RuntimeException $e ) {
			if ( empty( self::$validated['applicable'] ) ) {
				// An unavailable optional audit must not block a checkout with no notice.
				return;
			}
			throw new \Exception( esc_html__( 'Your dispatch notice could not be recorded. No payment has been requested by DispatchGo. Please review checkout and try again.', 'coocadev-dispatchgo' ) );
		}
	}

	/**
	 * Validate and persist only actual Store API checkout POSTs, never previews.
	 *
	 * @param \WC_Order        $order Persisted checkout order.
	 * @param \WP_REST_Request $request Authorized Woo Store API request.
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When checkout must stop before payment.
	 */
	public static function blocks_freeze( \WC_Order $order, \WP_REST_Request $request ): void {
		if ( 'POST' !== $request->get_method() ) {
			return;
		}
		$extensions = $request->get_param( 'extensions' );
		$token      = is_array( $extensions ) && isset( $extensions['ccdg']['token'] ) && is_string( $extensions['ccdg']['token'] ) ? $extensions['ccdg']['token'] : '';
		$terms      = null;
		try {
			$terms = self::validated_terms( $token, $order );
			Repository::freeze( $order, self::candidate( $terms, 'blocks' ) );
		} catch ( \RuntimeException $e ) {
			if ( is_array( $terms ) && empty( $terms['applicable'] ) ) {
				return;
			}
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'ccdg_notice_changed', esc_html__( 'Dispatch information needs review. Refresh the dispatch notice, then place your order again. Payment has not started.', 'coocadev-dispatchgo' ), 409 );
		}
	}

	/** Register namespaced extension schemas and a safe cart refresh callback. */
	public static function store_api(): void {
		static $registered = false;
		if ( $registered || ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}
		$registered = true;
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
				'namespace'       => 'ccdg',
				'data_callback'   => array( self::class, 'preview' ),
				'schema_callback' => static function () {
					return array(
						'text'      => array(
							'description' => 'Dispatch notice text.',
							'type'        => 'string',
							'readonly'    => true,
						),
						'token'     => array(
							'description' => 'Signed preview receipt.',
							'type'        => 'string',
							'readonly'    => true,
						),
						'show_cart' => array(
							'description' => 'Show notice in cart.',
							'type'        => 'boolean',
							'readonly'    => true,
						),
					);
				},
				'schema_type'     => ARRAY_A,
			)
		);
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
				'namespace'       => 'ccdg',
				'schema_callback' => static function () {
					return array(
						'token' => array(
							'description' => 'Signed dispatch preview receipt.',
							'type'        => 'string',
							'maxLength'   => 90,
							'context'     => array( 'view', 'edit' ),
							'readonly'    => false,
						),
					);
				},
				'schema_type'     => ARRAY_A,
			)
		);
		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => 'ccdg',
				'callback'  => static function () {},
			)
		);
	}

	/** Enqueue only checkout/cart assets with their existing WordPress/Woo dependencies. */
	public static function assets(): void {
		if ( ! is_checkout() && ! is_cart() ) {
			return;
		}
		wp_enqueue_style( 'ccdg-checkout', plugins_url( 'assets/checkout.css', self::$file ), array(), '0.2.0' );
		$post = get_post();
		if ( $post && ( has_block( 'woocommerce/checkout', $post ) || has_block( 'woocommerce/cart', $post ) ) ) {
			wp_enqueue_script( 'ccdg-blocks', plugins_url( 'assets/blocks.js', self::$file ), array( 'wp-element', 'wp-data', 'wp-plugins', 'wp-i18n', 'wc-blocks-checkout', 'wc-blocks-data-store' ), '0.2.0', true );
			wp_set_script_translations( 'ccdg-blocks', 'coocadev-dispatchgo' );
		} elseif ( is_checkout() ) {
			wp_enqueue_script( 'ccdg-classic', plugins_url( 'assets/classic.js', self::$file ), array( 'jquery', 'wc-checkout' ), '0.2.0', true );
		}
	}

	/**
	 * Read recorded terms through Woo's active data store.
	 *
	 * @param \WC_Order $order Authorized Woo order context.
	 * @return array|null Recorded terms, or absent/unsupported schema.
	 */
	private static function saved_terms( \WC_Order $order ): ?array {
		$snapshot = $order->get_meta( '_ccdg_snapshot', true );
		return is_array( $snapshot ) && 1 === ( $snapshot['version'] ?? 0 ) && is_array( $snapshot['terms'] ?? null ) && Schedule::valid_terms( $snapshot['terms'] ) ? $snapshot['terms'] : null;
	}

	/**
	 * Render a recorded notice in the authorized Woo order view.
	 *
	 * @param \WC_Order $order Authorized Woo order context.
	 */
	public static function order_notice( \WC_Order $order ): void {
		$terms = self::saved_terms( $order );
		if ( $terms && $terms['applicable'] ) {
			echo '<section class="ccdg-order-notice"><h2>' . esc_html__( 'Dispatch notice at checkout', 'coocadev-dispatchgo' ) . '</h2><p>' . esc_html( self::text( $terms ) ) . '</p><p>' . esc_html( $terms['timezone'] ) . '</p></section>';
		}
	}

	/**
	 * Show read-only receipt status to an authorized order editor.
	 *
	 * @param \WC_Order $order Woo order being edited.
	 */
	public static function admin_order_notice( \WC_Order $order ): void {
		if ( ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			return;
		}
		$terms = self::saved_terms( $order );
		echo '<p><strong>' . esc_html__( 'Dispatch notice at checkout', 'coocadev-dispatchgo' ) . '</strong><br>';
		if ( null === $terms && '' !== $order->get_meta( '_ccdg_snapshot', true ) ) {
			echo esc_html__( 'The recorded notice is damaged or uses an unsupported format. No dispatch date can be shown.', 'coocadev-dispatchgo' );
		} else {
			echo esc_html( null === $terms ? __( 'No checkout notice recorded.', 'coocadev-dispatchgo' ) : ( $terms['applicable'] ? self::text( $terms ) : __( 'No dispatch pause applied at checkout.', 'coocadev-dispatchgo' ) ) );
		}
		echo '</p>';
	}

	/**
	 * Render inside an existing Woo email; never create recipients or send mail.
	 *
	 * @param \WC_Order $order The email's authorized order context.
	 * @param bool      $sent_to_admin Whether this is an administrator's copy.
	 * @param bool      $plain_text Whether Woo requests text/plain output.
	 */
	public static function email_notice( \WC_Order $order, bool $sent_to_admin, bool $plain_text ): void {
		$terms = self::saved_terms( $order );
		if ( ! $terms || ! $terms['applicable'] ) {
			return;
		}
		if ( $plain_text ) {
			// Plain email is text, not HTML; strip tags instead of introducing HTML entities.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- This branch is WooCommerce's plain-text email output, and strips all tags; HTML escaping would corrupt the plain-text message.
			echo "\n" . wp_strip_all_tags( __( 'Dispatch notice at checkout', 'coocadev-dispatchgo' ) . "\n" . self::text( $terms ) . "\n" . $terms['timezone'] ) . "\n";
		} else {
			self::order_notice( $order );
		}
	}

	/**
	 * Extend Woo's authenticated, scoped personal-data export.
	 *
	 * @param array     $data Existing export items.
	 * @param \WC_Order $order Order selected by Woo's exporter.
	 * @return array Export items including the original dispatch notice.
	 */
	public static function privacy_export( array $data, \WC_Order $order ): array {
		$terms = self::saved_terms( $order );
		if ( $terms ) {
			$data[] = array(
				'name'  => __( 'Dispatch notice at checkout', 'coocadev-dispatchgo' ),
				'value' => $terms['applicable'] ? self::text( $terms ) . ' (' . $terms['timezone'] . ')' : __( 'No dispatch pause applied.', 'coocadev-dispatchgo' ),
			);
		}
		return $data;
	}

	/** Suggest an accurate privacy-policy paragraph for the site owner. */
	public static function privacy_policy(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( 'Coocadev DispatchGo', '<p>' . esc_html__( 'DispatchGo records the dispatch notice displayed at checkout with the order. It does not add tracking, external requests, customer identifiers or payment data. The receipt follows the retention of the order and is included in WooCommerce personal-data exports. Order receipts are retained when the plugin is removed; the store can remove them under its order-retention policy. Existing WooCommerce session cookies are used by checkout.', 'coocadev-dispatchgo' ) . '</p>' );
		}
	}
}
