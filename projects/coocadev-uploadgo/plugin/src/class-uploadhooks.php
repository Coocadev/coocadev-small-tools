<?php
/**
 * Core upload integration and local diagnostics.
 *
 * Adapts one policy/inspector pair to supported native upload paths.
 * Existing Core failures remain failures and this layer never grants
 * upload capability. Finalize is a second trust boundary: permissions
 * and policy may have changed since the original file was accepted.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevUploadGo
 */

namespace Coocadev\UploadGo;

defined( 'ABSPATH' ) || exit;

/**
 * Policy adapters for authenticated Core uploads and optional background work.
 */
final class UploadHooks {
	/**
	 * Attach the same server policy to the supported Core upload paths.
	 *
	 * @return void Operation result.
	 */
	public static function register(): void {
		add_filter( 'wp_handle_upload_prefilter', array( self::class, 'upload' ), 99 );
		add_filter( 'wp_handle_sideload_prefilter', array( self::class, 'sideload' ), 99 );
		add_filter( 'upload_mimes', array( self::class, 'mimes' ), 99 );
		add_filter( 'upload_size_limit', array( self::class, 'size' ), 99 );
		add_filter( 'rest_dispatch_request', array( self::class, 'finalize' ), 10, 2 );
		add_action( 'ccug_prune_diagnostics', array( self::class, 'prune_diagnostics' ) );
	}

	/**
	 * Reinspect attachment-owned files and normalize their metadata before Core finalize.
	 *
	 * @param mixed            $response Response.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed Operation result.
	 */
	public static function finalize( $response, $request ) {
		if ( null !== $response || 'POST' !== $request->get_method() || ! preg_match( '#^/wp/v2/media/([0-9]+)/finalize$#D', $request->get_route(), $match ) ) {
			return $response;
		}
		$policy = PolicyResolver::current();
		if ( null === $policy ) {
			return $response;
		}
		$id = (int) $match[1];
		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $id ) ) {
			return new \WP_Error( 'ccug_forbidden', __( 'You can no longer finalize this upload.', 'coocadev-uploadgo' ), array( 'status' => 403 ) );
		}
		$attachment = get_post( $id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new \WP_Error( 'ccug_attachment', __( 'The attachment was not found.', 'coocadev-uploadgo' ), array( 'status' => 404 ) );
		}
		$uploads   = wp_get_upload_dir();
		$root      = realpath( $uploads['basedir'] );
		$main      = get_attached_file( $id, true );
		$main_real = is_string( $main ) ? realpath( $main ) : false;
		if ( false === $root || false === $main_real || ! str_starts_with( wp_normalize_path( $main_real ), trailingslashit( wp_normalize_path( $root ) ) ) ) {
			return new \WP_Error( 'ccug_storage', __( 'The attachment must be stored in this site’s uploads directory.', 'coocadev-uploadgo' ), array( 'status' => 400 ) );
		}
		$allowed   = get_post_meta( $id, '_wp_sideloaded_file', false );
		$allowed[] = wp_basename( $main );
		$stored    = wp_get_attachment_metadata( $id, true );
		$collect   = static function ( $value ) use ( &$allowed ) {
			if ( is_string( $value ) && '' !== $value ) {
				$allowed[] = wp_basename( $value );
				$allowed[] = $value;
			}
		};
		if ( is_array( $stored ) ) {
			foreach ( array( 'file', 'original_image', 'source_image', 'animated_video', 'animated_video_poster' ) as $key ) {
				$collect( $stored[ $key ] ?? null );
			}
			foreach ( $stored['sizes'] ?? array() as $size ) {
				$collect( is_array( $size ) ? ( $size['file'] ?? null ) : null );
			}
		}
		$files   = array( $main_real );
		$entries = $request->get_param( 'sub_sizes' ) ?? array();
		if ( ! is_array( $entries ) || count( $entries ) > 200 ) {
			return new \WP_Error( 'ccug_entries', __( 'Too many or invalid image files.', 'coocadev-uploadgo' ), array( 'status' => 400 ) );
		}
		$entry_files = array();
		foreach ( $entries as $index => $entry ) {
			foreach ( array( 'file', 'original_image' ) as $key ) {
				$name = $entry[ $key ] ?? '';
				if ( '' === $name ) {
					continue;
				}
				if ( ! is_string( $name ) || ! in_array( $name, $allowed, true ) || str_contains( $name, '..' ) || str_contains( $name, ':' ) || str_contains( $name, '\\' ) ) {
					return new \WP_Error( 'ccug_provenance', __( 'This file does not belong to the attachment.', 'coocadev-uploadgo' ), array( 'status' => 400 ) );
				}
				$path = realpath( dirname( $main_real ) . '/' . wp_basename( $name ) );
				if ( false === $path || dirname( $path ) !== dirname( $main_real ) ) {
					return new \WP_Error( 'ccug_storage', __( 'An image file could not be verified.', 'coocadev-uploadgo' ), array( 'status' => 400 ) );
				}
				$files[] = $path;
				if ( 'file' === $key ) {
					$entry_files[ $index ] = $path;
				}
			}
		}
		foreach ( array_unique( $files ) as $file ) {
			$result = FileInspector::inspect(
				array(
					'tmp_name' => $file,
					'name'     => wp_basename( $file ),
				),
				$policy
			);
			if ( is_wp_error( $result ) ) {
				$result->add_data( array( 'status' => 400 ) );
				self::record( $result->get_error_code(), 'finalize' );
				return $result;
			}
		}
		// Core persists these fields. Replace client assertions with the inspected file's facts.
		foreach ( $entry_files as $index => $file ) {
			$type                           = wp_check_filetype( wp_basename( $file ), get_allowed_mime_types() );
			$entries[ $index ]['filesize']  = filesize( $file );
			$entries[ $index ]['mime_type'] = $type['type'];
			if ( str_starts_with( (string) $type['type'], 'image/' ) ) {
				$dimensions = wp_getimagesize( $file );
				if ( ! is_array( $dimensions ) || $dimensions[0] < 1 || $dimensions[1] < 1 ) {
					return new \WP_Error( 'ccug_metadata', __( 'The final image metadata could not be verified. Convert the image to JPEG, PNG or WebP.', 'coocadev-uploadgo' ), array( 'status' => 400 ) );
				}
				$entries[ $index ]['width']  = (int) $dimensions[0];
				$entries[ $index ]['height'] = (int) $dimensions[1];
			} else {
				// Width/height have no meaning for a non-image companion in this policy.
				unset( $entries[ $index ]['width'], $entries[ $index ]['height'] );
			}
		}
		$request->set_param( 'sub_sizes', $entries );
		return $response;
	}

	/**
	 * Apply policy to a native multipart upload without replacing upstream errors.
	 *
	 * @param array $file File.
	 * @return array Operation result.
	 */
	public static function upload( array $file ): array {
		return self::check( $file, 'upload' );
	}

	/**
	 * Apply policy to the native sideload path using the actual current user.
	 *
	 * @param array $file File.
	 * @return array Operation result.
	 */
	public static function sideload( array $file ): array {
		return self::check( $file, 'sideload' );
	}

	/**
	 * Preserve upstream failures and report a policy violation without deleting files.
	 *
	 * @param array  $file File.
	 * @param string $channel Channel.
	 * @return array Operation result.
	 */
	private static function check( array $file, string $channel ): array {
		if ( ! empty( $file['error'] ) ) {
			return $file;
		}
		$policy = PolicyResolver::current();
		if ( null === $policy ) {
			return $file;
		}
		$result = FileInspector::inspect( $file, $policy );
		if ( is_wp_error( $result ) ) {
			$file['error'] = $result->get_error_message();
			self::record( $result->get_error_code(), $channel );
		}
		return $file;
	}

	/**
	 * Intersect the incoming Core MIME allowlist with effective policy.
	 *
	 * @param array $mimes Mimes.
	 * @return array Operation result.
	 */
	public static function mimes( array $mimes ): array {
		$policy = PolicyResolver::current();
		if ( null !== $policy ) {
			$mimes = array_filter( $mimes, static fn( $mime ) => PolicyResolver::allows_mime( $mime, $policy ) );
		}
		return $mimes;
	}

	/**
	 * Only lower the incoming Core upload size limit.
	 *
	 * @param int $size Size.
	 * @return int Operation result.
	 */
	public static function size( $size ) {
		$policy = PolicyResolver::current();
		return null !== $policy && null !== $policy['max_bytes'] ? min( $size, $policy['max_bytes'] ) : $size;
	}

	/**
	 * Append a bounded local reason-code event when diagnostics are opted in.
	 *
	 * @param string $code Code.
	 * @param string $channel Channel.
	 * @return void Operation result.
	 */
	public static function record( string $code, string $channel ): void {
		if ( ! PolicyRepository::read()['diagnostics_enabled'] ) {
			return;
		}
		$events   = get_option( 'ccug_diagnostics', array() );
		$events   = is_array( $events ) ? $events : array();
		$events   = array_filter( $events, static fn( $event ) => is_array( $event ) && ( $event['time'] ?? 0 ) > time() - DAY_IN_SECONDS );
		$events[] = array(
			'time'    => time(),
			'code'    => sanitize_key( $code ),
			'channel' => sanitize_key( $channel ),
		);
		update_option( 'ccug_diagnostics', array_slice( array_values( $events ), -100 ), false );
		if ( ! wp_next_scheduled( 'ccug_prune_diagnostics' ) ) {
			wp_schedule_single_event( time() + DAY_IN_SECONDS, 'ccug_prune_diagnostics' );
		}
	}

	/**
	 * Physically remove expired diagnostics when scheduled cleanup runs.
	 *
	 * @return void Operation result.
	 */
	public static function prune_diagnostics(): void {
		$events = get_option( 'ccug_diagnostics', array() );
		$events = is_array( $events ) ? array_values( array_filter( $events, static fn( $event ) => is_array( $event ) && ( $event['time'] ?? 0 ) > time() - DAY_IN_SECONDS ) ) : array();
		if ( ! $events ) {
			delete_option( 'ccug_diagnostics' );
			return;
		}
		update_option( 'ccug_diagnostics', array_slice( $events, -100 ), false );
		if ( ! wp_next_scheduled( 'ccug_prune_diagnostics' ) ) {
			wp_schedule_single_event( max( time() + 1, min( array_column( $events, 'time' ) ) + DAY_IN_SECONDS ), 'ccug_prune_diagnostics' );
		}
	}
}
