<?php
/**
 * Site-time opening hours.
 *
 * @package CoocadevHoursGo
 */

namespace Coocadev\HoursGo;

defined( 'ABSPATH' ) || exit;
/** Independently scoped product controller and domain operations. */
final class Plugin {
	/**
	 * Register only this product's hooks and shortcode.
	 */
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_cchg_save', array( self::class, 'handle' ) );
		add_shortcode( 'cchg_hours', array( self::class, 'shortcode' ) );
	}
	/**
	 * Register the settings screen with its required capability.
	 */
	public static function menu(): void {
		add_options_page( __( 'HoursGo', 'coocadev-hoursgo' ), __( 'HoursGo', 'coocadev-hoursgo' ), 'manage_options', 'cchg-hours', array( self::class, 'admin' ) ); }
	/**
	 * Return the conservative settings used before explicit configuration.
	 *
	 * @return array Validated result of this operation.
	 */
	public static function defaults(): array {
		return array(
			'week'        => array_fill(
				0,
				7,
				array(
					'open'  => '',
					'close' => '',
				)
			),
			'overrides'   => array(),
			'delete_data' => false,
		); }
	/**
	 * Read this site's persisted settings without enabling new behavior.
	 *
	 * @return array Validated result of this operation.
	 */
	public static function settings(): array {
		$value = get_option( 'cchg_settings', array() );
		return array_replace( self::defaults(), is_array( $value ) ? $value : array() ); }
	/**
	 * Resolve site-local wall time and reject missing or repeated DST minutes.
	 *
	 * @param string $value Local wall-clock value in YYYY-MM-DDTHH:MM format.
	 * @return int|\WP_Error Validated result of this operation.
	 */
	public static function local_time( string $value ): int|\WP_Error {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $value ) ) {
			return new \WP_Error( 'date', __( 'Use a valid local date and time.', 'coocadev-hoursgo' ) ); }
		$zone = wp_timezone();
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $value, $zone );
		if ( ! $date || $date->format( 'Y-m-d\TH:i' ) !== $value ) {
			return new \WP_Error( 'date', __( 'This local time does not exist.', 'coocadev-hoursgo' ) ); }
		$stamp       = $date->getTimestamp();
		$transitions = $zone->getTransitions( $stamp - DAY_IN_SECONDS, $stamp + DAY_IN_SECONDS );
		if ( is_array( $transitions ) ) {
			$previous = $transitions[0]['offset'];
			foreach ( array_slice( $transitions, 1 ) as $transition ) {
				if ( $transition['offset'] < $previous ) {
					$wall = strtotime( $value . ':00 UTC' );
					if ( $wall >= $transition['ts'] + $transition['offset'] && $wall < $transition['ts'] + $previous ) {
						return new \WP_Error( 'ambiguous', __( 'Choose a time outside the repeated daylight-saving hour.', 'coocadev-hoursgo' ) ); }
				}
				$previous = $transition['offset'];
			}
		}
		return $stamp;
	}
	/**
	 * Validate the complete settings schema before a single database write.
	 *
	 * @param array    $input Explicitly constructed allowlisted fields to validate.
	 * @param int|null $now UTC Unix timestamp or null for the current time.
	 * @return array|\WP_Error Validated result of this operation.
	 */
	public static function validate( array $input, ?int $now = null ): array|\WP_Error {
		$now   = $now ?? time();
		$clean = self::defaults();
		if ( ! isset( $input['week'] ) || ! is_array( $input['week'] ) || count( $input['week'] ) !== 7 ) {
			return new \WP_Error( 'week', __( 'Supply seven days.', 'coocadev-hoursgo' ) ); }
		foreach ( range( 0, 6 ) as $day ) {
			$row = $input['week'][ $day ] ?? null;
			if ( ! is_array( $row ) || ! isset( $row['open'], $row['close'] ) || ! is_string( $row['open'] ) || ! is_string( $row['close'] ) ) {
				return new \WP_Error( 'hours', __( 'Invalid opening hours.', 'coocadev-hoursgo' ) ); }
			$open  = $row['open'];
			$close = $row['close'];
			if ( '' === $open && '' === $close ) {
				continue; }
			if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $open ) || ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $close ) || $open >= $close ) {
				return new \WP_Error( 'hours', __( 'Closing must follow opening on the same day.', 'coocadev-hoursgo' ) ); }
			$clean['week'][ $day ] = array(
				'open'  => $open,
				'close' => $close,
			);
		}
		if ( ! isset( $input['overrides'] ) || ! is_array( $input['overrides'] ) || count( $input['overrides'] ) > 12 ) {
			return new \WP_Error( 'overrides', __( 'At most 12 exceptions are supported.', 'coocadev-hoursgo' ) ); }
		foreach ( $input['overrides'] as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['start'], $row['end'], $row['message'] ) || ! is_string( $row['start'] ) || ! is_string( $row['end'] ) || ! is_string( $row['message'] ) ) {
				return new \WP_Error( 'override', __( 'Invalid exception.', 'coocadev-hoursgo' ) ); }
			if ( '' === $row['start'] && '' === $row['end'] && '' === $row['message'] ) {
				continue; }
			$start = self::local_time( $row['start'] );
			$end   = self::local_time( $row['end'] );
			if ( is_wp_error( $start ) ) {
				return $start;
			} if ( is_wp_error( $end ) ) {
					return $end; }
			if ( $end <= $start || $end - $start > 31 * DAY_IN_SECONDS || $start > $now + YEAR_IN_SECONDS || '' === trim( $row['message'] ) || strlen( $row['message'] ) > 250 ) {
				return new \WP_Error( 'override', __( 'Use a message and a period of at most 31 days within the next year.', 'coocadev-hoursgo' ) ); }
			foreach ( $clean['overrides'] as $other ) {
				if ( $start < $other['end'] && $end > $other['start'] ) {
					return new \WP_Error( 'overlap', __( 'Exceptions must not overlap.', 'coocadev-hoursgo' ) ); }
			}
			$clean['overrides'][] = array(
				'start'   => $start,
				'end'     => $end,
				'message' => sanitize_text_field( $row['message'] ),
			);
		}
		if ( isset( $input['delete_data'] ) && '1' !== $input['delete_data'] ) {
			return new \WP_Error( 'setting', __( 'Invalid retention choice.', 'coocadev-hoursgo' ) ); }
		$clean['delete_data'] = isset( $input['delete_data'] );
		return $clean;
	}
	/**
	 * Calculate opening state from one authoritative server timestamp.
	 *
	 * @param int|null $now UTC Unix timestamp or null for the current time.
	 * @return array Validated result of this operation.
	 */
	public static function status( ?int $now = null ): array {
		$now      = $now ?? time();
		$settings = self::settings();
		foreach ( $settings['overrides'] as $override ) {
			if ( $now >= $override['start'] && $now < $override['end'] ) {
					return array(
						'exception' => true,
						'open'      => null,
						'message'   => $override['message'],
						'until'     => $override['end'],
					); }
		}
		$date  = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() );
		$hours = $settings['week'][ (int) $date->format( 'w' ) ];
		$clock = $date->format( 'H:i' );
		if ( '' === $hours['open'] ) {
			return array(
				'exception' => false,
				'open'      => false,
				'message'   => __( 'Closed today', 'coocadev-hoursgo' ),
				'until'     => null,
			); }
		// Both the displayed day and the open/closed comparison come from this single server timestamp.
		$open = $clock >= $hours['open'] && $clock < $hours['close'];
		/* translators: 1: opening time, 2: closing time. */
		$message = sprintf( __( 'Today: %1$s–%2$s', 'coocadev-hoursgo' ), $hours['open'], $hours['close'] );
		return array(
			'exception' => false,
			'open'      => $open,
			'message'   => $message,
			'until'     => null,
		);
	}
	/**
	 * Render public current hours with full-page cache exclusion signals.
	 *
	 * @return string Validated result of this operation.
	 */
	public static function shortcode(): string {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		} nocache_headers();
		$status = self::status();
		$label  = $status['exception'] ? __( 'Temporary hours', 'coocadev-hoursgo' ) : ( $status['open'] ? __( 'Open now', 'coocadev-hoursgo' ) : __( 'Closed now', 'coocadev-hoursgo' ) );
		return '<section class="cchg-hours" aria-label="' . esc_attr__( 'Opening hours', 'coocadev-hoursgo' ) . '"><strong>' . esc_html( $label ) . '</strong><p>' . esc_html( $status['message'] ) . '</p><small>' . esc_html( wp_timezone_string() ) . '</small></section>';
	}
	/**
	 * Authorize a POST, validate its fields and persist only the allowlisted values.
	 */
	public static function handle(): void {
		if ( 'post' !== ( isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'coocadev-hoursgo' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'cchg_save' );
		$input = array(
			'week'      => array(),
			'overrides' => array(),
		);
		foreach ( array(
			'week'      => 7,
			'overrides' => 12,
		) as $group => $limit ) {
			if ( ! isset( $_POST[ $group ] ) || ! is_array( $_POST[ $group ] ) || count( $_POST[ $group ] ) > $limit ) {
				wp_die( esc_html__( 'Invalid schedule rows.', 'coocadev-hoursgo' ), '', array( 'response' => 400 ) ); }
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Only bounded structural keys are inspected here; every cell passes posted() and validate().
			foreach ( array_keys( $_POST[ $group ] ) as $index ) {
				if ( ! is_int( $index ) || $index < 0 || $index >= $limit ) {
					wp_die( esc_html__( 'Invalid schedule row.', 'coocadev-hoursgo' ), '', array( 'response' => 400 ) ); }
				$row = array();
				foreach ( 'week' === $group ? array( 'open', 'close' ) : array( 'start', 'end', 'message' ) as $key ) {
					$row[ $key ] = self::posted( $group, $index, $key ); }
				$input[ $group ][ $index ] = $row;
			}
		}
		if ( isset( $_POST['delete_data'] ) ) {
			$input['delete_data'] = self::posted( 'delete_data' ); }
		$clean = self::validate( $input );
		if ( is_wp_error( $clean ) ) {
			wp_die( esc_html( $clean->get_error_message() ), '', array( 'response' => 400 ) ); }
		update_option( 'cchg_settings', $clean, false );
		wp_safe_redirect( admin_url( 'options-general.php?page=cchg-hours&updated=1' ) );
		exit;
	}
	/**
	 * Render an escaped administration screen and its nonce-protected forms.
	 */
	public static function admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		} $settings = self::settings();
		echo '<div class="wrap"><h1>' . esc_html__( 'HoursGo', 'coocadev-hoursgo' ) . '</h1><p>' . esc_html__( 'Add [cchg_hours] to a page. Times use the WordPress site timezone. Blank days are closed. Overnight ranges are not supported.', 'coocadev-hoursgo' ) . '</p><p>' . esc_html__( 'Bypass full-page/CDN caching for that page. The plugin sends no-cache headers, but cannot change an external cache configuration.', 'coocadev-hoursgo' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cchg_save">';
		wp_nonce_field( 'cchg_save' );
		$days = array( __( 'Sunday', 'coocadev-hoursgo' ), __( 'Monday', 'coocadev-hoursgo' ), __( 'Tuesday', 'coocadev-hoursgo' ), __( 'Wednesday', 'coocadev-hoursgo' ), __( 'Thursday', 'coocadev-hoursgo' ), __( 'Friday', 'coocadev-hoursgo' ), __( 'Saturday', 'coocadev-hoursgo' ) );
		foreach ( $days as $index => $day ) {
			echo '<p><strong>' . esc_html( $day ) . '</strong> <label>' . esc_html__( 'Open', 'coocadev-hoursgo' ) . '<input type="time" name="week[' . esc_attr( (string) $index ) . '][open]" value="' . esc_attr( $settings['week'][ $index ]['open'] ) . '"></label> <label>' . esc_html__( 'Close', 'coocadev-hoursgo' ) . '<input type="time" name="week[' . esc_attr( (string) $index ) . '][close]" value="' . esc_attr( $settings['week'][ $index ]['close'] ) . '"></label></p>'; }
		echo '<h2>' . esc_html__( 'Temporary exceptions', 'coocadev-hoursgo' ) . '</h2><p>' . esc_html__( 'Up to 12 non-overlapping exceptions, at most 31 days each. A message replaces the normal schedule during the period. Empty a row to remove it. Repeated or missing daylight-saving minutes are rejected.', 'coocadev-hoursgo' ) . '</p>';
		foreach ( range( 0, 11 ) as $index ) {
			$row = $settings['overrides'][ $index ] ?? array(
				'start'   => 0,
				'end'     => 0,
				'message' => '',
			);
			echo '<fieldset><legend>' . esc_html__( 'Exception', 'coocadev-hoursgo' ) . ' ' . esc_html( (string) ( $index + 1 ) ) . '</legend>';
			foreach ( array(
				'start' => __( 'Starts', 'coocadev-hoursgo' ),
				'end'   => __( 'Ends', 'coocadev-hoursgo' ),
			) as $key => $label ) {
				echo '<label>' . esc_html( $label ) . ' <input type="datetime-local" name="overrides[' . esc_attr( (string) $index ) . '][' . esc_attr( $key ) . ']" value="' . esc_attr( $row[ $key ] ? wp_date( 'Y-m-d\TH:i', $row[ $key ] ) : '' ) . '"></label> '; }
			echo '<label>' . esc_html__( 'Message', 'coocadev-hoursgo' ) . ' <input name="overrides[' . esc_attr( (string) $index ) . '][message]" maxlength="250" value="' . esc_attr( $row['message'] ) . '"></label></fieldset>';
		}
		echo '<p><label><input type="checkbox" name="delete_data" value="1" ' . checked( $settings['delete_data'], true, false ) . '>' . esc_html__( 'Delete HoursGo settings when uninstalling', 'coocadev-hoursgo' ) . '</label></p>';
		submit_button();
		echo '</form></div>';
	}

	/**
	 * Read one scalar POST field without silently coercing arrays or stripping markup.
	 *
	 * @param string   $group Field or group name from the controller allowlist.
	 * @param int|null $index Bounded numeric row index.
	 * @param string   $key Allowlisted cell name.
	 * @return string Validated plain text; domain validators enforce enums and dates.
	 */
	private static function posted( string $group, ?int $index = null, string $key = '' ): string {
		check_admin_referer( 'cchg_save' );
		if ( null === $index ) {
			if ( ! isset( $_POST[ $group ] ) || ! is_string( $_POST[ $group ] ) ) {
				wp_die( esc_html__( 'Invalid field.', 'coocadev-hoursgo' ), '', array( 'response' => 400 ) ); }
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Scalar checked here and losslessly validated below.
			$raw = wp_unslash( $_POST[ $group ] );
		} else {
			if ( ! isset( $_POST[ $group ][ $index ][ $key ] ) || ! is_string( $_POST[ $group ][ $index ][ $key ] ) ) {
				wp_die( esc_html__( 'Invalid field.', 'coocadev-hoursgo' ), '', array( 'response' => 400 ) ); }
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Scalar checked here and losslessly validated below.
			$raw = wp_unslash( $_POST[ $group ][ $index ][ $key ] );
		}
		$clean = sanitize_textarea_field( $raw );
		if ( $clean !== $raw ) {
			wp_die( esc_html__( 'Use plain text without markup.', 'coocadev-hoursgo' ), '', array( 'response' => 400 ) ); }
		return $clean;
	}
}
