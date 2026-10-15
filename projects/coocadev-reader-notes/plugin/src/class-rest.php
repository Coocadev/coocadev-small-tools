<?php
/**
 * Private endpoints. WordPress performs REST authentication before permissions.
 *
 * Private transport boundary; WordPress authenticates before permissions.
 * Owners come only from the current session, never a request parameter.
 * Cookie-authenticated REST relies on the Core REST nonce mechanism.
 * No-store covers both success and error responses in this namespace.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevReaderNotes
 */

namespace Coocadev\ReaderNotes;

defined( 'ABSPATH' ) || exit;

/**
 * Rest module for private reader notes.
 */
final class Rest {
	const NS = 'coocadev-reader-notes/v1';

	/**
	 * Register private REST routes with shared authentication permissions.
	 *
	 * @return void Operation result.
	 */
	public static function register(): void {
		$routes = array(
			'/notes'                               => array( 'GET', 'listing' ),
			'/notes/(?P<id>[1-9][0-9]*)'           => array( 'GET', 'one' ),
			'/posts/(?P<post_id>[1-9][0-9]*)/note' => array( 'GET', 'by_post' ),
			'/export'                              => array( 'POST', 'export' ),
			'/erase'                               => array( 'POST', 'erase' ),
		);
		foreach ( $routes as $route => $definition ) {
			register_rest_route(
				self::NS,
				$route,
				array(
					'methods'             => $definition[0],
					'callback'            => array( self::class, $definition[1] ),
					'permission_callback' => array( self::class, 'permission' ),
				)
			);
		}
		register_rest_route(
			self::NS,
			'/posts/(?P<post_id>[1-9][0-9]*)/note',
			array(
				'methods'             => 'PUT',
				'callback'            => array( self::class, 'save' ),
				'permission_callback' => array( self::class, 'permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/notes/(?P<id>[1-9][0-9]*)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( self::class, 'delete' ),
				'permission_callback' => array( self::class, 'permission' ),
			)
		);
	}

	/**
	 * Require an authenticated site member and reject client-selected owners.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error Operation result.
	 */
	public static function permission( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! Access::member( get_current_user_id() ) ) {
			return Database::error( 'authentication', __( 'Sign in to access your notes.', 'coocadev-reader-notes' ), 401 );
		}
		if ( ! Access::same_origin() ) {
			return Database::error( 'origin', __( 'This request must come from the same site.', 'coocadev-reader-notes' ), 403 );
		}
		foreach ( array( 'user_id', 'author_id', 'owner' ) as $key ) {
			if ( $request->has_param( $key ) ) {
				return Database::error( 'owner', __( 'The note owner cannot be supplied in a request.', 'coocadev-reader-notes' ) );
			}
		}
		return true;
	}

	/**
	 * Apply no-store headers to private successes and errors.
	 *
	 * @param mixed            $response Response.
	 * @param \WP_REST_Server  $server Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed Operation result.
	 */
	public static function headers( mixed $response, \WP_REST_Server $server, \WP_REST_Request $request ): mixed {
		if ( str_starts_with( $request->get_route(), '/' . self::NS . '/' ) ) {
			$response = rest_ensure_response( $response );
			$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
			$response->header( 'Vary', 'Cookie' );
			$response->header( 'Pragma', 'no-cache' );
			$response->header( 'X-Robots-Tag', 'noindex, nofollow' );
		}
		return $response;
	}

	/**
	 * Prevent shared caching and CORS disclosure of personal responses.
	 *
	 * @return void Operation result.
	 */
	public static function no_cache(): void {
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'Vary: Cookie', false );
		header_remove( 'Access-Control-Allow-Origin' );
		header_remove( 'Access-Control-Allow-Credentials' );
		header( 'X-Robots-Tag: noindex, nofollow' );
	}

	/**
	 * Deliver only session state and a REST nonce; never return note text.
	 *
	 * @return void Operation result.
	 */
	public static function bootstrap(): void {
		self::no_cache();
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'get' !== $method || ! Access::same_origin() ) {
			wp_send_json_error( array( 'message' => __( 'This request must come from the same site.', 'coocadev-reader-notes' ) ), 403 );
		}
		if ( ! Access::member( get_current_user_id() ) ) {
			wp_send_json_error( array( 'message' => __( 'Sign in to access your notes.', 'coocadev-reader-notes' ) ), 401 );
		}
		wp_send_json_success(
			array_merge(
				Database::state( get_current_user_id() ),
				array(
					'user'     => get_current_user_id(),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'writable' => Database::healthy(),
				)
			)
		);
	}

	/**
	 * Accept a bounded JSON object containing only allowlisted fields.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param array            $fields Fields.
	 * @return array|\WP_Error Operation result.
	 */
	private static function body( \WP_REST_Request $request, array $fields ): array|\WP_Error {
		if ( strlen( $request->get_body() ) > 262144 || ! $request->is_json_content_type() ) {
			return Database::error( 'body', __( 'Send a bounded JSON request.', 'coocadev-reader-notes' ), 413 );
		}
		$data = $request->get_json_params();
		if ( ! is_array( $data ) || array_diff( array_keys( $data ), $fields ) ) {
			return Database::error( 'fields', __( 'The request contains unknown or invalid fields.', 'coocadev-reader-notes' ) );
		}
		return $data;
	}

	/**
	 * Validate a real JSON integer without coercing arrays or strings.
	 *
	 * @param mixed $value Value.
	 * @param int   $minimum Minimum.
	 * @return bool Operation result.
	 */
	public static function integer( mixed $value, int $minimum = 0 ): bool {
		return is_int( $value ) && $value >= $minimum && $value <= PHP_INT_MAX;
	}

	/**
	 * Validate a bounded non-negative cursor from a query string.
	 *
	 * @param mixed $value Value.
	 * @return int|\WP_Error Operation result.
	 */
	private static function query_int( mixed $value ): int|\WP_Error {
		if ( null === $value || '' === $value ) {
			return 0;
		}
		if ( ! is_scalar( $value ) || ! preg_match( '/^(0|[1-9][0-9]{0,17})$/', (string) $value ) ) {
			return Database::error( 'cursor', __( 'Invalid pagination cursor.', 'coocadev-reader-notes' ) );
		}
		return (int) $value;
	}

	/**
	 * Prime source objects in a batch and apply reader-specific visibility.
	 *
	 * @param array $rows Rows.
	 * @param int   $user_id User id.
	 * @return array Operation result.
	 */
	private static function present( array $rows, int $user_id ): array {
		$ids = array_filter( array_map( static fn( array $row ): int => (int) $row['post_id'], $rows ) );
		if ( $ids ) {
			_prime_post_caches( $ids, false, false );
		}
		return array_map( static fn( array $row ): array => Access::public_note( $row, $user_id ), $rows );
	}

	/**
	 * Return a bounded private page using a stable newest-created ID cursor.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error Operation result.
	 */
	public static function listing( \WP_REST_Request $request ): array|\WP_Error {
		$before = self::query_int( $request->get_param( 'before' ) );
		$search = $request->get_param( 'search' ) ?? '';
		if ( is_wp_error( $before ) ) {
			return $before;
		}
		if ( ! is_string( $search ) || strlen( $search ) > 400 || ! preg_match( '//u', $search ) ) {
			return Database::error( 'search', __( 'Search must be text of at most 400 bytes.', 'coocadev-reader-notes' ) );
		}
		$rows = Database::listing( get_current_user_id(), $before, $search, 20 );
		return array(
			'notes' => self::present( $rows, get_current_user_id() ),
			'next'  => count( $rows ) === 20 ? (int) end( $rows )['id'] : null,
		);
	}

	/**
	 * Read the current owner's note bound to one source article.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error Operation result.
	 */
	public static function by_post( \WP_REST_Request $request ): array|\WP_Error {
		$post_id = (int) $request['post_id'];
		if ( ! Access::post( $post_id, get_current_user_id() ) ) {
			return Database::error( 'source', __( 'This source is not available.', 'coocadev-reader-notes' ), 403 );
		}
		$note = Database::by_post( get_current_user_id(), $post_id );
		return array( 'note' => $note ? Access::public_note( $note, get_current_user_id() ) : null );
	}

	/**
	 * Read one note only when its owner matches the authenticated subject.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error Operation result.
	 */
	public static function one( \WP_REST_Request $request ): array|\WP_Error {
		$note = Database::one( get_current_user_id(), (int) $request['id'] );
		return $note ? array( 'note' => Access::public_note( $note, get_current_user_id() ) ) : Database::error( 'not_found', __( 'Note not found.', 'coocadev-reader-notes' ), 404 );
	}

	/**
	 * Create or compare-and-swap a note under the owner's epoch lock.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error Operation result.
	 */
	public static function save( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$data = self::body( $request, array( 'content', 'version', 'epoch' ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! is_string( $data['content'] ?? null ) || ! self::integer( $data['version'] ?? null ) || ! self::integer( $data['epoch'] ?? null, 1 ) ) {
			return Database::error( 'fields', __( 'Text, version and epoch are required with their correct types.', 'coocadev-reader-notes' ) );
		}
		$note = Database::save( get_current_user_id(), (int) $request['post_id'], $data['content'], $data['version'], $data['epoch'] );
		return is_wp_error( $note ) ? $note : new \WP_REST_Response( array( 'note' => Access::public_note( $note, get_current_user_id() ) ), 0 === $data['version'] ? 201 : 200 );
	}

	/**
	 * Delete an owned note only at the expected version and epoch.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error Operation result.
	 */
	public static function delete( \WP_REST_Request $request ): array|\WP_Error {
		$data = self::body( $request, array( 'version', 'epoch' ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! self::integer( $data['version'] ?? null, 1 ) || ! self::integer( $data['epoch'] ?? null, 1 ) ) {
			return Database::error( 'fields', __( 'Version and epoch are required.', 'coocadev-reader-notes' ) );
		}
		return Database::delete( get_current_user_id(), (int) $request['id'], $data['version'], $data['epoch'] );
	}

	/**
	 * Return one bounded page of owned notes for a client-side text export.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error Operation result.
	 */
	public static function export( \WP_REST_Request $request ): array|\WP_Error {
		$data = self::body( $request, array( 'before', 'id' ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( isset( $data['id'] ) ) {
			if ( ! self::integer( $data['id'], 1 ) ) {
				return Database::error( 'fields', __( 'Invalid note ID.', 'coocadev-reader-notes' ) );
			}
			$note = Database::one( get_current_user_id(), $data['id'] );
			return $note ? array(
				'notes' => self::present( array( $note ), get_current_user_id() ),
				'next'  => null,
			) : Database::error( 'not_found', __( 'Note not found.', 'coocadev-reader-notes' ), 404 );
		}
		$before = $data['before'] ?? 0;
		if ( ! self::integer( $before ) ) {
			return Database::error( 'fields', __( 'Invalid export cursor.', 'coocadev-reader-notes' ) );
		}
		$rows = Database::listing( get_current_user_id(), $before, '', 50 );
		return array(
			'notes' => self::present( $rows, get_current_user_id() ),
			'next'  => count( $rows ) === 50 ? (int) end( $rows )['id'] : null,
		);
	}

	/**
	 * Commit an epoch barrier, then erase an operation-bound batch safely.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error Operation result.
	 */
	public static function erase( \WP_REST_Request $request ): array|\WP_Error {
		$data = self::body( $request, array( 'epoch', 'operation' ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$operation = $data['operation'] ?? null;
		if ( ! self::integer( $data['epoch'] ?? null, 1 ) || ( null !== $operation && ( ! is_string( $operation ) || ! wp_is_uuid( $operation ) ) ) ) {
			return Database::error( 'fields', __( 'Invalid erasure state.', 'coocadev-reader-notes' ) );
		}
		return Database::erase( get_current_user_id(), $data['epoch'], $operation );
	}
}
