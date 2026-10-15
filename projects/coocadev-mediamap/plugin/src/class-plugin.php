<?php
/**
 * WordPress UI and REST boundary; the scanner itself never mutates content.
 *
 * The browser holds the transient result list. No tracking, scan history,
 * custom tables, cron or external service is needed for this microplugin.
 *
 * @package CoocadevMediaMap
 * @license GPL-2.0-or-later
 */

namespace Coocadev\MediaMap;

defined( 'ABSPATH' ) || exit;

/** WordPress administration and authenticated REST boundaries. */
final class Plugin {
	/**
	 * Register the native administration, assets and REST entry points.
	 */
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_filter( 'media_row_actions', array( self::class, 'media_action' ), 10, 2 );
	}
	/**
	 * Add the scan page under the native WordPress Tools menu.
	 */
	public static function menu(): void {
		add_management_page( 'MediaMap', 'MediaMap', 'manage_options', 'coocadev-mediamap', array( self::class, 'page' ) );
	}
	/**
	 * Load the local controller only on the authorized scanner screen.
	 *
	 * @param string $hook Current WordPress administration screen hook.
	 */
	public static function assets( string $hook ): void {
		if ( 'tools_page_coocadev-mediamap' !== $hook || ! current_user_can( 'manage_options' ) ) {
			return; }
		wp_enqueue_script( 'ccmm_scan', plugins_url( 'assets/scan.js', FILE ), array(), VERSION, true );
		wp_localize_script(
			'ccmm_scan',
			'ccmmScan',
			array(
				'endpoint'   => rest_url( 'coocadev-mediamap/v1/scan' ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'running'    => __( 'Scanning stored content…', 'coocadev-mediamap' ),
				'done'       => __( 'Scan complete. Matches are evidence of use; no matches do not prove an item is unused.', 'coocadev-mediamap' ),
				'paused'     => __( 'Scan paused. Choose Continue to resume.', 'coocadev-mediamap' ),
				'error'      => __( 'The scan could not finish. Try again or restart it.', 'coocadev-mediamap' ),
				'edit'       => __( 'Edit', 'coocadev-mediamap' ),
				'empty'      => __( 'No matches in the scanned content.', 'coocadev-mediamap' ),
				'summary'    => __( 'Records scanned:', 'coocadev-mediamap' ),
				'visited'    => __( 'Records visited:', 'coocadev-mediamap' ),
				'skipped'    => __( 'Skipped records larger than 256 KiB:', 'coocadev-mediamap' ),
				'incomplete' => __( 'Scan ended with skipped records. This report is incomplete; review oversized content separately. No unused conclusion can be drawn.', 'coocadev-mediamap' ),
			)
		);
	}
	/**
	 * Render labelled scan controls and an accessible results region.
	 */
	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return; }
		echo '<div class="wrap"><h1>MediaMap</h1><p>' . esc_html__( 'Find pages that reference a selected Media Library image. A clean report is not a safe-to-delete certificate.', 'coocadev-mediamap' ) . '</p>';
		echo '<p>' . esc_html__( 'Searches stored posts, pages, editable post types, reusable blocks and database templates in batches. Page builders, options, widgets, theme files, runtime output and remote content are outside this scan. It does not delete, deactivate or change anything.', 'coocadev-mediamap' ) . '</p>';
		echo '<p>' . esc_html__( 'Posts larger than 256 KiB are skipped before parsing or full post-cache loading. Skipped records are counted and make the report incomplete.', 'coocadev-mediamap' ) . '</p>';
		echo '<form id="ccmm_form">';
		$selected = isset( $_GET['attachment_id'] ) && is_scalar( $_GET['attachment_id'] ) ? absint( $_GET['attachment_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only prefill, never a mutation.
		echo '<p><label for="ccmm_image">' . esc_html__( 'Image attachment ID', 'coocadev-mediamap' ) . '</label> <input id="ccmm_image" name="image_id" type="number" min="1" required value="' . esc_attr( $selected ? (string) $selected : '' ) . '"></p>';
		echo '<p>' . esc_html__( 'Use the Usage map link on an image in the Media Library list, or enter its attachment ID. Reusable blocks are reported as their own entries.', 'coocadev-mediamap' ) . '</p>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Start new scan', 'coocadev-mediamap' ) . '</button> ';
		echo '<button type="button" class="button" id="ccmm_pause" disabled>' . esc_html__( 'Pause', 'coocadev-mediamap' ) . '</button> ';
		echo '<button type="button" class="button" id="ccmm_continue" disabled>' . esc_html__( 'Continue', 'coocadev-mediamap' ) . '</button></p></form>';
		echo '<p id="ccmm_status" role="status" aria-live="polite"></p><ol id="ccmm_results"></ol></div>';
	}
	/**
	 * Register the read-only endpoint with its capability boundary.
	 */
	public static function routes(): void {
		register_rest_route(
			'coocadev-mediamap/v1',
			'/scan',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' ); },
				'callback'            => array( self::class, 'scan' ),
			)
		);
	}
	/**
	 * Validate one request, scan a bounded batch and return private evidence.
	 *
	 * @param \WP_REST_Request $request Authenticated, capability-checked REST request.
	 * @return \WP_REST_Response|\WP_Error Validated data or the documented request error.
	 */
	public static function scan( \WP_REST_Request $request ) {
		$after = Scanner::cursor( $request->get_param( 'after' ) ?? '0' );
		if ( is_wp_error( $after ) ) {
			return $after; }
		$id = Scanner::cursor( $request->get_param( 'image_id' ) );
		if ( is_wp_error( $id ) || ! $id || ! wp_attachment_is_image( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return new \WP_Error( 'ccmm_image', __( 'Choose an image you can edit.', 'coocadev-mediamap' ), array( 'status' => 400 ) );
		}
		$urls  = Scanner::image_urls( $id );
		$batch = Scanner::batch( $after );
		if ( ! empty( $batch['error'] ) ) {
			return new \WP_Error( 'scan_database', __( 'The content database could not be read. Try again.', 'coocadev-mediamap' ), array( 'status' => 503 ) );
		}
		$matches = array();
		foreach ( $batch['posts'] as $post ) {
			// A custom admin role may manage settings without editing all private posts.
			if ( ! current_user_can( 'edit_post', (int) $post->ID ) ) {
				continue; }
			$reasons = Scanner::reasons( $post, $id, $urls );
			if ( $reasons ) {
				$matches[] = Scanner::result( $post, $reasons ); }
		}
		$response = new \WP_REST_Response(
			array(
				'matches'    => $matches,
				'next'       => $batch['next'],
				'done'       => $batch['done'],
				'scanned'    => count( $batch['posts'] ),
				'visited'    => $batch['visited'],
				'skipped'    => $batch['skipped'],
				'incomplete' => $batch['skipped'] > 0,
			)
		);
		$response->header( 'Cache-Control', 'no-store, private, max-age=0' );
		$response->header( 'Vary', 'Cookie, Authorization' );
		return $response;
	}
	/**
	 * Add a native entry point without injecting JavaScript into the Media Library.
	 *
	 * @param array  $actions Existing Media Library row actions.
	 * @param object $post Stored post record already checked for edit access.
	 * @return array Validated data or the documented request error.
	 */
	public static function media_action( array $actions, $post ): array {
		if ( current_user_can( 'manage_options' ) && current_user_can( 'edit_post', $post->ID ) && wp_attachment_is_image( $post->ID ) ) {
			$url                   = add_query_arg(
				array(
					'page'          => 'coocadev-mediamap',
					'attachment_id' => $post->ID,
				),
				admin_url( 'tools.php' )
			);
			$actions['ccmm_usage'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Usage map', 'coocadev-mediamap' ) . '</a>';
		}
		return $actions;
	}
}
