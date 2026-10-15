<?php
/**
 * Branding changes the body only; delivery remains the site's mail transport.
 *
 * @package CoocadevMailSkin
 */

namespace Coocadev\MailSkin;

defined( 'ABSPATH' ) || exit;
/** Independently scoped product controller and domain operations. */
final class Plugin {
	/**
	 * Register only this product's hooks and shortcode.
	 */
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_ccms_save', array( self::class, 'handle' ) );
		add_filter( 'wp_mail', array( self::class, 'mail' ), 20 );
	}
	/**
	 * Register the settings screen with its required capability.
	 */
	public static function menu(): void {
		add_options_page( __( 'MailSkin', 'coocadev-mailskin' ), __( 'MailSkin', 'coocadev-mailskin' ), 'manage_options', 'ccms-skin', array( self::class, 'admin' ) ); }
	/**
	 * Return the conservative settings used before explicit configuration.
	 *
	 * @return array Validated result of this operation.
	 */
	public static function defaults(): array {
		return array(
			'enabled'     => false,
			'skin'        => 'clean',
			'color'       => '#3155a4',
			'brand'       => get_bloginfo( 'name' ),
			'signature'   => '',
			'logo'        => 0,
			'delete_data' => false,
		); }
	/**
	 * Read this site's persisted settings without enabling new behavior.
	 *
	 * @return array Validated result of this operation.
	 */
	public static function settings(): array {
		$settings = get_option( 'ccms_settings', array() );
		return array_replace( self::defaults(), is_array( $settings ) ? $settings : array() ); }
	/**
	 * Validate the complete settings schema before a single database write.
	 *
	 * @param array $input Explicitly constructed allowlisted fields to validate.
	 * @return array|\WP_Error Validated result of this operation.
	 */
	public static function validate( array $input ): array|\WP_Error {
		$clean = self::defaults();
		foreach ( array( 'enabled', 'delete_data' ) as $checkbox ) {
			if ( array_key_exists( $checkbox, $input ) && '1' !== $input[ $checkbox ] ) {
					return new \WP_Error( 'checkbox', __( 'Invalid checkbox.', 'coocadev-mailskin' ) );
			} $clean[ $checkbox ] = isset( $input[ $checkbox ] ); }
		foreach ( array( 'skin', 'color', 'brand', 'signature', 'logo' ) as $key ) {
			if ( ! isset( $input[ $key ] ) || ! is_string( $input[ $key ] ) ) {
					return new \WP_Error( 'type', __( 'Invalid setting type.', 'coocadev-mailskin' ) ); }
		}
		if ( ! in_array( $input['skin'], array( 'clean', 'stripe', 'card' ), true ) || ! preg_match( '/^#[0-9a-fA-F]{6}$/D', $input['color'] ) || strlen( $input['brand'] ) > 120 || strlen( $input['signature'] ) > 1000 || ! ctype_digit( $input['logo'] ) ) {
			return new \WP_Error( 'setting', __( 'Check skin, color, logo and text lengths.', 'coocadev-mailskin' ) ); }
		$logo = (int) $input['logo'];
		if ( $logo && ( ! wp_attachment_is_image( $logo ) || ! current_user_can( 'edit_post', $logo ) ) ) {
			return new \WP_Error( 'logo', __( 'Choose an image you can edit from the media library.', 'coocadev-mailskin' ) ); }
		$clean['skin']      = $input['skin'];
		$clean['color']     = strtolower( $input['color'] );
		$clean['brand']     = sanitize_text_field( $input['brand'] );
		$clean['signature'] = sanitize_textarea_field( $input['signature'] );
		$clean['logo']      = $logo;
		return $clean;
	}
	/**
	 * Brand only the body while retaining recipients, headers and attachments.
	 *
	 * @param array $mail WordPress mail arguments; only the message field may change.
	 * @return array Validated result of this operation.
	 */
	public static function mail( array $mail ): array {
		$settings = self::settings();
		if ( ! $settings['enabled'] || ! isset( $mail['message'] ) || ! is_string( $mail['message'] ) || ! apply_filters( 'ccms_should_brand', true, $mail ) ) {
			return $mail; }
		$headers = $mail['headers'] ?? array();
		if ( is_string( $headers ) ) {
			$headers = preg_split( '/\r?\n/', $headers ); }
		if ( ! is_array( $headers ) ) {
			return $mail; }
		$type = 'text/plain';
		foreach ( $headers as $header ) {
			if ( is_string( $header ) && preg_match( '/^Content-Type\s*:\s*([^;\s]+)/i', $header, $matches ) ) {
					$type = strtolower( $matches[1] ); }
		}
		if ( 'text/html' === $type ) {
			if ( ! str_contains( $mail['message'], '<!-- ccms:branded -->' ) ) {
				$mail['message'] = self::html( $mail['message'], $settings ); }
		} elseif ( 'text/plain' === $type ) {
			// A mailer may choose its default content type later. Plain text gets no HTML or synthetic header.
			$footer = trim( $settings['brand'] . "\n" . $settings['signature'] );
			if ( '' !== $footer && ! str_ends_with( $mail['message'], "\n\n--\n" . $footer ) ) {
				$mail['message'] .= "\n\n--\n" . $footer; }
		}
		return $mail;
	}
	/**
	 * Wrap an HTML fragment or insert branding into an existing complete document.
	 *
	 * @param string     $body Trusted original mail HTML or escaped preview content.
	 * @param array|null $settings Validated branding settings or null to read stored settings.
	 * @return string Validated result of this operation.
	 */
	public static function html( string $body, ?array $settings = null ): string {
		$settings = $settings ?? self::settings();
		// Only a validated hexadecimal color reaches CSS. No arbitrary CSS is accepted.
		$color = preg_match( '/^#[0-9a-fA-F]{6}$/D', $settings['color'] ) ? $settings['color'] : '#3155a4';
		$logo  = $settings['logo'] ? wp_get_attachment_image_url( $settings['logo'], 'medium' ) : '';
		$brand = '<!-- ccms:branded --><div style="padding:24px;border-bottom:3px solid ' . esc_attr( $color ) . '">';
		if ( $logo ) {
			$brand .= '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $settings['brand'] ) . '" width="160" style="max-width:160px;height:auto"><br>'; }
		$brand .= '<strong style="color:' . esc_attr( $color ) . '">' . esc_html( $settings['brand'] ) . '</strong></div>';
		$footer = '<div style="padding:24px;color:#555555;font-size:13px">' . nl2br( esc_html( $settings['signature'] ) ) . '</div>';
		if ( preg_match( '/<body\b[^>]*>/i', $body ) ) {
			// Preserve an existing complete document and its styles instead of nesting another HTML document.
			$body = preg_replace_callback( '/<body\b[^>]*>/i', static fn( array $matches ): string => $matches[0] . $brand, $body, 1 );
			return preg_replace_callback( '/<\/body\s*>/i', static fn(): string => $footer . '</body>', $body, 1 );
		}
		$surface = 'card' === $settings['skin'] ? '#f0f2f5' : '#ffffff';
		$border  = 'stripe' === $settings['skin'] ? 'border-left:8px solid ' . $color . ';' : 'border:1px solid #dddddd;';
		return '<!doctype html><html><body style="margin:0;background:' . esc_attr( $surface ) . ';font-family:Arial,sans-serif"><div style="max-width:640px;margin:24px auto;background:#ffffff;' . esc_attr( $border ) . '">' . $brand . '<div style="padding:24px">' . $body . '</div>' . $footer . '</div></body></html>';
	}
	/**
	 * Authorize a POST, validate its fields and persist only the allowlisted values.
	 */
	public static function handle(): void {
		if ( 'post' !== ( isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'coocadev-mailskin' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'ccms_save' );
		$input = array();
		foreach ( array( 'enabled', 'delete_data', 'skin', 'color', 'brand', 'signature', 'logo' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
					$input[ $key ] = self::posted( $key ); }
		}
		$clean = self::validate( $input );
		if ( is_wp_error( $clean ) ) {
			wp_die( esc_html( $clean->get_error_message() ), '', array( 'response' => 400 ) ); }
		update_option( 'ccms_settings', $clean, false );
		wp_safe_redirect( admin_url( 'options-general.php?page=ccms-skin&updated=1' ) );
		exit;
	}
	/**
	 * Render an escaped administration screen and its nonce-protected forms.
	 */
	public static function admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		} $settings = self::settings();
		echo '<div class="wrap"><h1>' . esc_html__( 'MailSkin', 'coocadev-mailskin' ) . '</h1><p>' . esc_html__( 'Branding starts only after you enable it. SMTP and delivery stay with your current mail provider. Preview never sends an email.', 'coocadev-mailskin' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ccms_save">';
		wp_nonce_field( 'ccms_save' );
		echo '<p><label><input type="checkbox" name="enabled" value="1" ' . checked( $settings['enabled'], true, false ) . '>' . esc_html__( 'Enable body branding for messages sent through wp_mail', 'coocadev-mailskin' ) . '</label></p><p><label>' . esc_html__( 'Skin', 'coocadev-mailskin' ) . '<select name="skin">';
		foreach ( array(
			'clean'  => __( 'Clean', 'coocadev-mailskin' ),
			'stripe' => __( 'Stripe', 'coocadev-mailskin' ),
			'card'   => __( 'Card', 'coocadev-mailskin' ),
		) as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $settings['skin'], $key, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></label></p><p><label>' . esc_html__( 'Brand name', 'coocadev-mailskin' ) . '<input name="brand" maxlength="120" value="' . esc_attr( $settings['brand'] ) . '"></label></p><p><label>' . esc_html__( 'Accent color', 'coocadev-mailskin' ) . '<input type="color" name="color" value="' . esc_attr( $settings['color'] ) . '"></label></p><p><label>' . esc_html__( 'Logo media attachment ID (0 for none)', 'coocadev-mailskin' ) . '<input type="number" min="0" name="logo" value="' . esc_attr( (string) $settings['logo'] ) . '"></label></p><p>' . esc_html__( 'A logo is linked from your own media library. The recipient mail application may request that image; no tracking pixel is added.', 'coocadev-mailskin' ) . '</p><p><label>' . esc_html__( 'Signature', 'coocadev-mailskin' ) . '<textarea name="signature" maxlength="1000">' . esc_textarea( $settings['signature'] ) . '</textarea></label></p><p><label><input type="checkbox" name="delete_data" value="1" ' . checked( $settings['delete_data'], true, false ) . '>' . esc_html__( 'Delete MailSkin settings when uninstalling', 'coocadev-mailskin' ) . '</label></p>';
		submit_button();
		echo '</form><h2>' . esc_html__( 'Saved HTML preview — no email sent', 'coocadev-mailskin' ) . '</h2>';
		// srcdoc is escaped as an attribute; sandbox denies scripts and forms even if another plugin modifies this preview.
		$preview = self::html( '<h2>' . esc_html__( 'Your site notification', 'coocadev-mailskin' ) . '</h2><p>' . esc_html__( 'This is sample content. Your original message is kept inside the template.', 'coocadev-mailskin' ) . '</p>', $settings );
		echo '<iframe title="' . esc_attr__( 'MailSkin HTML preview', 'coocadev-mailskin' ) . '" sandbox="" width="700" height="400" srcdoc="' . esc_attr( $preview ) . '"></iframe><h2>' . esc_html__( 'Plain-text signature preview', 'coocadev-mailskin' ) . '</h2><pre>' . esc_html( trim( $settings['brand'] . "\n" . $settings['signature'] ) ) . '</pre></div>';
	}

	/**
	 * Read one scalar POST field without silently coercing arrays or stripping markup.
	 *
	 * @param string $key Field or group name from the controller allowlist.
	 * @return string Validated plain text; domain validators enforce enums and dates.
	 */
	private static function posted( string $key ): string {
		check_admin_referer( 'ccms_save' );
		if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
			wp_die( esc_html__( 'Invalid field.', 'coocadev-mailskin' ), '', array( 'response' => 400 ) ); }
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Type checked here and losslessly validated as plain text immediately below.
		$raw   = wp_unslash( $_POST[ $key ] );
		$clean = sanitize_textarea_field( $raw );
		if ( $clean !== $raw ) {
			wp_die( esc_html__( 'Use plain text without markup.', 'coocadev-mailskin' ), '', array( 'response' => 400 ) ); }
		return $clean;
	}
}
