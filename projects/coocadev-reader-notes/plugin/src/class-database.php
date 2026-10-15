<?php
/**
 * Per-site storage. Every mutation locks the owner's epoch row first.
 *
 * Owns private note rows and the per-owner erasure epoch barrier.
 * Every mutation locks the owner state first, then checks epoch/version.
 * Keeping the same lock order coordinates quota, autosave and erasure;
 * a delayed pre-erasure write cannot recreate text after deletion.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevReaderNotes
 */

namespace Coocadev\ReaderNotes;

defined( 'ABSPATH' ) || exit;

/**
 * Database module for private reader notes.
 */
final class Database {
	/**
	 * Return an allowlisted per-site table identifier.
	 *
	 * @param string $kind Kind.
	 * @return string Operation result.
	 */
	public static function table( string $kind = 'notes' ): string {
		global $wpdb;
		return $wpdb->prefix . ( 'state' === $kind ? 'ccrn_user_state' : 'ccrn_notes' );
	}

	/**
	 * Install tables for a single site and reject unsupported network activation.
	 *
	 * @param bool $network_wide Network wide.
	 * @return void Operation result.
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( $network_wide ) {
			wp_die( esc_html__( 'Activate Reader Notes separately on each site. Network activation is not supported.', 'coocadev-reader-notes' ) );
		}
		self::install();
		if ( ! self::healthy() ) {
			wp_die( esc_html__( 'Reader Notes requires its own InnoDB tables. Check database permissions and storage support before activating.', 'coocadev-reader-notes' ) );
		}
	}

	/**
	 * Create the plugin's version-one schema using the site's charset.
	 *
	 * @return void Operation result.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$notes   = self::table();
		$state   = self::table( 'state' );
		$collate = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $notes (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			post_id bigint(20) unsigned DEFAULT NULL,
			content longtext NOT NULL,
			version bigint(20) unsigned NOT NULL DEFAULT 1,
			created_at_gmt datetime NOT NULL,
			updated_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY owner_post (user_id,post_id),
			KEY owner_updated (user_id,updated_at_gmt,id),
			KEY post_id (post_id)
		) ENGINE=InnoDB $collate;"
		);
		dbDelta(
			"CREATE TABLE $state (
			user_id bigint(20) unsigned NOT NULL,
			epoch bigint(20) unsigned NOT NULL DEFAULT 1,
			is_erasing tinyint(1) unsigned NOT NULL DEFAULT 0,
			erase_operation_id varchar(36) DEFAULT NULL,
			PRIMARY KEY  (user_id)
		) ENGINE=InnoDB $collate;"
		);
		if ( self::healthy() ) {
			add_option( 'ccrn_settings', Plugin::settings(), '', true );
			wp_set_option_autoload_values( array( 'ccrn_settings' => true ) );
			update_option( 'ccrn_schema_version', 1, false );
		}
	}

	/**
	 * Verify both owned tables actually support InnoDB transactions.
	 *
	 * @return bool Operation result.
	 */
	public static function healthy(): bool {
		global $wpdb;
		foreach ( array( self::table(), self::table( 'state' ) ) as $table ) {
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
			if ( 'innodb' !== strtolower( (string) $engine ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Read the owner's erasure epoch without creating any state.
	 *
	 * @param int $user_id User id.
	 * @return array Operation result.
	 */
	public static function state( int $user_id ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT epoch,is_erasing,erase_operation_id FROM %i WHERE user_id = %d', self::table( 'state' ), $user_id ), ARRAY_A );
		return array(
			'epoch'     => $row ? (int) $row['epoch'] : 1,
			'erasing'   => $row && (bool) $row['is_erasing'],
			'operation' => $row ? $row['erase_operation_id'] : null,
		);
	}

	/**
	 * Create an owner-safe REST-compatible application error.
	 *
	 * @param string $code Code.
	 * @param string $message Message.
	 * @param int    $status Status.
	 * @return \WP_Error Operation result.
	 */
	public static function error( string $code, string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error( 'ccrn_' . $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Begin a transaction and lock the owner's state before any mutation.
	 *
	 * @param int $user_id User id.
	 * @return array|\WP_Error Operation result.
	 */
	private static function begin( int $user_id ): array|\WP_Error {
		global $wpdb;
		if ( ! self::healthy() ) {
			return self::error( 'storage', __( 'Notes are read-only because transactional storage is unavailable.', 'coocadev-reader-notes' ), 503 );
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return self::error( 'storage', __( 'Could not start a safe save. Please retry.', 'coocadev-reader-notes' ), 503 );
		}
		$insert = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (user_id,epoch,is_erasing) VALUES (%d,1,0)', self::table( 'state' ), $user_id ) );
		$row    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d FOR UPDATE', self::table( 'state' ), $user_id ), ARRAY_A );
		if ( false === $insert || ! $row ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'busy', __( 'Notes storage is busy. Your text has not been saved.', 'coocadev-reader-notes' ), 503 );
		}
		return $row;
	}

	/**
	 * Commit a complete operation or roll back its failure.
	 *
	 * @param mixed $result Result.
	 * @return mixed Operation result.
	 */
	private static function finish( mixed $result ): mixed {
		global $wpdb;
		if ( is_wp_error( $result ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $result;
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'commit', __( 'The save could not be confirmed. Reload before retrying.', 'coocadev-reader-notes' ), 503 );
		}
		return $result;
	}

	/**
	 * Reject stale drafts and any write during an active erasure.
	 *
	 * @param array $state State.
	 * @param int   $epoch Epoch.
	 * @return ?\WP_Error Operation result.
	 */
	private static function barrier( array $state, int $epoch ): ?\WP_Error {
		if ( (int) $state['epoch'] !== $epoch || (bool) $state['is_erasing'] ) {
			return self::error( 'epoch', __( 'Your notes session changed or erasure is in progress. Reload your notes.', 'coocadev-reader-notes' ), 409 );
		}
		return null;
	}

	/**
	 * Validate bounded Unicode plain text and normalize line endings.
	 *
	 * @param mixed $value Value.
	 * @return string|\WP_Error Operation result.
	 */
	public static function content( mixed $value ): string|\WP_Error {
		if ( ! is_string( $value ) || ! preg_match( '//u', $value ) || strlen( $value ) > 40960 ) {
			return self::error( 'content', __( 'Use valid text of at most 10,000 characters and 40 KiB.', 'coocadev-reader-notes' ) );
		}
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value );
		if ( preg_match_all( '/./us', $value ) > 10000 ) {
			return self::error( 'content', __( 'A note can contain at most 10,000 characters.', 'coocadev-reader-notes' ) );
		}
		return $value;
	}

	/**
	 * Read the current owner's note bound to one source article.
	 *
	 * @param int $user_id User id.
	 * @param int $post_id Post id.
	 * @return ?array Operation result.
	 */
	public static function by_post( int $user_id, int $post_id ): ?array {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d AND post_id = %d', self::table(), $user_id, $post_id ), ARRAY_A );
	}

	/**
	 * Read one note only when its owner matches the authenticated subject.
	 *
	 * @param int $user_id User id.
	 * @param int $note_id Note id.
	 * @return ?array Operation result.
	 */
	public static function one( int $user_id, int $note_id ): ?array {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d AND id = %d', self::table(), $user_id, $note_id ), ARRAY_A );
	}

	/**
	 * Create or compare-and-swap a note under the owner's epoch lock.
	 *
	 * @param int    $user_id User id.
	 * @param int    $post_id Post id.
	 * @param string $text Text.
	 * @param int    $version Version.
	 * @param int    $epoch Epoch.
	 * @return array|\WP_Error Operation result.
	 */
	public static function save( int $user_id, int $post_id, string $text, int $version, int $epoch ): array|\WP_Error {
		global $wpdb;
		if ( ! Access::post( $post_id, $user_id ) ) {
			return self::error( 'source', __( 'This source is not available for editing notes.', 'coocadev-reader-notes' ), 403 );
		}
		$text = self::content( $text );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$state = self::begin( $user_id );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		$barrier = self::barrier( $state, $epoch );
		if ( $barrier ) {
			return self::finish( $barrier );
		}
		$old = self::by_post( $user_id, $post_id );
		if ( ( $old && (int) $old['version'] !== $version ) || ( ! $old && 0 !== $version ) ) {
			return self::finish( self::error( 'conflict', __( 'This note changed in another tab. Load the saved version before replacing it.', 'coocadev-reader-notes' ), 409 ) );
		}
		if ( ! $old ) {
			$quota = Plugin::settings()['quota'];
			if ( null !== $quota ) {
				$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', self::table(), $user_id ) );
				if ( $count >= $quota ) {
					return self::finish( self::error( 'quota', __( "This site's note quota has been reached. Existing notes remain available.", 'coocadev-reader-notes' ), 409 ) );
				}
			}
			$now = current_time( 'mysql', true );
			$ok  = $wpdb->insert(
				self::table(),
				array(
					'user_id'        => $user_id,
					'post_id'        => $post_id,
					'content'        => $text,
					'version'        => 1,
					'created_at_gmt' => $now,
					'updated_at_gmt' => $now,
				),
				array( '%d', '%d', '%s', '%d', '%s', '%s' )
			);
		} else {
			$ok = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET content = %s,version = version + 1,updated_at_gmt = %s WHERE id = %d AND user_id = %d AND version = %d', self::table(), $text, current_time( 'mysql', true ), (int) $old['id'], $user_id, $version ) );
		}
		if ( false === $ok || 0 === $ok ) {
			return self::finish( self::error( 'save', __( 'Your text has not been saved. Please retry.', 'coocadev-reader-notes' ), 503 ) );
		}
		$result = self::by_post( $user_id, $post_id );
		return self::finish( $result ? $result : self::error( 'save', __( 'The saved note could not be read.', 'coocadev-reader-notes' ), 503 ) );
	}

	/**
	 * Delete an owned note only at the expected version and epoch.
	 *
	 * @param int $user_id User id.
	 * @param int $note_id Note id.
	 * @param int $version Version.
	 * @param int $epoch Epoch.
	 * @return array|\WP_Error Operation result.
	 */
	public static function delete( int $user_id, int $note_id, int $version, int $epoch ): array|\WP_Error {
		global $wpdb;
		$state = self::begin( $user_id );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		$error = self::barrier( $state, $epoch );
		if ( $error ) {
			return self::finish( $error );
		}
		$note = self::one( $user_id, $note_id );
		if ( ! $note ) {
			return self::finish( self::error( 'not_found', __( 'Note not found.', 'coocadev-reader-notes' ), 404 ) );
		}
		if ( (int) $note['version'] !== $version ) {
			return self::finish( self::error( 'conflict', __( 'This note changed. Reload before deleting it.', 'coocadev-reader-notes' ), 409 ) );
		}
		$result = $wpdb->delete(
			self::table(),
			array(
				'id'      => $note_id,
				'user_id' => $user_id,
				'version' => $version,
			),
			array( '%d', '%d', '%d' )
		);
		return self::finish( 1 === $result ? array( 'deleted' => true ) : self::error( 'delete', __( 'Deletion was not confirmed.', 'coocadev-reader-notes' ), 503 ) );
	}

	/**
	 * Return a bounded private page using a stable newest-created ID cursor.
	 *
	 * @param int    $user_id User id.
	 * @param int    $before Before.
	 * @param string $search Search.
	 * @param int    $limit Limit.
	 * @return array Operation result.
	 */
	public static function listing( int $user_id, int $before = 0, string $search = '', int $limit = 20 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE user_id = %d AND (%d = 0 OR id < %d) AND content LIKE %s ORDER BY id DESC LIMIT %d',
				self::table(),
				$user_id,
				$before,
				$before,
				'%' . $wpdb->esc_like( $search ) . '%',
				min( 50, max( 1, $limit ) )
			),
			ARRAY_A
		);
	}

	/**
	 * Atomically erase account text before account removal, preserving a write barrier.
	 *
	 * @param int  $user_id Account owner.
	 * @param bool $restore Restore only a completed account-removal tombstone.
	 * @return bool|\WP_Error
	 */
	public static function account( int $user_id, bool $restore = false ): bool|\WP_Error {
		global $wpdb;
		$state = self::begin( $user_id );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		if ( $restore && ( ! $state['is_erasing'] || null !== $state['erase_operation_id'] ) ) {
			return self::finish( true );
		}
		$changed = $wpdb->update(
			self::table( 'state' ),
			array(
				'epoch'              => (int) $state['epoch'] + 1,
				'is_erasing'         => $restore ? 0 : 1,
				'erase_operation_id' => null,
			),
			array( 'user_id' => $user_id ),
			array( '%d', '%d', '%s' ),
			array( '%d' )
		);
		if ( false === $changed || ( ! $restore && false === $wpdb->delete( self::table(), array( 'user_id' => $user_id ), array( '%d' ) ) ) ) {
			return self::finish( self::error( 'account', __( 'Account notes could not be safely erased.', 'coocadev-reader-notes' ), 503 ) );
		}
		return self::finish( true );
	}

	/**
	 * Commit an epoch barrier, then erase an operation-bound batch safely.
	 *
	 * @param int         $user_id User id.
	 * @param int|null    $expected_epoch Expected epoch.
	 * @param string|null $operation Operation.
	 * @param bool        $start Start.
	 * @return array|\WP_Error Operation result.
	 */
	public static function erase( int $user_id, ?int $expected_epoch = null, ?string $operation = null, bool $start = true ): array|\WP_Error {
		global $wpdb;
		$state = self::begin( $user_id );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		if ( null !== $expected_epoch && (int) $state['epoch'] !== $expected_epoch ) {
			return self::finish( self::error( 'epoch', __( 'Your notes session changed. Reload before deleting all notes.', 'coocadev-reader-notes' ), 409 ) );
		}
		if ( $state['is_erasing'] ) {
			if ( null !== $operation && $state['erase_operation_id'] !== $operation ) {
				return self::finish( self::error( 'erase_conflict', __( 'Another erasure is in progress.', 'coocadev-reader-notes' ), 409 ) );
			}
		} elseif ( ! $start || null !== $operation ) {
			return self::finish(
				array(
					'done'      => true,
					'removed'   => 0,
					'epoch'     => (int) $state['epoch'],
					'operation' => null,
				)
			);
		} else {
			$state['epoch']              = (int) $state['epoch'] + 1;
			$state['erase_operation_id'] = wp_generate_uuid4();
			$changed                     = $wpdb->update(
				self::table( 'state' ),
				array(
					'epoch'              => $state['epoch'],
					'is_erasing'         => 1,
					'erase_operation_id' => $state['erase_operation_id'],
				),
				array( 'user_id' => $user_id ),
				array( '%d', '%d', '%s' ),
				array( '%d' )
			);
			if ( false === $changed ) {
				return self::finish( self::error( 'erase', __( 'Erasure could not start.', 'coocadev-reader-notes' ), 503 ) );
			}
		}
		// Barrier is committed before batch deletion, so retries cannot resurrect old drafts.
		$barrier = self::finish( $state );
		if ( is_wp_error( $barrier ) ) {
			return $barrier;
		}
		$locked = self::begin( $user_id );
		if ( is_wp_error( $locked ) ) {
			return $locked;
		}
		if ( $locked['erase_operation_id'] !== $state['erase_operation_id'] || ! $locked['is_erasing'] ) {
			return self::finish( self::error( 'erase_conflict', __( 'Erasure changed. Reload before retrying.', 'coocadev-reader-notes' ), 409 ) );
		}
		$removed = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d ORDER BY id LIMIT 100', self::table(), $user_id ) );
		if ( false === $removed ) {
			return self::finish( self::error( 'erase', __( 'Erasure paused. Retry to continue safely.', 'coocadev-reader-notes' ), 503 ) );
		}
		$left = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE user_id = %d LIMIT 1', self::table(), $user_id ) );
		if ( ! $left ) {
			if ( false === $wpdb->update(
				self::table( 'state' ),
				array(
					'is_erasing'         => 0,
					'erase_operation_id' => null,
				),
				array(
					'user_id'            => $user_id,
					'erase_operation_id' => $state['erase_operation_id'],
				),
				array( '%d', '%s' ),
				array( '%d', '%s' )
			) ) {
				return self::finish( self::error( 'erase', __( 'Erasure could not be confirmed. Retry safely.', 'coocadev-reader-notes' ), 503 ) );
			}
		}
		return self::finish(
			array(
				'done'      => ! $left,
				'removed'   => $removed,
				'epoch'     => (int) $state['epoch'],
				'operation' => $left ? $state['erase_operation_id'] : null,
			)
		);
	}
}
