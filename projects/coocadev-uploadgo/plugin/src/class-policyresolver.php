<?php
/**
 * Restrictive policy intersection, with no role-order dependency.
 *
 * Combines the global policy with every matching authenticated role.
 * Numeric bounds use minima and enabled family lists use intersection.
 * No role, including administrator, widens another applicable rule.
 * Background work is separately opted in and uses the global rule only.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevUploadGo
 */

namespace Coocadev\UploadGo;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless policy intersection for the current site and user.
 */
final class PolicyResolver {
	/**
	 * Map explicit file families to their allowed MIME types.
	 *
	 * @return array Operation result.
	 */
	public static function families(): array {
		return array(
			'images'    => array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence', 'image/bmp', 'image/tiff', 'image/x-icon' ),
			'pdf'       => array( 'application/pdf' ),
			'audio'     => array( 'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/mp4', 'audio/flac', 'audio/x-m4a' ),
			'video'     => array( 'video/mp4', 'video/webm', 'video/ogg', 'video/quicktime', 'video/x-msvideo' ),
			'documents' => array( 'text/plain', 'text/csv', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/vnd.oasis.opendocument.text', 'application/vnd.oasis.opendocument.spreadsheet' ),
		);
	}

	/**
	 * Intersect global and authenticated role policies using the strictest limits.
	 *
	 * @param array $settings Settings.
	 * @param array $roles Roles.
	 * @param bool  $authenticated Authenticated.
	 * @return ?array Operation result.
	 */
	public static function resolve( array $settings, array $roles, bool $authenticated ): ?array {
		if ( ! $settings['enabled'] || ( ! $authenticated && ! $settings['background_mode'] ) ) {
			return null;
		}
		$policies = array( $settings['global_policy'] );
		if ( $authenticated ) {
			foreach ( $roles as $role ) {
				if ( isset( $settings['role_policies'][ $role ] ) ) {
					$policies[] = $settings['role_policies'][ $role ];
				}
			}
		}
		$result = PolicyRepository::empty_policy();
		foreach ( $policies as $policy ) {
			foreach ( array( 'max_bytes', 'max_width', 'max_height', 'max_pixels' ) as $key ) {
				if ( null !== $policy[ $key ] ) {
					$result[ $key ] = null === $result[ $key ] ? $policy[ $key ] : min( $result[ $key ], $policy[ $key ] );
				}
			}
			if ( $policy['types_enabled'] ) {
				$result['types']         = $result['types_enabled'] ? array_values( array_intersect( $result['types'], $policy['types'] ) ) : $policy['types'];
				$result['types_enabled'] = true;
			}
		}
		return $result;
	}

	/**
	 * Resolve policy for the actual current site and authenticated user.
	 *
	 * @return ?array Operation result.
	 */
	public static function current(): ?array {
		$user = wp_get_current_user();
		return self::resolve( PolicyRepository::read(), $user->roles, $user->exists() );
	}

	/**
	 * Apply permanent executable-type exclusions and configured family intersections.
	 *
	 * @param string $mime Mime.
	 * @param array  $policy Policy.
	 * @return bool Operation result.
	 */
	public static function allows_mime( string $mime, array $policy ): bool {
		// These types can never be enabled by UploadGo, including for unfiltered_upload users.
		if ( in_array( $mime, array( 'image/svg+xml', 'text/html', 'application/xhtml+xml', 'application/x-httpd-php', 'text/x-php', 'application/javascript', 'text/javascript' ), true ) ) {
			return false;
		}
		if ( ! $policy['types_enabled'] ) {
			return true;
		}
		foreach ( $policy['types'] as $family ) {
			if ( in_array( $mime, self::families()[ $family ], true ) ) {
				return true;
			}
		}
		return false;
	}
}
