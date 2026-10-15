<?php
/**
 * WordPress privacy exporter and author-reference erasure.
 *
 * Integrates version authorship with WordPress privacy requests.
 * Erasure removes attribution and notes, not the published document.
 * PDF attachments have their own WordPress lifecycle and may already
 * have public direct URLs or downloaded copies outside our control.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevFileGo
 */

namespace Coocadev\FileGo;

defined( 'ABSPATH' ) || exit;

/**
 * Site-scoped FileGo Privacy component.
 */
final class Privacy {
	/**
	 * Register only the hooks required by this component.
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'policy' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
		add_action( 'delete_user', array( self::class, 'delete_user' ) );
		add_action( 'wpmu_delete_user', array( self::class, 'delete_user' ) );
	}

	/**
	 * Offer accurate privacy-policy wording through WordPress.
	 */
	public static function policy(): void {
		wp_add_privacy_policy_content( 'Coocadev FileGo', '<p>' . esc_html__( 'FileGo stores public PDF versions, their creation time, optional version notes and the uploading user’s ID. PDFs and version URLs are public, including versions not selected by the permanent link. No visitor download tracking, analytics cookies or external services are used. Administrative diagnostics make a request to this site only. Privacy erasure anonymizes FileGo author references; it does not delete public documents or WordPress media records.', 'coocadev-filego' ) . '</p>' );
	}

	/**
	 * Register the standard WordPress privacy exporter.
	 *
	 * @param array $exporters Previously registered privacy exporters.
	 * @return array Operation result.
	 */
	public static function exporters( array $exporters ): array {
		$exporters['ccfg-filego'] = array(
			'exporter_friendly_name' => __( 'FileGo version authorship', 'coocadev-filego' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the standard WordPress privacy eraser.
	 *
	 * @param array $erasers Previously registered privacy erasers.
	 * @return array Operation result.
	 */
	public static function erasers( array $erasers ): array {
		$erasers['ccfg-filego'] = array(
			'eraser_friendly_name' => __( 'FileGo version authorship', 'coocadev-filego' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export one bounded page of version authorship for the requested email.
	 *
	 * @param string $email Email address authorized by the WordPress privacy workflow.
	 * @param int    $page One-based result page.
	 * @return array Operation result.
	 */
	public static function export( string $email, int $page = 1 ): array {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,document_id,note,created_at_gmt FROM %i WHERE created_by = %d ORDER BY id LIMIT 100 OFFSET %d', Store::table( 'versions' ), $user->ID, ( max( 1, $page ) - 1 ) * 100 ) );
		$data = array();
		foreach ( $rows as $row ) {
			$data[] = array(
				'group_id'    => 'ccfg-versions',
				'group_label' => __( 'FileGo versions', 'coocadev-filego' ),
				'item_id'     => 'ccfg-version-' . $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Document ID', 'coocadev-filego' ),
						'value' => (string) $row->document_id,
					),
					array(
						'name'  => __( 'Version note', 'coocadev-filego' ),
						'value' => $row->note,
					),
					array(
						'name'  => __( 'Created (UTC)', 'coocadev-filego' ),
						'value' => $row->created_at_gmt,
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => count( $rows ) < 100,
		);
	}

	/**
	 * Anonymize a bounded batch without deleting public documents or media records.
	 *
	 * @param string $email Email address authorized by the WordPress privacy workflow.
	 * @param int    $page One-based result page.
	 * @return array Operation result.
	 */
	public static function erase( string $email, int $page = 1 ): array {
		global $wpdb;
		if ( $page < 1 ) {
			return array(
				'items_removed'  => false,
				'items_retained' => true,
				'messages'       => array( __( 'The privacy batch number is invalid.', 'coocadev-filego' ) ),
				'done'           => true,
			);
		}
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}
		// Always take the first remaining batch; OFFSET would skip rows after anonymizing.
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE %i SET created_by = 0, note = '' WHERE created_by = %d LIMIT 100", Store::table( 'versions' ), $user->ID ) );
		return array(
			'items_removed'  => $changed > 0,
			'items_retained' => false === $changed,
			'messages'       => false === $changed ? array( __( 'FileGo author references could not be erased. Try again.', 'coocadev-filego' ) ) : array(),
			'done'           => false === $changed || $changed < 100,
		);
	}

	/**
	 * Remove FileGo authorship and optional notes when a local user is deleted.
	 *
	 * @param int $id Site-local numeric identifier.
	 */
	public static function delete_user( int $id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET created_by = 0, note = '' WHERE created_by = %d", Store::table( 'versions' ), $id ) );
	}
}
