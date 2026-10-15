<?php
/**
 * Settings CAS and a write-once order receipt ledger.
 *
 * Keeps an immutable first-writer receipt separate from Woo storage.
 * The unique order ID chooses the durable winner before payment.
 * A connection-owned mutex only serializes copying that same winner
 * into WC_Order metadata; it never permits replacing the ledger row.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevDispatchGo
 */

namespace Coocadev\DispatchGo;

defined( 'ABSPATH' ) || exit;

/** Stores configuration and immutable receipts without direct order-table SQL. */
final class Repository {
	/**
	 * Safe inactive configuration.
	 *
	 * @return array Settings defaults.
	 */
	public static function defaults(): array {
		return array(
			'schema_version'            => 1,
			'revision'                  => 0,
			'enabled'                   => false,
			'starts_local'              => '',
			'starts_at_utc'             => 0,
			'resumes_at_utc'            => 0,
			'resumes_local_date'        => '',
			'saved_timezone'            => wp_timezone_string(),
			'customer_message'          => '',
			'show_cart'                 => true,
			'uninstall_remove_settings' => false,
		);
	}

	/**
	 * Create the site-scoped ledger and inactive settings.
	 *
	 * @param bool $network_wide Whether network activation was requested.
	 */
	public static function install( bool $network_wide = false ): void {
		if ( $network_wide ) {
			wp_die( esc_html__( 'Activate DispatchGo separately on each site. Network activation is not supported.', 'coocadev-dispatchgo' ) );
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $wpdb->prefix . 'ccdg_snapshots';
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
			order_id bigint(20) unsigned NOT NULL,
			snapshot longtext NOT NULL,
			PRIMARY KEY  (order_id)
		) {$charset};"
		);
		add_option( 'ccdg_settings', self::defaults(), '', false );
	}

	/**
	 * Read settings with fallback defaults.
	 *
	 * @return array Settings.
	 */
	public static function settings(): array {
		$value = get_option( 'ccdg_settings', self::defaults() );
		return is_array( $value ) ? array_merge( self::defaults(), $value ) : self::defaults();
	}

	/**
	 * Compare-and-swap prevents two admin tabs silently overwriting each other.
	 *
	 * @param array $next Normalized settings.
	 * @param int   $revision Expected current revision.
	 * @return bool Whether this writer committed its revision.
	 */
	public static function save_settings( array $next, int $revision ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The settings CAS must compare the database value, never a potentially stale object cache.
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'ccdg_settings' ) );
		$old = maybe_unserialize( $raw );
		if ( ! is_array( $old ) || ! isset( $old['revision'] ) || (int) $old['revision'] !== $revision ) {
			return false;
		}
		$next['revision'] = $revision + 1;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- WordPress has no compare-and-swap option API; invalidate its option cache immediately below.
		$changed = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, maybe_serialize( $next ), 'ccdg_settings', $raw ) );
		wp_cache_delete( 'ccdg_settings', 'options' );
		return 1 === $changed;
	}

	/**
	 * Read authoritative immutable payload; never use stale cache for a write gate.
	 *
	 * @param int $order_id Woo order ID.
	 * @return array|null Immutable receipt, or absent.
	 * @throws \RuntimeException When persisted receipt data is corrupt.
	 */
	public static function receipt( int $order_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own immutable receipt ledger; a write gate requires an authoritative uncached read.
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT snapshot FROM %i WHERE order_id = %d', $wpdb->prefix . 'ccdg_snapshots', $order_id ) );
		if ( null === $raw ) {
			return null;
		}
		$value = json_decode( $raw, true );
		if ( ! is_array( $value ) || 1 !== ( $value['version'] ?? null ) || ! isset( $value['terms'] ) || ! is_array( $value['terms'] ) || ! Schedule::valid_terms( $value['terms'] ) ) {
			throw new \RuntimeException( 'Invalid DispatchGo receipt.' );
		}
		return $value;
	}

	/**
	 * Unique-key insert selects one winner without expiring locks or takeover.
	 *
	 * @param \WC_Order $order Existing Woo order.
	 * @param array     $candidate Server-validated candidate receipt.
	 * @return array The durable first receipt.
	 * @throws \RuntimeException When persistence or immutable comparison fails.
	 */
	public static function freeze( \WC_Order $order, array $candidate ): array {
		global $wpdb;
		$id = $order->get_id();
		if ( $id <= 0 ) {
			throw new \RuntimeException( 'Missing order.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Unique-key insert is the durable first-writer gate; receipt reads are intentionally uncached.
		$insert = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (order_id, snapshot) VALUES (%d, %s)', $wpdb->prefix . 'ccdg_snapshots', $id, wp_json_encode( $candidate ) ) );
		$winner = self::receipt( $id );
		if ( false === $insert || ! $winner ) {
			throw new \RuntimeException( 'DispatchGo receipt could not be saved.' );
		}
		if ( $winner['terms'] !== $candidate['terms'] ) {
			throw new \RuntimeException( 'DispatchGo receipt changed. Review checkout again.' );
		}
		// The immutable ledger prevents stale-owner replacement. A connection-owned
		// mutex only avoids duplicate Woo meta rows during concurrent materialization.
		// It has no TTL takeover and is automatically released if the DB connection dies.
		$lock = 'ccdg:' . hash( 'sha256', $wpdb->prefix . ':' . $id );
		$lock = substr( $lock, 0, 64 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-scoped MySQL mutex has no WordPress API and must not be cached.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 3 ) ) ) {
			throw new \RuntimeException( 'DispatchGo order receipt is busy. Retry checkout.' );
		}
		try {
			$order->read_meta_data( true );
			if ( $order->get_meta( '_ccdg_snapshot', true ) !== $winner ) {
				$order->update_meta_data( '_ccdg_snapshot', $winner );
				$order->save_meta_data();
			}
			$order->read_meta_data( true );
			if ( $order->get_meta( '_ccdg_snapshot', true ) !== $winner ) {
				throw new \RuntimeException( 'DispatchGo order notice could not be saved.' );
			}
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Release the mutex on this connection; caching would leave it held.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
		return $winner;
	}

	/**
	 * Remove a ledger row only after Woo permanently deletes its order.
	 *
	 * @param int $order_id Deleted order ID.
	 */
	public static function deleted_order( int $order_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete only this plugin's uncached ledger row after Woo permanently deletes its order.
		$wpdb->delete( $wpdb->prefix . 'ccdg_snapshots', array( 'order_id' => $order_id ), array( '%d' ) );
	}
}
