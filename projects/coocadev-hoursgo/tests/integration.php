<?php
/** Behavioral schedule and DST regression tests, executed by wp eval-file. */
use Coocadev\HoursGo\Plugin;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( '127.0.0.1', 'localhost', '::1' ), true ) ) { throw new RuntimeException( 'Synthetic integration tests require local WP-CLI and a localhost site.' ); }
$cchg_results = array(); $cchg_old = get_option( 'cchg_settings', null ); $cchg_zone = get_option( 'timezone_string' );
$cchg_assert = static function ( string $name, bool $condition ) use ( &$cchg_results ): void { $cchg_results[] = array( 'test' => $name, 'passed' => $condition ); if ( ! $condition ) { throw new RuntimeException( $name ); } };
try {
 update_option( 'timezone_string', 'Europe/Bucharest' );
 $cchg_week = array_fill( 0, 7, array( 'open' => '09:00', 'close' => '17:00' ) );
 $cchg_input = array( 'week' => $cchg_week, 'overrides' => array() );
 $cchg_settings = Plugin::validate( $cchg_input );
 $cchg_assert( 'Valid seven-day schedule accepted', ! is_wp_error( $cchg_settings ) ); update_option( 'cchg_settings', $cchg_settings );
 $cchg_now = Plugin::local_time( '2026-10-15T10:00' );
 $cchg_assert( 'Site-local morning is open', true === Plugin::status( $cchg_now )['open'] );
 $cchg_assert( 'Opening boundary included', true === Plugin::status( Plugin::local_time( '2026-10-15T09:00' ) )['open'] );
 $cchg_assert( 'Closing boundary excluded', false === Plugin::status( Plugin::local_time( '2026-10-15T17:00' ) )['open'] );
 $cchg_assert( 'Missing DST minute rejected', is_wp_error( Plugin::local_time( '2026-03-29T03:30' ) ) );
 $cchg_assert( 'Repeated DST minute rejected', is_wp_error( Plugin::local_time( '2026-10-25T03:30' ) ) );
 $cchg_assert( 'Invalid calendar date rejected', is_wp_error( Plugin::local_time( '2026-02-30T10:00' ) ) );
 $cchg_input['overrides'][] = array( 'start' => '2026-10-15T10:00', 'end' => '2026-10-15T11:00', 'message' => 'Closed for maintenance' );
 $cchg_override = Plugin::validate( $cchg_input, $cchg_now ); update_option( 'cchg_settings', $cchg_override );
 $cchg_assert( 'Override start included', 'Closed for maintenance' === Plugin::status( $cchg_now )['message'] );
 $cchg_assert( 'Override end expires without cron', false === Plugin::status( Plugin::local_time( '2026-10-15T11:00' ) )['exception'] );
 $cchg_input['overrides'][] = array( 'start' => '2026-10-15T10:30', 'end' => '2026-10-15T12:00', 'message' => 'Overlap' );
 $cchg_assert( 'Overlapping exceptions rejected', is_wp_error( Plugin::validate( $cchg_input, $cchg_now ) ) );
 $cchg_bad = array( 'week' => $cchg_week, 'overrides' => array(), 'delete_data' => array( '1' ) );
 $cchg_assert( 'Malformed checkbox rejected', is_wp_error( Plugin::validate( $cchg_bad ) ) );
 $cchg_bad = array( 'week' => $cchg_week, 'overrides' => array() ); $cchg_bad['week'][0] = array( 'open' => '17:00', 'close' => '09:00' );
 $cchg_assert( 'Overnight/inverted range rejected', is_wp_error( Plugin::validate( $cchg_bad ) ) );
 $cchg_bad['week'] = $cchg_week; $cchg_bad['overrides'] = array_fill( 0, 13, array() );
 $cchg_assert( 'Exception count bounded', is_wp_error( Plugin::validate( $cchg_bad ) ) );
 $cchg_html = Plugin::shortcode();
 $cchg_assert( 'Output contains site timezone', str_contains( $cchg_html, 'Europe/Bucharest' ) );
 $cchg_assert( 'Time-sensitive shortcode disables page cache', defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
 echo wp_json_encode( array( 'version' => '0.2.0', 'wp' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'tests' => $cchg_results ), JSON_PRETTY_PRINT ) . "\n";
} finally { if ( null === $cchg_old ) { delete_option( 'cchg_settings' ); } else { update_option( 'cchg_settings', $cchg_old ); } update_option( 'timezone_string', $cchg_zone ); }
