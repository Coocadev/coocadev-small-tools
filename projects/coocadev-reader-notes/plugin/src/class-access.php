<?php
/**
 * Access checks must also apply to the subject of a privacy export.
 *
 * Separates note ownership from access to the linked source article.
 * Existing owned text remains exportable if article access is revoked,
 * but the closed source title/URL is withheld. Privacy exports use their
 * explicit subject ID, never the administrator running the export job.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevReaderNotes
 */

namespace Coocadev\ReaderNotes;

defined( 'ABSPATH' ) || exit;

/**
 * Access module for private reader notes.
 */
final class Access {
	/**
	 * Require the read capability and membership of the current site.
	 *
	 * @param int $user_id User id.
	 * @return bool Operation result.
	 */
	public static function member( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, 'read' ) && ( ! is_multisite() || is_user_member_of_blog( $user_id, get_current_blog_id() ) );
	}

	/**
	 * Check source access for an explicit reader, including privacy exports.
	 *
	 * @param int  $post_id Post id.
	 * @param int  $user_id User id.
	 * @param bool $background Background.
	 * @return bool Operation result.
	 */
	public static function post( int $post_id, int $user_id, bool $background = false ): bool {
		$post = get_post( $post_id );
		if ( ! $post || ! self::member( $user_id ) || ! in_array( $post->post_type, Plugin::settings()['post_types'], true ) ) {
			return false;
		}
		if ( ! in_array( $post->post_status, array( 'publish', 'private' ), true ) || ! user_can( $user_id, 'read_post', $post_id ) ) {
			return false;
		}
		if ( '' !== $post->post_password && ( $background || post_password_required( $post ) ) ) {
			return false;
		}
		/**
		 * A membership integration may deny access, never grant a denied Core check.
		 * Must use the explicit user ID, including during background privacy export.
		 */
		return true === apply_filters( 'ccrn_can_read_source', true, $post_id, $user_id, $background );
	}

	/**
	 * Return owned note fields, hiding metadata of inaccessible sources.
	 *
	 * @param array $note Note.
	 * @param int   $user_id User id.
	 * @param bool  $background Background.
	 * @return array Operation result.
	 */
	public static function public_note( array $note, int $user_id, bool $background = false ): array {
		$post_id   = isset( $note['post_id'] ) ? (int) $note['post_id'] : 0;
		$available = $post_id > 0 && self::post( $post_id, $user_id, $background );
		return array(
			'id'             => (int) $note['id'],
			'post_id'        => $available ? $post_id : null,
			'content'        => $note['content'],
			'version'        => (int) $note['version'],
			'created_at_gmt' => $note['created_at_gmt'],
			'updated_at_gmt' => $note['updated_at_gmt'],
			'source'         => $available ? array(
				'title' => get_the_title( $post_id ),
				'url'   => get_permalink( $post_id ),
			) : null,
		);
	}

	/**
	 * Reject cross-origin browser requests without broadening CORS.
	 *
	 * @return bool Operation result.
	 */
	public static function same_origin(): bool {
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) && is_string( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_url( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		$site   = wp_parse_url( home_url() );
		if ( $origin ) {
			$parts = wp_parse_url( $origin );
			if ( ! $parts || ( $parts['scheme'] ?? '' ) !== ( $site['scheme'] ?? '' ) || ( $parts['host'] ?? '' ) !== ( $site['host'] ?? '' ) || ( $parts['port'] ?? 0 ) !== ( $site['port'] ?? 0 ) ) {
				return false;
			}
		}
		$fetch = isset( $_SERVER['HTTP_SEC_FETCH_SITE'] ) && is_string( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ) : '';
		return ! in_array( $fetch, array( 'cross-site', 'same-site' ), true );
	}
}
