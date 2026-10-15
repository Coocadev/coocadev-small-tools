<?php
/**
 * Authoritative fingerprints and independent compare-and-swap mark state.
 *
 * Stores each post/mark independently with an optimistic row version.
 * A review fingerprint describes saved title/content/excerpt bytes.
 * It is not a claim about SEO metadata, media or content accuracy.
 * Staleness is derived from comparison, rather than rewriting history.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevDoneMark
 */

namespace Coocadev\DoneMark;

defined( 'ABSPATH' ) || exit;

/**
 * Site-scoped DoneMark States component.
 */
final class States {
	/**
	 * Install the site-local state table idempotently.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $wpdb->prefix . 'ccdm_states';
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $table (
			post_id bigint unsigned NOT NULL,
			mark_id varchar(40) NOT NULL,
			is_done tinyint NOT NULL DEFAULT 0,
			reviewed_hash char(64) DEFAULT NULL,
			hash_version smallint NOT NULL DEFAULT 1,
			actor_user_id bigint unsigned DEFAULT NULL,
			changed_at_gmt datetime NOT NULL,
			version bigint unsigned NOT NULL DEFAULT 1,
			PRIMARY KEY  (post_id,mark_id),
			KEY mark_status (mark_id,is_done,post_id),
			KEY actor (actor_user_id,post_id)
		) ENGINE=InnoDB $charset;"
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Verify migration before recording version.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $exists !== $table ) {
			wp_die( esc_html__( 'DoneMark could not create its state table.', 'coocadev-donemark' ) );
		}
		update_option( 'ccdm_schema', 1, false );
	}

	/**
	 * Hash length-prefixed saved title, content and excerpt bytes.
	 *
	 * @param array $post Validated operation input or WordPress hook value.
	 * @return string Operation result.
	 */
	public static function fingerprint( array $post ): string {
		$parts = array( '1' );
		foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field ) {
			$value   = (string) $post[ $field ];
			$parts[] = strlen( $value ) . ':' . $value;
		}
		return hash( 'sha256', implode( ':', $parts ) );
	}

	/**
	 * Read persisted post content rather than a stale object-cache entry.
	 *
	 * @param int $id Validated operation input or WordPress hook value.
	 * @return array|null Operation result.
	 */
	public static function post( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Correctness requires persisted content.
		$post = $wpdb->get_row( $wpdb->prepare( 'SELECT ID, post_type, post_status, post_title, post_content, post_excerpt FROM %i WHERE ID = %d', $wpdb->posts, $id ), ARRAY_A );
		return is_array( $post ) ? $post : null;
	}

	/**
	 * Require permission to edit this non-trashed content object.
	 *
	 * @param int $id Validated operation input or WordPress hook value.
	 * @return bool Operation result.
	 */
	public static function access( int $id ): bool {
		$post = self::post( $id );
		return $post && ! in_array( $post['post_status'], array( 'trash', 'auto-draft' ), true ) && ! in_array( $post['post_type'], array( 'revision', 'attachment' ), true ) && current_user_can( 'edit_post', $id );
	}

	/**
	 * Return only marks the current editor can access.
	 *
	 * @param int $id Validated operation input or WordPress hook value.
	 */
	public static function view( int $id ) {
		if ( ! self::access( $id ) ) {
			return new \WP_Error( 'ccdm_forbidden', __( 'You cannot edit marks for this post.', 'coocadev-donemark' ), array( 'status' => 403 ) );
		}
		$post = self::post( $id );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private state, fresh after a concurrent write.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d', $wpdb->prefix . 'ccdm_states', $id ), ARRAY_A );
		return self::compose( $post, $rows ? $rows : array(), Settings::applicable( $post['post_type'] ) );
	}

	/**
	 * Compose independent marks and detect content review staleness.
	 *
	 * @param array $post Validated operation input or WordPress hook value.
	 * @param array $rows Validated operation input or WordPress hook value.
	 * @param array $definitions Validated operation input or WordPress hook value.
	 * @return array Operation result.
	 */
	public static function compose( array $post, array $rows, array $definitions ): array {
		$hash   = self::fingerprint( $post );
		$states = array_column( $rows, null, 'mark_id' );
		$marks  = array();
		foreach ( $definitions as $definition ) {
			$row     = $states[ $definition['id'] ] ?? null;
			$done    = $row && (bool) $row['is_done'];
			$stale   = $done && 'review' === $definition['type'] && ( 1 !== (int) $row['hash_version'] || ! hash_equals( $hash, (string) $row['reviewed_hash'] ) );
			$actor   = $row && $row['actor_user_id'] ? get_userdata( (int) $row['actor_user_id'] ) : null;
			$marks[] = array(
				'id'             => $definition['id'],
				'label'          => $definition['label'],
				'type'           => $definition['type'],
				'done'           => (bool) $done,
				'stale'          => (bool) $stale,
				'version'        => $row ? (int) $row['version'] : 0,
				'changed_at_gmt' => $row ? $row['changed_at_gmt'] : null,
				'actor'          => $row ? ( $actor ? $actor->display_name : __( 'Former team member', 'coocadev-donemark' ) ) : null,
			);
		}
		return array(
			'post_id'      => (int) $post['ID'],
			'content_hash' => $hash,
			'marks'        => $marks,
		);
	}

	/**
	 * Authorize and atomically change one mark before returning fresh content state.
	 *
	 * @param int    $id Validated operation input or WordPress hook value.
	 * @param string $mark_id Validated operation input or WordPress hook value.
	 * @param bool   $done Validated operation input or WordPress hook value.
	 * @param int    $version Validated operation input or WordPress hook value.
	 * @param string $expected_hash Validated operation input or WordPress hook value.
	 */
	public static function write( int $id, string $mark_id, bool $done, int $version, string $expected_hash ) {
		if ( ! self::access( $id ) ) {
			return new \WP_Error( 'ccdm_forbidden', __( 'You cannot edit marks for this post.', 'coocadev-donemark' ), array( 'status' => 403 ) );
		}
		$result = Settings::locked( static fn() => self::write_locked( $id, $mark_id, $done, $version, $expected_hash ) );
		// Read outside the transaction so an overlapping content save is visibly stale.
		return is_wp_error( $result ) ? $result : self::view( $id );
	}

	/**
	 * Apply version and content checks while holding the definition lock.
	 *
	 * @param int    $id Validated operation input or WordPress hook value.
	 * @param string $mark_id Validated operation input or WordPress hook value.
	 * @param bool   $done Validated operation input or WordPress hook value.
	 * @param int    $version Validated operation input or WordPress hook value.
	 * @param string $expected_hash Validated operation input or WordPress hook value.
	 */
	private static function write_locked( int $id, string $mark_id, bool $done, int $version, string $expected_hash ) {
		global $wpdb;
		if ( ! self::access( $id ) ) {
			return new \WP_Error( 'ccdm_forbidden', __( 'You cannot edit marks for this post.', 'coocadev-donemark' ), array( 'status' => 403 ) );
		}
		$post        = self::post( $id );
		$definitions = array_column( Settings::applicable( $post['post_type'] ), null, 'id' );
		$definition  = $definitions[ $mark_id ] ?? null;
		if ( ! $definition ) {
			return new \WP_Error( 'ccdm_mark', __( 'This mark is no longer available. Reload the list.', 'coocadev-donemark' ), array( 'status' => 409 ) );
		}
		$hash = self::fingerprint( $post );
		if ( 'review' === $definition['type'] && $done && ! hash_equals( $hash, $expected_hash ) ) {
			return new \WP_Error( 'ccdm_content_conflict', __( 'The content changed. Read its current version before marking it reviewed.', 'coocadev-donemark' ), array( 'status' => 409 ) );
		}
		$table    = $wpdb->prefix . 'ccdm_states';
		$reviewed = 'review' === $definition['type'] && $done ? $hash : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic row versioning.
		$old = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d AND mark_id = %s', $table, $id, $mark_id ), ARRAY_A );
		if ( $old && (bool) $old['is_done'] === $done && ( 'review' !== $definition['type'] || ! $done || hash_equals( (string) $old['reviewed_hash'], $reviewed ) ) ) {
			return self::view( $id );
		}
		if ( ( $old ? (int) $old['version'] : 0 ) !== $version ) {
			return new \WP_Error( 'ccdm_conflict', __( 'Another editor changed this mark. Reload its current state.', 'coocadev-donemark' ), array( 'status' => 409 ) );
		}
		$now = current_time( 'mysql', true );
		if ( 0 === $version ) {
			// INSERT IGNORE resolves a race to initialize the same primary key.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The primary key guards concurrent initialization.
			$changed = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (post_id,mark_id,is_done,reviewed_hash,hash_version,actor_user_id,changed_at_gmt,version) VALUES (%d,%s,%d,%s,1,%d,%s,1)', $table, $id, $mark_id, (int) $done, $reviewed, get_current_user_id(), $now ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Conditional update, never last-write-wins.
			$changed = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET is_done=%d, reviewed_hash=%s, hash_version=1, actor_user_id=%d, changed_at_gmt=%s, version=version+1 WHERE post_id=%d AND mark_id=%s AND version=%d', $table, (int) $done, $reviewed, get_current_user_id(), $now, $id, $mark_id, $version ) );
		}
		if ( 1 !== $changed ) {
			return new \WP_Error( 'ccdm_conflict', __( 'This mark was not saved. Reload and try again.', 'coocadev-donemark' ), array( 'status' => 409 ) );
		}
		// A definition may have been deleted while this independent row was written.
		$active = array_column( Settings::applicable( $post['post_type'] ), null, 'id' );
		if ( ! isset( $active[ $mark_id ] ) ) {
			return new \WP_Error( 'ccdm_mark', __( 'The mark was archived or deleted during this request.', 'coocadev-donemark' ), array( 'status' => 409 ) );
		}
		// Re-reading content after the CAS makes an intervening content edit visibly stale.
		return self::view( $id );
	}

	/**
	 * Clean owned state when WordPress permanently deletes a post.
	 *
	 * @param int $id Validated operation input or WordPress hook value.
	 */
	public static function delete_post( int $id ): void {
		Settings::locked(
			static function () use ( $id ) {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Serialize cleanup with state writers after Core has removed the post.
				$deleted = $wpdb->delete( $wpdb->prefix . 'ccdm_states', array( 'post_id' => $id ), array( '%d' ) );
				return false === $deleted ? new \WP_Error( 'ccdm_storage', __( 'DoneMark could not remove the deleted post’s states.', 'coocadev-donemark' ) ) : true;
			}
		);
	}
}
