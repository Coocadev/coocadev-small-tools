<?php
/**
 * Bounded, read-only search of stored WordPress content.
 *
 * Each HTTP call advances an ID cursor instead of keeping a server-side job.
 * A newly inserted post may appear in a later batch. Edits to already scanned
 * posts require a new scan: the report is evidence of a scan, not a live index.
 *
 * @package CoocadevMediaMap
 * @license GPL-2.0-or-later
 */

namespace Coocadev\MediaMap;

defined( 'ABSPATH' ) || exit;

/** Read-only content search with bounded, ordered batches. */
final class Scanner {
	const BATCH_SIZE        = 40;
	const MAX_CONTENT_BYTES = 262144;

	/**
	 * Reject malformed cursors rather than silently coercing arrays or exponents.
	 *
	 * @param mixed $value Untrusted cursor or identifier-list input.
	 * @return int|\WP_Error Validated data or the documented request error.
	 */
	public static function cursor( $value ) {
		if ( ! is_scalar( $value ) || is_bool( $value ) || ! preg_match( '/^(0|[1-9][0-9]{0,9})$/D', (string) $value ) ) {
			return new \WP_Error( 'ccmm_cursor', __( 'Invalid scan cursor.', 'coocadev-mediamap' ), array( 'status' => 400 ) );
		}
		return (int) $value;
	}

	/**
	 * Read at most 41 rows; the extra row establishes whether another batch exists.
	 *
	 * This prepared query intentionally bypasses WP_Query offsets: offsets get
	 * progressively slower on large sites. All identifiers originate in wpdb;
	 * values and the cursor are placeholders. No arbitrary SQL enters this path.
	 *
	 * @param int $after Last successfully scanned post ID.
	 * @return array Validated data or the documented request error.
	 */
	public static function batch( int $after ): array {
		global $wpdb;
		$types = array_values( get_post_types( array( 'show_ui' => true ), 'names' ) );
		$types = array_values( array_unique( array_merge( $types, array( 'wp_block', 'wp_template', 'wp_template_part' ) ) ) );
		$types = array_values( array_diff( $types, array( 'attachment', 'revision', 'nav_menu_item' ) ) );
		if ( ! $types ) {
			return array(
				'posts'   => array(),
				'next'    => $after,
				'done'    => true,
				'visited' => 0,
				'skipped' => 0,
			);
		}
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$params       = array_merge( array( self::MAX_CONTENT_BYTES, $wpdb->posts, $after ), $types, array( self::BATCH_SIZE + 1 ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Prepared, bounded cursor search; original oversized content never leaves the database.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID,post_title,CASE WHEN OCTET_LENGTH(post_content) <= %d THEN post_content ELSE NULL END AS post_content,post_type FROM %i WHERE ID > %d AND post_type IN ($placeholders) AND post_status NOT IN ('trash','auto-draft','inherit') ORDER BY ID ASC LIMIT %d", ...$params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only generated placeholder tokens are interpolated; all values go through prepare.
		// get_results() can return [] after a SQL failure: inspect the error before any cache query can replace it.
		$database_error = $wpdb->last_error;
		if ( '' !== $database_error || ! is_array( $rows ) ) {
			return array(
				'posts'   => array(),
				'next'    => $after,
				'done'    => false,
				'visited' => 0,
				'skipped' => 0,
				'error'   => true,
			);
		}
		$done         = count( $rows ) <= self::BATCH_SIZE;
		$rows         = array_slice( $rows, 0, self::BATCH_SIZE );
		$visited      = count( $rows );
		$skipped      = 0;
		$posts        = array();
		$all_ids      = array();
		$eligible_ids = array();
		foreach ( $rows as $row ) {
			$all_ids[] = (int) $row->ID;
			if ( null === $row->post_content ) {
				++$skipped;
				continue; }
			$posts[]        = $row;
			$eligible_ids[] = (int) $row->ID;
		}
		// Never prime a skipped post: Core's full post cache would otherwise load the oversized body again.
		if ( $eligible_ids ) {
			_prime_post_caches( $eligible_ids, false, true ); }
		return array(
			'posts'   => $posts,
			'next'    => $all_ids ? max( $all_ids ) : $after,
			'done'    => $done,
			'visited' => $visited,
			'skipped' => $skipped,
		);
	}

	/**
	 * Only return an editable title/link; never send full post content to the browser.
	 *
	 * @param object $post Stored post record already checked for edit access.
	 * @param array  $reasons Match labels collected for this record.
	 * @return array Validated data or the documented request error.
	 */
	public static function result( $post, array $reasons ): array {
		return array(
			'id'      => (int) $post->ID,
			'title'   => $post->post_title ? $post->post_title : __( '(no title)', 'coocadev-mediamap' ),
			'type'    => $post->post_type,
			'edit'    => get_edit_post_link( (int) $post->ID, 'raw' ),
			'reasons' => array_values( array_unique( $reasons ) ),
		);
	}

	/**
	 * Build trusted local URLs for the original image and generated sizes once per request.
	 *
	 * @param int $id Image attachment ID authorized for this request.
	 * @return array Validated data or the documented request error.
	 */
	public static function image_urls( int $id ): array {
		$url = wp_get_attachment_url( $id );
		if ( ! $url ) {
			return array(); }
		$urls = array( $url );
		$meta = wp_get_attachment_metadata( $id );
		$base = trailingslashit( dirname( $url ) );
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( isset( $size['file'] ) && is_string( $size['file'] ) ) {
				$urls[] = $base . wp_basename( $size['file'] );
			}
		}
		// Keep both URL and root-relative forms; query strings are stripped at comparison time.
		foreach ( $urls as $url ) {
			$path = wp_parse_url( $url, PHP_URL_PATH );
			if ( $path ) {
				$urls[] = $path; }
		}
		return array_values( array_unique( $urls ) );
	}

	/**
	 * Match supported Core block attributes, explicit HTML IDs and exact image URLs.
	 * A source URL match is evidence, not ownership or permission to delete a file.
	 *
	 * @param object $post Stored post record already checked for edit access.
	 * @param int    $image_id Image attachment ID authorized for this request.
	 * @param array  $urls Exact original and generated local image URLs.
	 * @return array Validated data or the documented request error.
	 */
	public static function reasons( $post, int $image_id, array $urls ): array {
		$reasons = array();
		if ( (int) get_post_thumbnail_id( (int) $post->ID ) === $image_id ) {
			$reasons[] = __( 'Featured image', 'coocadev-mediamap' );
		}
		self::blocks( parse_blocks( $post->post_content ), $image_id, $reasons, 0 );
		$processor = new \WP_HTML_Tag_Processor( $post->post_content );
		while ( $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
			if ( $processor->has_class( 'wp-image-' . $image_id ) ) {
				$reasons[] = __( 'Image class in content', 'coocadev-mediamap' ); }
			foreach ( array( 'src', 'srcset' ) as $attribute ) {
				$value = $processor->get_attribute( $attribute );
				if ( ! is_string( $value ) ) {
					continue; }
				$candidates = 'srcset' === $attribute ? explode( ',', $value ) : array( $value );
				foreach ( $candidates as $candidate ) {
					$candidate = preg_split( '/\s+/', trim( $candidate ) )[0];
					$candidate = html_entity_decode( $candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					$candidate = preg_replace( '/[?#].*$/', '', $candidate );
					if ( in_array( $candidate, $urls, true ) ) {
						$reasons[] = __( 'Image URL in content', 'coocadev-mediamap' );
					}
				}
			}
		}
		return array_values( array_unique( $reasons ) );
	}

	/**
	 * Depth limit protects malformed nested content; reusable blocks are scanned as their own entries.
	 *
	 * @param array $blocks Parsed block data or validated target names.
	 * @param int   $id Image attachment ID authorized for this request.
	 * @param array $reasons Match labels collected for this record.
	 * @param int   $depth Current block nesting depth, bounded at 30.
	 */
	private static function blocks( array $blocks, int $id, array &$reasons, int $depth ): void {
		if ( $depth > 30 ) {
			return; }
		$attributes = array(
			'core/image'      => 'id',
			'core/cover'      => 'id',
			'core/media-text' => 'mediaId',
		);
		foreach ( $blocks as $block ) {
			$name  = $block['blockName'] ?? '';
			$attrs = $block['attrs'] ?? array();
			if ( isset( $attributes[ $name ] ) && isset( $attrs[ $attributes[ $name ] ] ) && (int) $attrs[ $attributes[ $name ] ] === $id ) {
				$reasons[] = $name;
			}
			if ( 'core/gallery' === $name && in_array( $id, array_map( 'intval', (array) ( $attrs['ids'] ?? array() ) ), true ) ) {
				$reasons[] = $name;
			}
			self::blocks( (array) ( $block['innerBlocks'] ?? array() ), $id, $reasons, $depth + 1 );
		}
	}
}
