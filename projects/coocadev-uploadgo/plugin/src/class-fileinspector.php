<?php
/**
 * Bounded file metadata inspection; never trusts submitted size or MIME.
 *
 * Validates local file facts rather than browser-submitted metadata.
 * Header inspection is bounded and does not decode whole image pixels.
 * EXIF orientation affects displayed dimensions; unsupported or ambiguous
 * image headers fail when the configured dimensions cannot be verified.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevUploadGo
 */

namespace Coocadev\UploadGo;

defined( 'ABSPATH' ) || exit;

/**
 * Conservative local file inspection before native Core processing.
 */
final class FileInspector {
	/**
	 * Validate real upload bytes, type and bounded image dimensions against the effective policy.
	 *
	 * @param array $file File.
	 * @param array $policy Policy.
	 * @return bool|\WP_Error Operation result.
	 */
	public static function inspect( array $file, array $policy ) {
		$path = $file['tmp_name'] ?? null;
		$name = $file['name'] ?? null;
		if ( ! is_string( $path ) || ! is_string( $name ) || str_contains( $path, '://' ) || ! is_file( $path ) || is_link( $path ) || ! is_readable( $path ) ) {
			return new \WP_Error( 'ccug_file', __( 'The temporary upload cannot be inspected.', 'coocadev-uploadgo' ) );
		}
		clearstatcache( true, $path );
		$bytes = filesize( $path );
		if ( false === $bytes || $bytes < 1 ) {
			return new \WP_Error( 'ccug_empty', __( 'The uploaded file is empty or unreadable.', 'coocadev-uploadgo' ) );
		}
		if ( null !== $policy['max_bytes'] && $bytes > $policy['max_bytes'] ) {
			return new \WP_Error(
				'ccug_size',
				/* translators: 1: actual size, 2: maximum size. */
				sprintf( __( 'This file is %1$s. Your upload limit is %2$s.', 'coocadev-uploadgo' ), size_format( $bytes, 2 ), size_format( $policy['max_bytes'], 2 ) )
			);
		}
		$allowed  = get_allowed_mime_types();
		$declared = wp_check_filetype( $name, $allowed );
		if ( ! $declared['type'] || ! PolicyResolver::allows_mime( $declared['type'], $policy ) ) {
			return new \WP_Error( 'ccug_type', __( 'This file type is not allowed by your upload policy.', 'coocadev-uploadgo' ) );
		}
		// Core validates images and corrects misleading extensions. A correction is a rejection here.
		$checked = wp_check_filetype_and_ext( $path, $name, $allowed );
		if ( ! $checked['type'] || ! $checked['ext'] || ! empty( $checked['proper_filename'] ) || $checked['type'] !== $declared['type'] ) {
			return new \WP_Error( 'ccug_mismatch', __( 'The file contents do not match its extension.', 'coocadev-uploadgo' ) );
		}
		if ( ! class_exists( '\finfo' ) ) {
			return new \WP_Error( 'ccug_fileinfo', __( 'The server needs PHP Fileinfo to verify upload types. Contact the site administrator.', 'coocadev-uploadgo' ) );
		}
		$actual = ( new \finfo( FILEINFO_MIME_TYPE ) )->file( $path );
		if ( ! is_string( $actual ) || '' === $actual ) {
			return new \WP_Error( 'ccug_fileinfo', __( 'The uploaded file type could not be verified.', 'coocadev-uploadgo' ) );
		}
		// PDF and raster image signatures must agree; containers remain subject to core validation.
		if ( ( 'application/pdf' === $declared['type'] && 'application/pdf' !== $actual ) ||
			( str_starts_with( $declared['type'], 'image/' ) && ! str_starts_with( (string) $actual, 'image/' ) && ! in_array( $declared['type'], array( 'image/heic', 'image/heif', 'image/avif' ), true ) ) ||
			( str_starts_with( (string) $actual, 'image/' ) && ! str_starts_with( $declared['type'], 'image/' ) ) ) {
			return new \WP_Error( 'ccug_mismatch', __( 'The file contents do not match its extension.', 'coocadev-uploadgo' ) );
		}
		$has_image_limits = null !== $policy['max_width'] || null !== $policy['max_height'] || null !== $policy['max_pixels'];
		if ( ! $has_image_limits || ! str_starts_with( $declared['type'], 'image/' ) ) {
			return true;
		}
		$header_formats = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp', 'image/tiff', 'image/x-icon' );
		if ( ! in_array( $declared['type'], $header_formats, true ) ) {
			return new \WP_Error( 'ccug_dimensions', __( 'Dimensions cannot be safely checked for this format. Convert it to JPEG, PNG or WebP before uploading.', 'coocadev-uploadgo' ) );
		}
		$dimensions = wp_getimagesize( $path );
		if ( ! is_array( $dimensions ) || $dimensions[0] < 1 || $dimensions[1] < 1 ) {
			return new \WP_Error( 'ccug_dimensions', __( 'The image dimensions could not be verified.', 'coocadev-uploadgo' ) );
		}
		list( $width, $height ) = $dimensions;
		// Read only bounded JPEG headers; works even when PHP EXIF is unavailable.
		if ( 'image/jpeg' === $declared['type'] ) {
			$orientation = self::jpeg_orientation( $path );
			if ( null === $orientation ) {
				return new \WP_Error( 'ccug_orientation', __( 'The image orientation could not be safely verified. Re-export it as JPEG or PNG.', 'coocadev-uploadgo' ) );
			}
			if ( in_array( $orientation, array( 5, 6, 7, 8 ), true ) ) {
				list( $width, $height ) = array( $height, $width );
			}
		}
		if ( ( null !== $policy['max_width'] && $width > $policy['max_width'] ) ||
			( null !== $policy['max_height'] && $height > $policy['max_height'] ) ||
			( null !== $policy['max_pixels'] && $width > intdiv( $policy['max_pixels'], $height ) ) ) {
			return new \WP_Error(
				'ccug_dimensions',
				/* translators: 1: image width, 2: image height, 3: maximum width, 4: maximum height, 5: maximum pixels. */
				sprintf( __( 'This image is %1$d × %2$d px. Your limits are width %3$s, height %4$s, pixels %5$s.', 'coocadev-uploadgo' ), $width, $height, $policy['max_width'] ?? '—', $policy['max_height'] ?? '—', $policy['max_pixels'] ?? '—' )
			);
		}
		return true;
	}

	/**
	 * Read a bounded APP1/IFD0 orientation without decoding pixels or depending on EXIF.
	 *
	 * @param string $path Local validated JPEG path.
	 * @return int|null Orientation, or null if headers cannot be safely interpreted.
	 */
	private static function jpeg_orientation( string $path ): ?int {
		$stream = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Read-only bounded JPEG header stream; WP_Filesystem has no seekable stream API and reading whole uploads is unsafe.
		if ( false === $stream ) {
			return null;
		}
		try {
			$found_orientation = null;
			$found_exif        = false;
			if ( "\xFF\xD8" !== fread( $stream, 2 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read-only bounded JPEG header stream; WP_Filesystem has no seekable stream API and reading whole uploads is unsafe.
				return null;
			}
			while ( ! feof( $stream ) && ftell( $stream ) < 524288 ) {
				$marker = fread( $stream, 2 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read-only bounded JPEG header stream; WP_Filesystem has no seekable stream API and reading whole uploads is unsafe.
				if ( strlen( $marker ) !== 2 || "\xFF" !== $marker[0] ) {
					return null;
				}
				if ( in_array( ord( $marker[1] ), array( 0xDA, 0xD9 ), true ) ) {
					return $found_orientation ?? 1;
				}
				$length_bytes = fread( $stream, 2 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read-only bounded JPEG header stream; WP_Filesystem has no seekable stream API and reading whole uploads is unsafe.
				if ( strlen( $length_bytes ) !== 2 ) {
					return null;
				}
				$length = unpack( 'n', $length_bytes )[1] - 2;
				if ( $length < 0 ) {
					return null;
				}
				if ( "\xE1" !== $marker[1] ) {
					if ( 0 !== fseek( $stream, $length, SEEK_CUR ) ) {
						return null;
					}
					continue;
				}
				$segment = $length > 0 ? fread( $stream, $length ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read-only bounded JPEG header stream; WP_Filesystem has no seekable stream API and reading whole uploads is unsafe.
				if ( ! str_starts_with( $segment, "Exif\0\0" ) ) {
					continue;
				}
				if ( $found_exif ) {
					return null;
				}
				$found_exif = true;
				$tiff       = substr( $segment, 6 );
				if ( strlen( $tiff ) < 8 || ! in_array( substr( $tiff, 0, 2 ), array( 'II', 'MM' ), true ) ) {
					return null;
				}
				$little = 'II' === substr( $tiff, 0, 2 );
				$short  = $little ? 'v' : 'n';
				$long   = $little ? 'V' : 'N';
				if ( 42 !== unpack( $short, substr( $tiff, 2, 2 ) )[1] ) {
					return null;
				}
				$offset = unpack( $long, substr( $tiff, 4, 4 ) )[1];
				if ( $offset < 8 || $offset + 2 > strlen( $tiff ) ) {
					return null;
				}
				$count = unpack( $short, substr( $tiff, $offset, 2 ) )[1];
				if ( $count > 1024 || $offset + 2 + $count * 12 > strlen( $tiff ) ) {
					return null;
				}
				for ( $i = 0; $i < $count; ++$i ) {
					$entry = substr( $tiff, $offset + 2 + $i * 12, 12 );
					if ( 0x0112 === unpack( $short, substr( $entry, 0, 2 ) )[1] ) {
						if ( 3 !== unpack( $short, substr( $entry, 2, 2 ) )[1] || 1 !== unpack( $long, substr( $entry, 4, 4 ) )[1] ) {
							return null;
						}
						$orientation = unpack( $short, substr( $entry, 8, 2 ) )[1];
						if ( $orientation < 1 || $orientation > 8 || null !== $found_orientation ) {
							return null;
						}
						$found_orientation = $orientation;
					}
				}
			}
			return null;
		} finally {
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Read-only bounded JPEG header stream; WP_Filesystem has no seekable stream API and reading whole uploads is unsafe.
		}
	}
}
