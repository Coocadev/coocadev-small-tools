<?php
/**
 * Bounded, read-only search of stored WordPress content.
 *
 * Each HTTP call advances an ID cursor instead of keeping a server-side job.
 * A newly inserted post may appear in a later batch. Edits to already scanned
 * posts require a new scan: the report is evidence of a scan, not a live index.
 *
 * @package CoocadevUseMap
 * @license GPL-2.0-or-later
 */

namespace Coocadev\UseMap;

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
			return new \WP_Error( 'ccum_cursor', __( 'Invalid scan cursor.', 'coocadev-usemap' ), array( 'status' => 400 ) );
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
			'title'   => $post->post_title ? $post->post_title : __( '(no title)', 'coocadev-usemap' ),
			'type'    => $post->post_type,
			'edit'    => get_edit_post_link( (int) $post->ID, 'raw' ),
			'reasons' => array_values( array_unique( $reasons ) ),
		);
	}

	/**
	 * Validate human-supplied identifiers; never infer a plugin-to-block mapping.
	 *
	 * @param mixed $value Untrusted cursor or identifier-list input.
	 * @param bool  $blocks Whether to validate block names rather than shortcode names.
	 * @return array|\WP_Error Validated data or the documented request error.
	 */
	public static function identifiers( $value, bool $blocks ) {
		if ( ! is_string( $value ) || strlen( $value ) > 3000 ) {
			return new \WP_Error( 'ccum_identifiers', __( 'Enter a short list of identifiers.', 'coocadev-usemap' ), array( 'status' => 400 ) );
		}
		$items = preg_split( '/[\s,]+/', trim( $value ), -1, PREG_SPLIT_NO_EMPTY );
		if ( count( $items ) > 30 ) {
			return new \WP_Error( 'ccum_identifiers', __( 'Use at most 30 identifiers per list.', 'coocadev-usemap' ), array( 'status' => 400 ) );
		}
		foreach ( $items as $item ) {
			$pattern = $blocks ? '/^[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*$/D' : '/^[A-Za-z][A-Za-z0-9_-]*$/D';
			if ( strlen( $item ) > 100 || ! preg_match( $pattern, $item ) ) {
				return new \WP_Error( 'ccum_identifiers', __( 'One or more identifiers are invalid.', 'coocadev-usemap' ), array( 'status' => 400 ) );
			}
		}
		return array_values( array_unique( $items ) );
	}

	/**
	 * Parse shortcode syntax without executing it, including when its plugin is inactive.
	 *
	 * @param string $content Raw stored post content; it is never executed.
	 * @param array  $blocks Parsed block data or validated target names.
	 * @param array  $shortcodes Validated shortcode identifiers without brackets.
	 * @return array Validated data or the documented request error.
	 */
	public static function reasons( string $content, array $blocks, array $shortcodes ): array {
		$reasons = array();
		self::blocks( parse_blocks( $content ), $blocks, $reasons, 0 );
		if ( $shortcodes && preg_match_all( '/' . get_shortcode_regex( $shortcodes ) . '/s', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				// [[shortcode]] is escaped display text, not an executable shortcode use.
				if ( '[' === $match[1] && ']' === $match[6] ) {
					continue; }
				$reasons[] = '[' . $match[2] . ']';
			}
		}
		return array_values( array_unique( $reasons ) );
	}

	/**
	 * Walk supported block data without rendering or executing any content.
	 *
	 * @param array $items Parsed block nodes at this nesting level.
	 * @param array $names Validated target block names.
	 * @param array $reasons Match labels collected for this record.
	 * @param int   $depth Current block nesting depth, bounded at 30.
	 */
	private static function blocks( array $items, array $names, array &$reasons, int $depth ): void {
		if ( $depth > 30 ) {
			return; }
		foreach ( $items as $block ) {
			$name = $block['blockName'] ?? '';
			if ( in_array( $name, $names, true ) ) {
				$reasons[] = $name; }
			self::blocks( (array) ( $block['innerBlocks'] ?? array() ), $names, $reasons, $depth + 1 );
		}
	}
}
