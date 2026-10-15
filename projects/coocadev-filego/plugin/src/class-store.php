<?php
/**
 * Database lifecycle and indexed data access.
 *
 * Owns only the two site-prefixed tables; values never contain PDF bytes.
 * InnoDB and the unique slug/attachment keys are part of the consistency
 * contract used by Documents. Pointer reads intentionally avoid a shared
 * object cache so a late reader cannot reintroduce a retired target.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevFileGo
 */

namespace Coocadev\FileGo;

defined( 'ABSPATH' ) || exit;

/**
 * Site-scoped FileGo Store component.
 */
final class Store {

	/**
	 * Return only a trusted site-prefixed FileGo table identifier.
	 *
	 * @param string $name Allowlisted table suffix.
	 * @throws \InvalidArgumentException When a non-FileGo table is requested.
	 * @return string Operation result.
	 */
	public static function table( string $name ): string {
		global $wpdb;
		if ( ! in_array( $name, array( 'documents', 'versions' ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown FileGo table.' );
		}
		return $wpdb->prefix . 'ccfg_' . $name;
	}

	/**
	 * Create the initial schema idempotently and grant administrator access.
	 *
	 * @return bool Operation result.
	 */
	public static function install(): bool {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$documents = self::table( 'documents' );
		$versions  = self::table( 'versions' );
		$collate   = $wpdb->get_charset_collate();
		// Identifiers are derived exclusively from wpdb and a closed table-name list.
		dbDelta(
			"CREATE TABLE $documents (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(100) NOT NULL,
			title varchar(200) NOT NULL,
			current_version_id bigint unsigned DEFAULT NULL,
			revision bigint unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'unpublished',
			created_at_gmt datetime NOT NULL,
			updated_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY current_version_id (current_version_id)
		) ENGINE=InnoDB $collate;"
		);
		dbDelta(
			"CREATE TABLE $versions (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			document_id bigint unsigned NOT NULL,
			attachment_id bigint unsigned NOT NULL,
			state varchar(20) NOT NULL DEFAULT 'staged',
			delete_token varchar(64) DEFAULT NULL,
			relative_path varchar(255) NOT NULL,
			byte_size bigint unsigned NOT NULL,
			sha256 char(64) NOT NULL,
			mime varchar(50) NOT NULL,
			note varchar(500) NOT NULL DEFAULT '',
			created_by bigint unsigned NOT NULL DEFAULT 0,
			created_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY attachment_id (attachment_id),
			KEY document_id (document_id,id),
			KEY created_by (created_by,id)
		) ENGINE=InnoDB $collate;"
		);
		if ( ! self::transactional() ) {
			return false;
		}
		update_option( 'ccfg_schema_version', 1, false );
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( 'ccfg_manage_documents' );
		}
		return true;
	}

	/**
	 * Require InnoDB storage for both document and version tables.
	 *
	 * @return bool Operation result.
	 */
	public static function transactional(): bool {
		global $wpdb;
		foreach ( array( 'documents', 'versions' ) as $name ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', self::table( $name ) ), ARRAY_A );
			if ( ! $row || 'innodb' !== strtolower( $row['Engine'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Fetch the current uncached document row by its numeric identifier.
	 *
	 * @param int $id Site-local numeric identifier.
	 * @return object|null Operation result.
	 */
	public static function document( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table( 'documents' ), $id ) );
	}

	/**
	 * Fetch one version row by its numeric identifier.
	 *
	 * @param int $id Site-local numeric identifier.
	 * @return object|null Operation result.
	 */
	public static function version( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table( 'versions' ), $id ) );
	}

	/**
	 * Fetch one bounded page of versions for a document.
	 *
	 * @param int $document Document row or numeric document identifier.
	 * @param int $page One-based result page.
	 * @return array Operation result.
	 */
	public static function versions( int $document, int $page = 1 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE document_id = %d ORDER BY id DESC LIMIT 20 OFFSET %d', self::table( 'versions' ), $document, ( max( 1, $page ) - 1 ) * 20 ) );
	}

	/**
	 * Render bounded search results and the create-document form.
	 *
	 * @param string $search Sanitized title/slug search text.
	 * @param int    $page One-based result page.
	 * @return array Operation result.
	 */
	public static function listing( string $search, int $page ): array {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE title LIKE %s OR slug LIKE %s ORDER BY id DESC LIMIT 21 OFFSET %d', self::table( 'documents' ), $like, $like, ( max( 1, $page ) - 1 ) * 20 ) );
	}

	/**
	 * Calculate indexed per-document storage statistics without scanning uploads.
	 *
	 * @param int $document Document row or numeric document identifier.
	 * @return object Operation result.
	 */
	public static function stats( int $document ): object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS versions, COALESCE(SUM(byte_size),0) AS bytes FROM %i WHERE document_id = %d AND state <> 'deleted'", self::table( 'versions' ), $document ) );
	}
}
