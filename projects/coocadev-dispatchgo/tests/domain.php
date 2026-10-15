<?php
/** Run with php tests/domain.php. No WordPress or network needed. */
define( 'ABSPATH', __DIR__ );
function sanitize_textarea_field( $text ) { return trim( strip_tags( $text ) ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
if ( ! function_exists( 'mb_strlen' ) ) { function mb_strlen( $value ) { return preg_match_all( '/./us', $value ); } }
require __DIR__ . '/../plugin/src/class-schedule.php';
use Coocadev\DispatchGo\Schedule;
$count = 0;
function check( $condition, $name ) { global $count; ++$count; if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $name ); } echo 'PASS ' . $name . PHP_EOL; }
function rejects( callable $fn, $name ) { try { $fn(); } catch ( InvalidArgumentException $e ) { check( true, $name ); return; } check( false, $name ); }
$zone = new DateTimeZone( 'Europe/Bucharest' );
$now = strtotime( '2026-10-14T00:00:00Z' );
$input = array( 'enabled' => true, 'starts_local' => '2026-10-20T10:00', 'resumes_local_date' => '2026-10-24', 'customer_message' => 'Shipping pause.', 'show_cart' => true );
$s = Schedule::normalize( $input, $zone->getName(), $now );
check( $s['starts_at_utc'] === strtotime( '2026-10-20T07:00:00Z' ), 'IANA conversion' );
check( $s['resumes_at_utc'] === strtotime( '2026-10-23T21:00:00Z' ), 'resume local midnight' );
check( ! Schedule::terms( $s, true, array(), $s['starts_at_utc'] - 1 )['applicable'], 'before start' );
$terms = Schedule::terms( $s, true, array(), $s['starts_at_utc'] );
check( $terms['applicable'], 'inclusive start' );
check( Schedule::terms( $s, true, array(), $s['resumes_at_utc'] - 1 )['applicable'], 'last active second' );
check( ! Schedule::terms( $s, true, array(), $s['resumes_at_utc'] )['applicable'], 'exclusive end' );
check( ! Schedule::terms( $s, false, array(), $s['starts_at_utc'] )['applicable'], 'virtual only' );
check( ! Schedule::terms( $s, true, array( 'local_pickup:5' ), $s['starts_at_utc'] )['applicable'], 'pickup only' );
check( Schedule::terms( $s, true, array( 'local_pickup:5', 'flat_rate:1' ), $s['starts_at_utc'] )['applicable'], 'mixed packages' );
check( Schedule::terms( $s, true, array( 'not_local_pickup:5' ), $s['starts_at_utc'] )['applicable'], 'method lookalike' );
check( Schedule::terms( $s, true, array( 'local_pickup:5', '' ), $s['starts_at_utc'] )['applicable'], 'unselected package' );
check( ! Schedule::terms( array_merge( $s, array( 'enabled' => false ) ), true, array(), $s['starts_at_utc'] )['applicable'], 'disabled' );
rejects( fn() => Schedule::parse_local( '2026-02-29T10:00', $zone ), 'invalid leap date' );
check( Schedule::parse_local( '2028-02-29T10:00', $zone ) > 0, 'valid leap date' );
rejects( fn() => Schedule::parse_local( '2026-03-29T03:30', $zone ), 'DST gap rejected' );
rejects( fn() => Schedule::parse_local( '2026-10-25T03:30', $zone ), 'DST repeat rejected' );
check( Schedule::parse_local( '2026-10-25T04:30', $zone ) === strtotime( '2026-10-25T02:30:00Z' ), 'DST after repeated hour' );
check( Schedule::parse_local( '2026-10-20T10:00', new DateTimeZone( '+05:30' ) ) === strtotime( '2026-10-20T04:30:00Z' ), 'fixed offset supported' );
rejects( fn() => Schedule::normalize( array_merge( $input, array( 'starts_local' => array() ) ), $zone->getName(), $now ), 'wrong input type' );
rejects( fn() => Schedule::normalize( array_merge( $input, array( 'resumes_local_date' => '2026-10-19' ) ), $zone->getName(), $now ), 'inverted period' );
rejects( fn() => Schedule::normalize( $input, $zone->getName(), $s['resumes_at_utc'] ), 'enabled past period' );
rejects( fn() => Schedule::normalize( array_merge( $input, array( 'customer_message' => str_repeat( 'a', 301 ) ) ), $zone->getName(), $now ), 'message length limit' );
check( Schedule::normalize( array_merge( $input, array( 'customer_message' => '<b>Plain</b>' ) ), $zone->getName(), $now )['customer_message'] === 'Plain', 'plain-text message' );
$token = Schedule::token( $terms, 'test-secret', $now );
check( Schedule::verify( $token, $terms, 'test-secret', $now ), 'valid signed receipt' );
check( Schedule::verify( $token, $terms, 'test-secret', $now + 1800 ), 'receipt expiry inclusive' );
check( ! Schedule::verify( $token, $terms, 'test-secret', $now + 1801 ), 'expired receipt rejected' );
check( ! Schedule::verify( $token, array_merge( $terms, array( 'message_plain' => 'changed' ) ), 'test-secret', $now ), 'changed explanation rejected' );
check( ! Schedule::verify( $token, array_merge( $terms, array( 'resumes_local_date' => '2026-10-26' ) ), 'test-secret', $now ), 'changed date rejected' );
check( ! Schedule::verify( $token, $terms, 'another-secret', $now ), 'forged signature rejected' );
check( ! Schedule::verify( '', $terms, 'test-secret', $now ), 'missing receipt rejected' );
check( ! Schedule::verify( $token . 'x', $terms, 'test-secret', $now ), 'malformed receipt rejected' );
check( ! Schedule::verify( $token, $terms, 'test-secret', $now - 1 ), 'future-created receipt rejected' );
rejects( fn() => Schedule::parse_local( "2026-10-20T10:00\0", $zone ), 'NUL wall clock rejected without ValueError' );
check( Schedule::valid_terms( $terms ), 'version-one terms validate' );
check( ! Schedule::valid_terms( array( 'applicable' => true ) ), 'partial stored terms rejected' );
check( ! Schedule::valid_terms( array_merge( $terms, array( 'resumes_local_date' => "2026-10-26\0" ) ) ), 'corrupt stored date rejected' );
echo "PASS: $count domain assertions\n";
