<?php
/** No email is delivered: test the body filter and intercept wp_mail transport. */
use Coocadev\MailSkin\Plugin;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( '127.0.0.1', 'localhost', '::1' ), true ) ) { throw new RuntimeException( 'Synthetic integration tests require local WP-CLI and a localhost site.' ); }
$ccms_results = array(); $ccms_old = get_option( 'ccms_settings', null );
$ccms_assert = static function ( string $name, bool $condition ) use ( &$ccms_results ): void { $ccms_results[] = array( 'test' => $name, 'passed' => $condition ); if ( ! $condition ) { throw new RuntimeException( $name ); } };
try {
 delete_option( 'ccms_settings' );
 $ccms_mail = array( 'to' => array( 'recipient@example.test' ), 'subject' => 'Original subject', 'message' => 'Plain message', 'headers' => array( 'X-Example: untouched' ), 'attachments' => array( 'fixture.pdf' ) );
 $ccms_assert( 'Disabled by default', $ccms_mail === Plugin::mail( $ccms_mail ) );
 $ccms_settings = Plugin::validate( array( 'enabled' => '1', 'skin' => 'clean', 'brand' => 'Acme & Company', 'color' => '#123abc', 'logo' => '0', 'signature' => 'Support team' ) );
 $ccms_assert( 'Valid explicit opt-in settings accepted', ! is_wp_error( $ccms_settings ) ); update_option( 'ccms_settings', $ccms_settings );
 $ccms_plain = Plugin::mail( $ccms_mail );
 $ccms_assert( 'Plain text remains plain text', str_starts_with( $ccms_plain['message'], 'Plain message' ) && ! str_contains( $ccms_plain['message'], '<' ) );
 $ccms_assert( 'Recipients subject headers attachments unchanged', $ccms_plain['to'] === $ccms_mail['to'] && $ccms_plain['subject'] === $ccms_mail['subject'] && $ccms_plain['headers'] === $ccms_mail['headers'] && $ccms_plain['attachments'] === $ccms_mail['attachments'] );
 $ccms_assert( 'Plain branding idempotent', Plugin::mail( $ccms_plain ) === $ccms_plain );
 $ccms_html = $ccms_mail; $ccms_html['headers'][] = 'Content-Type: text/html; charset=UTF-8'; $ccms_html['message'] = '<p>Original <strong>HTML</strong></p>';
 $ccms_branded = Plugin::mail( $ccms_html );
 $ccms_assert( 'HTML fragment kept inside branded template', str_contains( $ccms_branded['message'], $ccms_html['message'] ) && str_contains( $ccms_branded['message'], 'Acme &amp; Company' ) );
 $ccms_assert( 'HTML headers retained', $ccms_branded['headers'] === $ccms_html['headers'] );
 $ccms_assert( 'HTML branding idempotent', Plugin::mail( $ccms_branded ) === $ccms_branded );
 $ccms_complete = '<!doctype html><html><head><title>Preserved</title></head><body><p>Content</p></body></html>';
 $ccms_doc = Plugin::html( $ccms_complete );
 $ccms_assert( 'Complete HTML is not nested', 1 === substr_count( $ccms_doc, '<html>' ) && str_contains( $ccms_doc, '<title>Preserved</title>' ) );
 $ccms_multi = $ccms_mail; $ccms_multi['headers'] = 'Content-Type: multipart/alternative; boundary=abc';
 $ccms_assert( 'Multipart messages unchanged', Plugin::mail( $ccms_multi ) === $ccms_multi );
 $ccms_skins = array(); foreach ( array( 'clean', 'stripe', 'card' ) as $ccms_skin ) { $ccms_variant = $ccms_settings; $ccms_variant['skin'] = $ccms_skin; $ccms_skins[] = Plugin::html( '<p>Sample</p>', $ccms_variant ); }
 $ccms_assert( 'All three skins differ', 3 === count( array_unique( $ccms_skins ) ) );
 $ccms_bad = array( 'enabled' => array( '1' ), 'skin' => 'clean', 'color' => '#123abc', 'brand' => 'A', 'signature' => '', 'logo' => '0' );
 $ccms_assert( 'Array checkbox rejected', is_wp_error( Plugin::validate( $ccms_bad ) ) );
 $ccms_bad['enabled'] = '1'; $ccms_bad['color'] = '#fff;display:none';
 $ccms_assert( 'CSS injection rejected', is_wp_error( Plugin::validate( $ccms_bad ) ) );
 $ccms_bad['color'] = '#123abc'; $ccms_bad['logo'] = '99999999';
 $ccms_assert( 'Unknown logo rejected', is_wp_error( Plugin::validate( $ccms_bad ) ) );
 add_filter( 'ccms_should_brand', '__return_false' );
 $ccms_assert( 'Per-message opt-out preserves original', Plugin::mail( $ccms_html ) === $ccms_html ); remove_filter( 'ccms_should_brand', '__return_false' );
 $ccms_sent = array(); $ccms_sink = static function ( $value, $attributes ) use ( &$ccms_sent ) { $ccms_sent = $attributes; return true; }; add_filter( 'pre_wp_mail', $ccms_sink, 10, 2 );
 wp_mail( 'sink@example.test', 'Transport fixture', '<p>Original</p>', array( 'Content-Type: text/html' ) ); remove_filter( 'pre_wp_mail', $ccms_sink, 10 );
 $ccms_assert( 'Real wp_mail hook reached without transport', str_contains( $ccms_sent['message'], '<!-- ccms:branded -->' ) );
 echo wp_json_encode( array( 'version' => '0.2.0', 'wp' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'tests' => $ccms_results ), JSON_PRETTY_PRINT ) . "\n";
} finally { if ( null === $ccms_old ) { delete_option( 'ccms_settings' ); } else { update_option( 'ccms_settings', $ccms_old ); } }
