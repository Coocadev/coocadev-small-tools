<?php
/**
 * Date calculations and signed preview receipts.
 *
 * Calculates terms using server time and the configured store timezone.
 * A browser token proves a recent preview of those exact terms, not
 * that a client-supplied date is trustworthy. Persisted order receipts
 * are later rendered without recalculating from a changed schedule.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevDispatchGo
 */

namespace Coocadev\DispatchGo;

defined( 'ABSPATH' ) || exit;

/** Pure schedule and preview-integrity rules. */
final class Schedule {
	/**
	 * Validate a stored receipt's shape before rendering or comparing it.
	 *
	 * @param array $terms Stored or calculated terms.
	 * @return bool Whether these version-one terms can be used safely.
	 */
	public static function valid_terms( array $terms ): bool {
		if ( ! isset( $terms['applicable'] ) || ! is_bool( $terms['applicable'] ) ) {
			return false;
		}
		foreach ( array( 'resumes_local_date', 'timezone', 'message_plain' ) as $key ) {
			if ( ! isset( $terms[ $key ] ) || ! is_string( $terms[ $key ] ) ) {
				return false;
			}
		}
		if ( ! $terms['applicable'] ) {
			return '' === $terms['resumes_local_date'] && '' === $terms['timezone'] && '' === $terms['message_plain'];
		}
		if ( ! preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $terms['resumes_local_date'] ) ) {
			return false;
		}
		try {
			$zone = new \DateTimeZone( $terms['timezone'] );
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $terms['resumes_local_date'], $zone );
			return $date && $date->format( 'Y-m-d' ) === $terms['resumes_local_date'];
		} catch ( \Throwable $e ) {
			return false;
		}
	}
	/**
	 * Parse a local wall clock; reject DST gaps and repeated times.
	 *
	 * @param string        $value Local ISO date and minute.
	 * @param \DateTimeZone $zone Store timezone.
	 * @return int UTC timestamp.
	 * @throws \InvalidArgumentException When the wall time is invalid or ambiguous.
	 */
	public static function parse_local( string $value, \DateTimeZone $zone ): int {
		if ( ! preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}$/D', $value ) ) {
			throw new \InvalidArgumentException( 'invalid_date' );
		}
		$date   = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $value, $zone );
		$errors = \DateTimeImmutable::getLastErrors();
		if ( ! $date || ( $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) || $date->format( 'Y-m-d\TH:i' ) !== $value ) {
			throw new \InvalidArgumentException( 'invalid_date' );
		}
		$wall        = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $value, new \DateTimeZone( 'UTC' ) )->getTimestamp();
		$transitions = $zone->getTransitions( $wall - 172800, $wall + 172800 );
		$offsets     = array( $zone->getOffset( $date ) );
		foreach ( false !== $transitions ? $transitions : array() as $transition ) {
			$offsets[] = $transition['offset'];
		}
		$matches = array();
		foreach ( array_unique( $offsets ) as $offset ) {
			$candidate = $wall - $offset;
			if ( ( new \DateTimeImmutable( '@' . $candidate ) )->setTimezone( $zone )->format( 'Y-m-d\TH:i' ) === $value ) {
				$matches[] = $candidate;
			}
		}
		if ( 1 !== count( $matches ) ) {
			throw new \InvalidArgumentException( 'ambiguous_date' );
		}
		return $matches[0];
	}

	/**
	 * Normalize settings without silently repairing invalid input.
	 *
	 * @param array  $input Validated request fields.
	 * @param string $timezone Store timezone name or fixed offset.
	 * @param int    $now UTC clock.
	 * @return array Normalized settings.
	 * @throws \InvalidArgumentException When fields or dates are invalid.
	 */
	public static function normalize( array $input, string $timezone, int $now ): array {
		foreach ( array( 'starts_local', 'resumes_local_date', 'customer_message' ) as $key ) {
			if ( ! isset( $input[ $key ] ) || ! is_string( $input[ $key ] ) ) {
				throw new \InvalidArgumentException( 'invalid_fields' );
			}
		}
		$message = sanitize_textarea_field( $input['customer_message'] );
		if ( mb_strlen( $message ) > 300 ) {
			throw new \InvalidArgumentException( 'message_length' );
		}
		$result = array(
			'schema_version'            => 1,
			'enabled'                   => ! empty( $input['enabled'] ),
			'starts_local'              => $input['starts_local'],
			'resumes_local_date'        => $input['resumes_local_date'],
			'starts_at_utc'             => 0,
			'resumes_at_utc'            => 0,
			'saved_timezone'            => $timezone,
			'customer_message'          => $message,
			'show_cart'                 => ! empty( $input['show_cart'] ),
			'uninstall_remove_settings' => ! empty( $input['uninstall_remove_settings'] ),
		);
		if ( '' !== $input['starts_local'] || '' !== $input['resumes_local_date'] || $result['enabled'] ) {
			$zone                     = new \DateTimeZone( $timezone );
			$result['starts_at_utc']  = self::parse_local( $input['starts_local'], $zone );
			$result['resumes_at_utc'] = self::parse_local( $input['resumes_local_date'] . 'T00:00', $zone );
			if ( $result['resumes_at_utc'] <= $result['starts_at_utc'] || ( $result['enabled'] && $result['resumes_at_utc'] <= $now ) ) {
				throw new \InvalidArgumentException( 'invalid_period' );
			}
		}
		return $result;
	}

	/**
	 * Return only the material terms the customer must have seen.
	 *
	 * @param array $settings Stored settings.
	 * @param bool  $needs_shipping Whether the cart needs shipping.
	 * @param array $methods Selected shipping method IDs.
	 * @param int   $now UTC clock.
	 * @return array Public dispatch terms.
	 */
	public static function terms( array $settings, bool $needs_shipping, array $methods, int $now ): array {
		$all_pickup = count( $methods ) > 0;
		foreach ( $methods as $method ) {
			if ( ! is_string( $method ) || ! preg_match( '/^local_pickup(?::[0-9]+)?$/', $method ) ) {
				$all_pickup = false;
			}
		}
		$active = ! empty( $settings['enabled'] ) && $needs_shipping && ! $all_pickup
			&& $now >= (int) ( $settings['starts_at_utc'] ?? PHP_INT_MAX )
			&& $now < (int) ( $settings['resumes_at_utc'] ?? 0 );
		return array(
			'applicable'         => $active,
			'resumes_local_date' => $active ? (string) $settings['resumes_local_date'] : '',
			'timezone'           => $active ? (string) $settings['saved_timezone'] : '',
			'message_plain'      => $active ? (string) $settings['customer_message'] : '',
		);
	}

	/**
	 * Mint a receipt for a short-lived, server-calculated preview.
	 *
	 * @param array  $terms Server terms.
	 * @param string $secret Site signing secret.
	 * @param int    $now UTC clock.
	 * @return string Signed receipt.
	 */
	public static function token( array $terms, string $secret, int $now ): string {
		$expires = $now + 1800;
		return $expires . '.' . hash_hmac( 'sha256', $expires . ':' . wp_json_encode( $terms ), $secret );
	}

	/**
	 * Verify receipt integrity against current server terms.
	 *
	 * @param string $token Untrusted client receipt.
	 * @param array  $terms Server terms.
	 * @param string $secret Site signing secret.
	 * @param int    $now UTC clock.
	 * @return bool Whether the receipt is current and valid.
	 */
	public static function verify( string $token, array $terms, string $secret, int $now ): bool {
		if ( ! preg_match( '/^([0-9]{10})\.([a-f0-9]{64})$/D', $token, $parts ) ) {
			return false;
		}
		$expires = (int) $parts[1];
		return $expires >= $now && $expires <= $now + 1800
			&& hash_equals( hash_hmac( 'sha256', $expires . ':' . wp_json_encode( $terms ), $secret ), $parts[2] );
	}
}
