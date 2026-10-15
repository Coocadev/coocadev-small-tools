<?php
/**
 * Stable mark identifiers and versioned definitions.
 *
 * Owns stable mark UUIDs and typed versioned definitions.
 * Renaming a label preserves its history; changing the mark type does
 * not. Explicit deletion and state writes share the same settings-row
 * lock so a concurrent writer cannot strand orphaned mark rows.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevDoneMark
 */

namespace Coocadev\DoneMark;

defined( 'ABSPATH' ) || exit;

/**
 * Site-scoped DoneMark Settings component.
 */
final class Settings {
	/**
	 * Return the versioned site-local settings defaults.
	 *
	 * @return array Operation result.
	 */
	public static function defaults(): array {
		return array(
			'schema_version'           => 1,
			'revision'                 => 1,
			'definitions'              => array(),
			'remove_data_on_uninstall' => false,
		);
	}

	/**
	 * Read authoritative definitions without an external cache race.
	 *
	 * @return array Operation result.
	 */
	public static function read(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Definition changes must not be hidden by stale cache.
		$raw   = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'ccdm_settings' ) );
		$value = is_string( $raw ) ? maybe_unserialize( $raw ) : null;
		return is_array( $value ) && 1 === ( $value['schema_version'] ?? null ) && is_array( $value['definitions'] ?? null ) ? $value : self::defaults();
	}

	/**
	 * Validate and compare-and-swap administrator-owned mark definitions.
	 *
	 * @param array $definitions Validated operation input or WordPress hook value.
	 * @param int   $revision Validated operation input or WordPress hook value.
	 * @param bool  $remove Validated operation input or WordPress hook value.
	 */
	public static function save( array $definitions, int $revision, bool $remove ) {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'ccdm_forbidden', __( 'You cannot manage DoneMark definitions.', 'coocadev-donemark' ), array( 'status' => 403 ) );
		}
		if ( count( $definitions ) > 12 ) {
			return new \WP_Error( 'ccdm_limit', __( 'Use at most 12 marks to keep the editing list readable.', 'coocadev-donemark' ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Conditional settings write.
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'ccdm_settings' ) );
		$old = is_string( $raw ) ? maybe_unserialize( $raw ) : null;
		if ( ! is_array( $old ) || ( $old['revision'] ?? 0 ) !== $revision ) {
			return new \WP_Error( 'ccdm_conflict', __( 'Settings changed. Reload before saving.', 'coocadev-donemark' ) );
		}
		$existing = array_column( $old['definitions'], null, 'id' );
		$valid    = array();
		$seen     = array();
		foreach ( $definitions as $definition ) {
			if ( ! is_array( $definition ) || array_diff( array_keys( $definition ), array( 'id', 'label', 'type', 'post_types', 'archived' ) ) ||
				! is_string( $definition['label'] ?? null ) || '' === trim( $definition['label'] ) || mb_strlen( $definition['label'] ) > 40 ||
				! in_array( $definition['type'] ?? '', array( 'review', 'distribution' ), true ) ||
				! is_array( $definition['post_types'] ?? null ) || ! $definition['post_types'] || ! is_bool( $definition['archived'] ?? null ) ) {
				return new \WP_Error( 'ccdm_definition', __( 'Each mark needs a label of up to 40 characters, a type and applicable post types.', 'coocadev-donemark' ) );
			}
			$id = $definition['id'] ?? '';
			if ( ! is_string( $id ) || ( '' !== $id && ( ! isset( $existing[ $id ] ) || isset( $seen[ $id ] ) ) ) ) {
				return new \WP_Error( 'ccdm_definition', __( 'Unknown or duplicate mark identifier.', 'coocadev-donemark' ) );
			}
			if ( '' !== $id && $existing[ $id ]['type'] !== $definition['type'] ) {
				return new \WP_Error( 'ccdm_type', __( 'An existing mark’s type cannot change. Create another mark instead.', 'coocadev-donemark' ) );
			}
			$id          = '' === $id ? wp_generate_uuid4() : $id;
			$seen[ $id ] = true;
			foreach ( $definition['post_types'] as $type ) {
				$object = is_string( $type ) ? get_post_type_object( $type ) : null;
				if ( ! $object || ! $object->show_ui || in_array( $type, array( 'attachment', 'revision', 'nav_menu_item' ), true ) ) {
					return new \WP_Error( 'ccdm_post_type', __( 'Choose supported editable post types.', 'coocadev-donemark' ) );
				}
			}
			$label = sanitize_text_field( $definition['label'] );
			if ( '' === trim( $label ) ) {
				return new \WP_Error( 'ccdm_definition', __( 'A mark label must contain readable text.', 'coocadev-donemark' ) );
			}
			$valid[] = array(
				'id'         => $id,
				'label'      => $label,
				'type'       => $definition['type'],
				'post_types' => array_values( array_unique( $definition['post_types'] ) ),
				'archived'   => $definition['archived'],
			);
		}
		// Normal saves cannot implicitly delete marks and their history.
		if ( array_diff( array_keys( $existing ), array_keys( $seen ) ) ) {
			return new \WP_Error( 'ccdm_delete', __( 'Archive a mark or use its separate confirmed Delete action.', 'coocadev-donemark' ) );
		}
		$value = array(
			'schema_version'           => 1,
			'revision'                 => $revision + 1,
			'definitions'              => $valid,
			'remove_data_on_uninstall' => $remove,
		);
		return self::replace( $raw, $value );
	}

	/**
	 * Replace only the exact settings revision that was read.
	 *
	 * @param string $raw Validated operation input or WordPress hook value.
	 * @param array  $value Validated operation input or WordPress hook value.
	 */
	private static function replace( string $raw, array $value ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic CAS; one writer wins.
		$result = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s', $wpdb->options, maybe_serialize( $value ), 'ccdm_settings', $raw ) );
		if ( 1 !== $result ) {
			return new \WP_Error( 'ccdm_conflict', __( 'Settings changed. Reload before saving.', 'coocadev-donemark' ) );
		}
		wp_cache_delete( 'ccdm_settings', 'options' );
		return $value;
	}

	/**
	 * Authorize and atomically delete a definition with all its states.
	 *
	 * @param string $id Validated operation input or WordPress hook value.
	 * @param int    $revision Validated operation input or WordPress hook value.
	 */
	public static function delete( string $id, int $revision ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'ccdm_forbidden', __( 'You cannot manage DoneMark definitions.', 'coocadev-donemark' ), array( 'status' => 403 ) );
		}
		return self::locked( static fn() => self::delete_locked( $id, $revision ) );
	}

	/**
	 * Serialize definition deletion and state writes using one InnoDB option row lock.
	 *
	 * @param callable $operation Validated operation input or WordPress hook value.
	 */
	public static function locked( callable $operation ) {
		global $wpdb;
		foreach ( array( $wpdb->options, $wpdb->prefix . 'ccdm_states' ) as $table ) {
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
			if ( 'InnoDB' !== $engine ) {
				return new \WP_Error( 'ccdm_storage', __( 'DoneMark requires InnoDB storage for safe concurrent changes.', 'coocadev-donemark' ), array( 'status' => 503 ) );
			}
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new \WP_Error( 'ccdm_storage', __( 'The database could not start this change. Please retry.', 'coocadev-donemark' ), array( 'status' => 503 ) );
		}
		try {
			$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name = %s FOR UPDATE', $wpdb->options, 'ccdm_settings' ) );
			if ( 'ccdm_settings' !== $locked ) {
				return new \WP_Error( 'ccdm_storage', __( 'DoneMark settings are unavailable. Reactivate the plugin and retry.', 'coocadev-donemark' ), array( 'status' => 503 ) );
			}
			$result = $operation();
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				return new \WP_Error( 'ccdm_storage', __( 'The change could not be committed. Reload before retrying.', 'coocadev-donemark' ), array( 'status' => 503 ) );
			}
			return $result;
		} finally {
			// Harmless after COMMIT; every early error releases the row lock.
			$wpdb->query( 'ROLLBACK' );
		}
	}

	/**
	 * Delete a definition and its rows inside the caller transaction.
	 *
	 * @param string $id Validated operation input or WordPress hook value.
	 * @param int    $revision Validated operation input or WordPress hook value.
	 */
	private static function delete_locked( string $id, int $revision ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Compare exact persisted settings.
		$raw   = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'ccdm_settings' ) );
		$value = is_string( $raw ) ? maybe_unserialize( $raw ) : null;
		if ( ! is_array( $value ) || $revision !== $value['revision'] ) {
			return new \WP_Error( 'ccdm_conflict', __( 'Settings changed. Reload before deleting.', 'coocadev-donemark' ) );
		}
		$found = array_filter( $value['definitions'], static fn( $mark ) => $mark['id'] === $id );
		if ( ! $found ) {
			return new \WP_Error( 'ccdm_missing', __( 'Mark not found.', 'coocadev-donemark' ) );
		}
		$value['definitions'] = array_values( array_filter( $value['definitions'], static fn( $mark ) => $mark['id'] !== $id ) );
		++$value['revision'];
		$result = self::replace( $raw, $value );
		if ( ! is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Only explicitly deleted mark data.
			$deleted = $wpdb->delete( $wpdb->prefix . 'ccdm_states', array( 'mark_id' => $id ), array( '%s' ) );
			if ( false === $deleted ) {
				return new \WP_Error( 'ccdm_storage', __( 'The mark could not be deleted. Please retry.', 'coocadev-donemark' ), array( 'status' => 503 ) );
			}
		}
		return $result;
	}

	/**
	 * Return non-archived definitions for an editable post type.
	 *
	 * @param string $post_type Validated operation input or WordPress hook value.
	 * @return array Operation result.
	 */
	public static function applicable( string $post_type ): array {
		return array_values( array_filter( self::read()['definitions'], static fn( $mark ) => ! $mark['archived'] && in_array( $post_type, $mark['post_types'], true ) ) );
	}
}
