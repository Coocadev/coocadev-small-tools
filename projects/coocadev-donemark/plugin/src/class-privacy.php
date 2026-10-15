<?php
/**
 * Subject-scoped export and anonymization.
 *
 * Exports current actor attribution and anonymizes only the subject.
 * Erasure keeps the team state but invalidates stale state versions.
 * Definitions and labels are shared editorial configuration, so owners
 * must separately review labels that contain personal information.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevDoneMark
 */

namespace Coocadev\DoneMark;

defined( 'ABSPATH' ) || exit;

/**
 * Site-scoped DoneMark Privacy component.
 */
final class Privacy {
	/**
	 * Register scoped WordPress hooks for this component.
	 */
	public static function register(): void {
		add_filter(
			'wp_privacy_personal_data_exporters',
			static function ( $items ) {
				$items['coocadev-donemark'] = array(
					'exporter_friendly_name' => __( 'DoneMark editor attribution', 'coocadev-donemark' ),
					'callback'               => array( self::class, 'export' ),
				);
				return $items;
			}
		);
		add_filter(
			'wp_privacy_personal_data_erasers',
			static function ( $items ) {
				$items['coocadev-donemark'] = array(
					'eraser_friendly_name' => __( 'DoneMark editor attribution', 'coocadev-donemark' ),
					'callback'             => array( self::class, 'erase' ),
				);
				return $items;
			}
		);
		add_action( 'delete_user', array( self::class, 'anonymize' ) );
		add_action( 'wpmu_delete_user', array( self::class, 'anonymize' ) );
		add_action(
			'admin_init',
			static function () {
				wp_add_privacy_policy_content( 'Coocadev DoneMark', __( 'DoneMark stores task definitions, post IDs, current checkmark states, the last editor’s user ID and UTC time. Content reviews store a one-way fingerprint of the title, body and excerpt, not a copy of the article. No information is sent to Coocadev. Privacy erasure removes editor attribution while retaining team task states. Avoid personal data in mark labels; labels need manual review.', 'coocadev-donemark' ) );
			}
		);
	}

	/**
	 * Export one bounded page of the requested subject’s current attribution.
	 *
	 * @param string $email Validated operation input or WordPress hook value.
	 * @param int    $page Validated operation input or WordPress hook value.
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Subject-scoped privacy export, bounded page.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT post_id,mark_id,is_done,changed_at_gmt FROM %i WHERE actor_user_id=%d ORDER BY post_id,mark_id LIMIT 100 OFFSET %d', $wpdb->prefix . 'ccdm_states', $user->ID, ( max( 1, $page ) - 1 ) * 100 ), ARRAY_A );
		$data = array();
		foreach ( $rows as $row ) {
			$data[] = array(
				'group_id'    => 'coocadev-donemark',
				'group_label' => __( 'DoneMark attribution', 'coocadev-donemark' ),
				'item_id'     => 'ccdm-' . $row['post_id'] . '-' . $row['mark_id'],
				'data'        => array(
					array(
						'name'  => __( 'Post ID', 'coocadev-donemark' ),
						'value' => $row['post_id'],
					),
					array(
						'name'  => __( 'Mark ID', 'coocadev-donemark' ),
						'value' => $row['mark_id'],
					),
					array(
						'name'  => __( 'Done', 'coocadev-donemark' ),
						'value' => $row['is_done'],
					),
					array(
						'name'  => __( 'Changed at (UTC)', 'coocadev-donemark' ),
						'value' => $row['changed_at_gmt'],
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
	 * Remove this user’s attribution and invalidate old state versions.
	 *
	 * @param int $id Validated operation input or WordPress hook value.
	 */
	public static function anonymize( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Remove only this subject's attribution.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET actor_user_id = NULL, version = version + 1 WHERE actor_user_id = %d', $wpdb->prefix . 'ccdm_states', $id ) );
	}

	/**
	 * Anonymize at most 100 subject-owned rows without deleting editorial states.
	 *
	 * @param string $email Validated operation input or WordPress hook value.
	 * @param int    $page Validated operation input or WordPress hook value.
	 * @return array Operation result.
	 */
	public static function erase( string $email, int $page = 1 ): array {
		global $wpdb;
		$user    = get_user_by( 'email', $email );
		$changed = $user ? $wpdb->query( $wpdb->prepare( 'UPDATE %i SET actor_user_id = NULL, version = version + 1 WHERE actor_user_id = %d ORDER BY post_id, mark_id LIMIT 100', $wpdb->prefix . 'ccdm_states', $user->ID ) ) : 0;
		if ( false === $changed ) {
			return array(
				'items_removed'  => false,
				'items_retained' => true,
				'messages'       => array( __( 'DoneMark could not anonymize attribution. Please retry the erasure.', 'coocadev-donemark' ) ),
				'done'           => false,
			);
		}
		return array(
			'items_removed'  => $changed > 0,
			'items_retained' => false,
			'messages'       => array( __( 'Editor attribution is anonymized. Team states remain; personal data in custom mark labels requires manual review.', 'coocadev-donemark' ) ),
			'done'           => $changed < 100,
		);
	}
}
