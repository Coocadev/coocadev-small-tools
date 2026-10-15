<?php
/**
 * WordPress UI and REST boundary; the scanner itself never mutates content.
 *
 * The browser holds the transient result list. No tracking, scan history,
 * custom tables, cron or external service is needed for this microplugin.
 *
 * @package CoocadevUseMap
 * @license GPL-2.0-or-later
 */

namespace Coocadev\UseMap;

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
	}
	/**
	 * Add the scan page under the native WordPress Tools menu.
	 */
	public static function menu(): void {
		add_management_page( 'UseMap', 'UseMap', 'manage_options', 'coocadev-usemap', array( self::class, 'page' ) );
	}
	/**
	 * Load the local controller only on the authorized scanner screen.
	 *
	 * @param string $hook Current WordPress administration screen hook.
	 */
	public static function assets( string $hook ): void {
		if ( 'tools_page_coocadev-usemap' !== $hook || ! current_user_can( 'manage_options' ) ) {
			return; }
		wp_enqueue_script( 'ccum_scan', plugins_url( 'assets/scan.js', FILE ), array(), VERSION, true );
		wp_localize_script(
			'ccum_scan',
			'ccumScan',
			array(
				'endpoint'   => rest_url( 'coocadev-usemap/v1/scan' ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'running'    => __( 'Scanning stored content…', 'coocadev-usemap' ),
				'done'       => __( 'Scan complete. Matches are evidence of use; no matches do not prove an item is unused.', 'coocadev-usemap' ),
				'paused'     => __( 'Scan paused. Choose Continue to resume.', 'coocadev-usemap' ),
				'error'      => __( 'The scan could not finish. Try again or restart it.', 'coocadev-usemap' ),
				'edit'       => __( 'Edit', 'coocadev-usemap' ),
				'empty'      => __( 'No matches in the scanned content.', 'coocadev-usemap' ),
				'summary'    => __( 'Records scanned:', 'coocadev-usemap' ),
				'visited'    => __( 'Records visited:', 'coocadev-usemap' ),
				'skipped'    => __( 'Skipped records larger than 256 KiB:', 'coocadev-usemap' ),
				'incomplete' => __( 'Scan ended with skipped records. This report is incomplete; review oversized content separately. No unused conclusion can be drawn.', 'coocadev-usemap' ),
			)
		);
	}
	/**
	 * Render labelled scan controls and an accessible results region.
	 */
	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return; }
		echo '<div class="wrap"><h1>UseMap</h1><p>' . esc_html__( 'Enter block and shortcode identifiers to find their stored uses before changing a plugin.', 'coocadev-usemap' ) . '</p>';
		echo '<p>' . esc_html__( 'Searches stored posts, pages, editable post types, reusable blocks and database templates in batches. Page builders, options, widgets, theme files, runtime output and remote content are outside this scan. It does not delete, deactivate or change anything.', 'coocadev-usemap' ) . '</p>';
		echo '<p>' . esc_html__( 'Posts larger than 256 KiB are skipped before parsing or full post-cache loading. Skipped records are counted and make the report incomplete.', 'coocadev-usemap' ) . '</p>';
		echo '<form id="ccum_form">';
		echo '<p><label for="ccum_blocks">' . esc_html__( 'Block names, separated by spaces or new lines', 'coocadev-usemap' ) . '</label><br><textarea id="ccum_blocks" name="blocks" rows="4" cols="65" placeholder="vendor/block-name"></textarea></p>';
		echo '<p><label for="ccum_shortcodes">' . esc_html__( 'Shortcode names without brackets', 'coocadev-usemap' ) . '</label><br><textarea id="ccum_shortcodes" name="shortcodes" rows="3" cols="65" placeholder="my_shortcode"></textarea></p>';
		$names = array_keys( \WP_Block_Type_Registry::get_instance()->get_all_registered() );
		echo '<details><summary>' . esc_html__( 'Registered blocks and shortcodes on this site', 'coocadev-usemap' ) . '</summary><p><code>' . esc_html( implode( ', ', $names ) ) . '</code></p>';
		global $shortcode_tags;
		echo '<p><code>' . esc_html( implode( ', ', array_keys( (array) $shortcode_tags ) ) ) . '</code></p></details>';
		echo '<p>' . esc_html__( 'Use up to 30 names in each list. A plugin can affect the site without storing a block or shortcode. No matches cannot establish that deactivation is safe.', 'coocadev-usemap' ) . '</p>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Start new scan', 'coocadev-usemap' ) . '</button> ';
		echo '<button type="button" class="button" id="ccum_pause" disabled>' . esc_html__( 'Pause', 'coocadev-usemap' ) . '</button> ';
		echo '<button type="button" class="button" id="ccum_continue" disabled>' . esc_html__( 'Continue', 'coocadev-usemap' ) . '</button></p></form>';
		echo '<p id="ccum_status" role="status" aria-live="polite"></p><ol id="ccum_results"></ol></div>';
	}
	/**
	 * Register the read-only endpoint with its capability boundary.
	 */
	public static function routes(): void {
		register_rest_route(
			'coocadev-usemap/v1',
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
		$blocks     = Scanner::identifiers( $request->get_param( 'blocks' ) ?? '', true );
		$shortcodes = Scanner::identifiers( $request->get_param( 'shortcodes' ) ?? '', false );
		if ( is_wp_error( $blocks ) ) {
			return $blocks; }
		if ( is_wp_error( $shortcodes ) ) {
			return $shortcodes; }
		if ( ! $blocks && ! $shortcodes ) {
			return new \WP_Error( 'ccum_empty', __( 'Enter at least one block or shortcode name.', 'coocadev-usemap' ), array( 'status' => 400 ) );
		}
		$batch = Scanner::batch( $after );
		if ( ! empty( $batch['error'] ) ) {
			return new \WP_Error( 'scan_database', __( 'The content database could not be read. Try again.', 'coocadev-usemap' ), array( 'status' => 503 ) );
		}
		$matches = array();
		foreach ( $batch['posts'] as $post ) {
			// A custom admin role may manage settings without editing all private posts.
			if ( ! current_user_can( 'edit_post', (int) $post->ID ) ) {
				continue; }
			$reasons = Scanner::reasons( $post->post_content, $blocks, $shortcodes );
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
}
