<?php
/**
 * Private REST with strict typed payloads.
 *
 * Private transport adapter for strict, bounded state-change payloads.
 * A nonce is CSRF protection under Core cookie authentication; object
 * capabilities still decide access. Bulk validates the complete shape
 * first and reports per-object outcomes rather than claiming all succeed.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevDoneMark
 */

namespace Coocadev\DoneMark;

defined( 'ABSPATH' ) || exit;

/**
 * Site-scoped DoneMark Rest component.
 */
final class Rest {
	/**
	 * Register scoped WordPress hooks for this component.
	 */
	public static function register(): void {
		add_action(
			'rest_api_init',
			static function () {
				register_rest_route(
					'coocadev-donemark/v1',
					'/posts/(?P<id>[1-9][0-9]*)/marks',
					array(
						'methods'             => 'GET',
						'permission_callback' => static fn( $request ) => States::access( (int) $request['id'] ),
						'callback'            => static fn( $request ) => States::view( (int) $request['id'] ),
					)
				);
				register_rest_route(
					'coocadev-donemark/v1',
					'/posts/(?P<id>[1-9][0-9]*)/marks/(?P<mark_id>[a-f0-9-]{36})',
					array(
						'methods'             => 'PUT',
						'permission_callback' => static fn( $request ) => States::access( (int) $request['id'] ),
						'callback'            => array( self::class, 'update' ),
					)
				);
				register_rest_route(
					'coocadev-donemark/v1',
					'/bulk',
					array(
						'methods'             => 'POST',
						'permission_callback' => static fn() => is_user_logged_in(),
						'callback'            => array( self::class, 'bulk' ),
					)
				);
			}
		);
		add_filter(
			'rest_post_dispatch',
			static function ( $response, $server, $request ) {
				if ( str_starts_with( $request->get_route(), '/coocadev-donemark/v1/' ) ) {
					$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
					$response->header( 'Vary', 'Cookie, Authorization' );
				}
				return $response;
			},
			10,
			3
		);
	}

	/**
	 * Accept only a strict typed state-change payload.
	 *
	 * @param mixed $data Validated operation input or WordPress hook value.
	 * @return bool Operation result.
	 */
	private static function payload( $data ): bool {
		return is_array( $data ) && ! array_diff( array_keys( $data ), array( 'done', 'version', 'content_hash' ) ) &&
			is_bool( $data['done'] ?? null ) && is_int( $data['version'] ?? null ) && $data['version'] >= 0 &&
			is_string( $data['content_hash'] ?? null ) && (bool) preg_match( '/^[a-f0-9]{64}$/D', $data['content_hash'] );
	}

	/**
	 * Validate one REST change before invoking the shared permission-checked service.
	 *
	 * @param \WP_REST_Request $request Validated operation input or WordPress hook value.
	 */
	public static function update( \WP_REST_Request $request ) {
		$data = $request->get_json_params();
		if ( ! self::payload( $data ) ) {
			return new \WP_Error( 'ccdm_input', __( 'Send only done (boolean), version (integer) and content_hash.', 'coocadev-donemark' ), array( 'status' => 400 ) );
		}
		return States::write( (int) $request['id'], $request['mark_id'], $data['done'], $data['version'], $data['content_hash'] );
	}

	/**
	 * Validate a bounded batch and report each object’s authorized result.
	 *
	 * @param \WP_REST_Request $request Validated operation input or WordPress hook value.
	 */
	public static function bulk( \WP_REST_Request $request ) {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) || array_keys( $data ) !== array( 'items' ) || ! is_array( $data['items'] ) || ! array_is_list( $data['items'] ) || count( $data['items'] ) < 1 || count( $data['items'] ) > 100 ) {
			return new \WP_Error( 'ccdm_bulk', __( 'Send between 1 and 100 distinct post/mark changes.', 'coocadev-donemark' ), array( 'status' => 400 ) );
		}
		$seen = array();
		foreach ( $data['items'] as $item ) {
			if ( ! is_array( $item ) || array_diff( array_keys( $item ), array( 'post_id', 'mark_id', 'done', 'version', 'content_hash' ) ) ||
				! is_int( $item['post_id'] ?? null ) || $item['post_id'] < 1 || ! is_string( $item['mark_id'] ?? null ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $item['mark_id'] ) ) {
				return new \WP_Error( 'ccdm_bulk', __( 'Invalid bulk change.', 'coocadev-donemark' ), array( 'status' => 400 ) );
			}
			$change = array_intersect_key( $item, array_flip( array( 'done', 'version', 'content_hash' ) ) );
			$key    = $item['post_id'] . ':' . $item['mark_id'];
			if ( isset( $seen[ $key ] ) || ! self::payload( $change ) ) {
				return new \WP_Error( 'ccdm_bulk', __( 'Duplicate or malformed bulk change.', 'coocadev-donemark' ), array( 'status' => 400 ) );
			}
			$seen[ $key ] = true;
		}
		$results = array();
		foreach ( $data['items'] as $item ) {
			$result    = States::write( $item['post_id'], $item['mark_id'], $item['done'], $item['version'], $item['content_hash'] );
			$results[] = array(
				'post_id' => $item['post_id'],
				'mark_id' => $item['mark_id'],
				'success' => ! is_wp_error( $result ),
				'state'   => is_wp_error( $result ) ? null : $result,
				'error'   => is_wp_error( $result ) ? $result->get_error_message() : null,
			);
		}
		return array( 'results' => $results );
	}
}
