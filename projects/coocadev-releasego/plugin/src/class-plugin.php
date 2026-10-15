<?php
/**
 * Native release editing and a bounded published-only changelog renderer.
 *
 * WordPress owns post/taxonomy permissions and revision history. The custom
 * metadata adapter additionally requires an action nonce and exact object rights.
 * The public renderer repeats publish/type/version checks after WP_Query so even
 * a query filter cannot accidentally expose a draft through this shortcode.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevReleaseGo
 */

namespace Coocadev\ReleaseGo;

defined( 'ABSPATH' ) || exit;

/** Native WordPress release notes; no external API, source execution or build step. */
final class Plugin {
	const TYPE = 'ccrg_release';
	const TAX  = 'ccrg_product';

	/**
	 * Preserve old posts on reactivation; reject unsupported network-wide activation.
	 *
	 * @param bool $network_wide Whether network-wide activation was requested.
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( $network_wide ) {
			wp_die( esc_html__( 'Activate ReleaseGo separately on each site.', 'coocadev-releasego' ) );
		}
		add_option( 'ccrg_remove_data', false, '', false );
		self::types();
	}

	/** Register only native editor/admin hooks and the explicit frontend shortcode. */
	public static function boot(): void {
		add_action( 'init', array( self::class, 'types' ) );
		add_action( 'add_meta_boxes_' . self::TYPE, array( self::class, 'boxes' ) );
		add_action( 'save_post_' . self::TYPE, array( self::class, 'save' ), 10, 2 );
		add_filter( 'redirect_post_location', array( self::class, 'save_notice' ), 10, 2 );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_ccrg_retention', array( self::class, 'retention' ) );
		add_action( 'admin_init', array( self::class, 'privacy' ) );
		add_shortcode( 'coocadev_releases', array( self::class, 'shortcode' ) );
	}

	/** Store release posts privately; only the deliberate shortcode exposes published notes. */
	public static function types(): void {
		register_post_type(
			self::TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Releases', 'coocadev-releasego' ),
					'singular_name' => __( 'Release', 'coocadev-releasego' ),
					'add_new_item'  => __( 'Add release', 'coocadev-releasego' ),
					'edit_item'     => __( 'Edit release', 'coocadev-releasego' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_rest'        => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'supports'            => array( 'title', 'editor', 'author', 'revisions' ),
				'map_meta_cap'        => true,
				'capability_type'     => 'post',
				'menu_icon'           => 'dashicons-list-view',
			)
		);
		register_taxonomy(
			self::TAX,
			self::TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Products', 'coocadev-releasego' ),
					'singular_name' => __( 'Product', 'coocadev-releasego' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => false,
				'rewrite'           => false,
				'hierarchical'      => false,
				'capabilities'      => array(
					'manage_terms' => 'manage_categories',
					'edit_terms'   => 'manage_categories',
					'delete_terms' => 'manage_categories',
					'assign_terms' => 'edit_posts',
				),
			)
		);
	}

	/** Stable translated badges; unknown values never become public CSS or labels. */
	public static function kinds(): array {
		return array(
			'new'      => __( 'New', 'coocadev-releasego' ),
			'fixed'    => __( 'Fixed', 'coocadev-releasego' ),
			'improved' => __( 'Improved', 'coocadev-releasego' ),
		);
	}

	/**
	 * Accept bounded version labels rather than sanitizing an invalid value into validity.
	 *
	 * @param mixed $value Untrusted candidate value; no coercion is allowed.
	 */
	public static function version( mixed $value ): bool {
		return is_string( $value ) && strlen( $value ) <= 50 && (bool) preg_match( '/^[0-9]{1,6}(?:\.[0-9]{1,6}){1,3}(?:[-+][A-Za-z0-9][A-Za-z0-9.-]{0,30})?$/D', $value );
	}

	/**
	 * Validate the entire metadata pair before persisting either part.
	 *
	 * @param mixed $version Exact candidate product version label.
	 * @param mixed $kinds Candidate list of distinct release-category enums.
	 */
	public static function metadata( mixed $version, mixed $kinds ): array|\WP_Error {
		if ( ! self::version( $version ) || ! is_array( $kinds ) || ! array_is_list( $kinds ) || count( $kinds ) < 1 || count( $kinds ) > 3 ) {
			return new \WP_Error( 'ccrg_metadata', __( 'Use a version such as 1.2.3 and select New, Fixed or Improved.', 'coocadev-releasego' ) );
		}
		foreach ( $kinds as $kind ) {
			if ( ! is_string( $kind ) || ! array_key_exists( $kind, self::kinds() ) ) {
				return new \WP_Error( 'ccrg_metadata', __( 'The release category is invalid.', 'coocadev-releasego' ) );
			}
		}
		if ( count( array_unique( $kinds ) ) !== count( $kinds ) ) {
			return new \WP_Error( 'ccrg_metadata', __( 'Release categories must be distinct.', 'coocadev-releasego' ) );
		}
		return array(
			'version' => $version,
			'kinds'   => $kinds,
		);
	}

	/** Register an accessible native metabox, leaving the post editor to Core. */
	public static function boxes(): void {
		add_meta_box( 'ccrg-details', __( 'Release details', 'coocadev-releasego' ), array( self::class, 'details' ), self::TYPE, 'side' );
	}

	/**
	 * Escape persisted text and badge fields in their final HTML context.
	 *
	 * @param \WP_Post $post Current persisted WordPress release post.
	 */
	public static function details( \WP_Post $post ): void {
		wp_nonce_field( 'ccrg_meta_' . $post->ID, 'ccrg_nonce' );
		$version = get_post_meta( $post->ID, '_ccrg_version', true );
		$kinds   = get_post_meta( $post->ID, '_ccrg_kinds', true );
		$kinds   = is_array( $kinds ) ? $kinds : array();
		echo '<p><label for="ccrg-version">' . esc_html__( 'Version (for example 1.2.3)', 'coocadev-releasego' ) . '</label><input class="widefat" type="text" maxlength="50" id="ccrg-version" name="ccrg_version" value="' . esc_attr( is_string( $version ) ? $version : '' ) . '"></p>';
		foreach ( self::kinds() as $key => $label ) {
			echo '<p><label><input type="checkbox" name="ccrg_kinds[]" value="' . esc_attr( $key ) . '" ' . checked( in_array( $key, $kinds, true ), true, false ) . '> ' . esc_html( $label ) . '</label></p>';
		}
		echo '<p>' . esc_html__( 'Only published releases with valid version details appear in the shortcode. Choose a Product to filter its history.', 'coocadev-releasego' ) . '</p>';
	}

	/**
	 * Write only owned metadata; native Quick Edit/autosave without this nonce preserves it.
	 *
	 * @param int      $id Current site release post identifier.
	 * @param \WP_Post $post Current persisted WordPress release post.
	 */
	public static function save( int $id, \WP_Post $post ): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'post' !== $method || self::TYPE !== $post->post_type || wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		if ( ! isset( $_POST['ccrg_nonce'] ) || ! is_string( $_POST['ccrg_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ccrg_nonce'] ) ), 'ccrg_meta_' . $id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- metadata() strictly validates the exact version before persistence; sanitization must not repair an invalid version into a valid one.
		$version = isset( $_POST['ccrg_version'] ) && is_string( $_POST['ccrg_version'] ) ? wp_unslash( $_POST['ccrg_version'] ) : null;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- metadata() validates a distinct bounded list containing only three exact badge enums.
		$kinds = isset( $_POST['ccrg_kinds'] ) && is_array( $_POST['ccrg_kinds'] ) ? wp_unslash( $_POST['ccrg_kinds'] ) : null;
		$valid = self::metadata( $version, $kinds );
		if ( is_wp_error( $valid ) ) {
			// Request-local result: no cross-user transient or private details enter a URL.
			$GLOBALS['ccrg_invalid_metadata'] = true;
			return;
		}
		update_post_meta( $id, '_ccrg_version', $valid['version'] );
		update_post_meta( $id, '_ccrg_kinds', $valid['kinds'] );
	}

	/**
	 * Carry a generic invalid-input notice through Core's normal post-save redirect.
	 *
	 * @param string $location Core post-save redirect URL.
	 * @param int    $id Current site release post identifier.
	 */
	public static function save_notice( string $location, int $id ): string {
		return ! empty( $GLOBALS['ccrg_invalid_metadata'] ) && self::TYPE === get_post_type( $id ) ? add_query_arg( 'ccrg_details_error', '1', $location ) : $location;
	}

	/** Explain metadata failure without claiming the editor body was rolled back. */
	public static function notice(): void {
		$screen = get_current_screen();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only generic error marker; no content or metadata mutation.
		if ( $screen && self::TYPE === $screen->post_type && isset( $_GET['ccrg_details_error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Release details were not changed: enter a valid version and at least one distinct category. WordPress may still have saved the title and body.', 'coocadev-releasego' ) . '</p></div>';
		}
	}

	/**
	 * Render a bounded published-only list without executing notes as shortcodes or PHP.
	 *
	 * @param mixed $attributes Shortcode attributes before strict bounds validation.
	 */
	public static function shortcode( mixed $attributes = array() ): string {
		if ( ! is_array( $attributes ) ) {
			return '';
		}
		$attributes = shortcode_atts(
			array(
				'product' => '',
				'limit'   => '10',
			),
			$attributes,
			'coocadev_releases'
		);
		$product    = $attributes['product'];
		$limit      = $attributes['limit'];
		if ( ! is_string( $product ) || strlen( $product ) > 80 || ( '' !== $product && ( sanitize_title( $product ) !== $product || ! preg_match( '/^[a-z0-9][a-z0-9-]*$/D', $product ) ) ) || ! is_string( $limit ) || ! preg_match( '/^(?:[1-9]|[1-4][0-9]|50)$/D', $limit ) ) {
			return '';
		}
		$args = array(
			'post_type'           => self::TYPE,
			'post_status'         => 'publish',
			'posts_per_page'      => (int) $limit,
			'orderby'             => array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			),
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		);
		if ( '' !== $product ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Bounded shortcode list with one native indexed product-taxonomy filter, not an unbounded metadata scan.
			$args['tax_query'] = array(
				array(
					'taxonomy' => self::TAX,
					'field'    => 'slug',
					'terms'    => $product,
				),
			);
		}
		$query = new \WP_Query( $args );
		$items = '';
		foreach ( $query->posts as $post ) {
			if ( self::TYPE !== $post->post_type || 'publish' !== $post->post_status || '' !== $post->post_password ) {
				continue;
			}
			$valid = self::metadata( get_post_meta( $post->ID, '_ccrg_version', true ), get_post_meta( $post->ID, '_ccrg_kinds', true ) );
			if ( is_wp_error( $valid ) ) {
				continue;
			}
			$items .= '<article class="ccrg-release"><h3>' . esc_html( get_the_title( $post ) ) . ' <span class="ccrg-version">' . esc_html( $valid['version'] ) . '</span></h3><p>';
			foreach ( $valid['kinds'] as $kind ) {
				$items .= '<span class="ccrg-badge ccrg-' . esc_attr( $kind ) . '">' . esc_html( self::kinds()[ $kind ] ) . '</span> ';
			}
			$items .= '<time datetime="' . esc_attr( get_post_time( 'c', true, $post ) ) . '">' . esc_html( get_the_date( '', $post ) ) . '</time></p>';
			// Allow normal post HTML, but do not run arbitrary shortcode/content filters.
			$items .= '<div class="ccrg-content">' . wp_kses_post( wpautop( $post->post_content ) ) . '</div></article>';
		}
		return '' === $items ? '' : '<section class="ccrg-releases" aria-label="' . esc_attr__( 'Product release history', 'coocadev-releasego' ) . '">' . $items . '</section>';
	}

	/** The native settings page changes only the explicit uninstall policy. */
	public static function menu(): void {
		add_options_page( __( 'ReleaseGo', 'coocadev-releasego' ), __( 'ReleaseGo', 'coocadev-releasego' ), 'manage_options', 'ccrg-settings', array( self::class, 'settings' ) );
	}

	/** Keep ordinary deactivation and uninstall non-destructive by default. */
	public static function settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot change ReleaseGo settings.', 'coocadev-releasego' ), '', array( 'response' => 403 ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'ReleaseGo', 'coocadev-releasego' ) . '</h1><p><code>[coocadev_releases product="my-product" limit="10"]</code></p><p>' . esc_html__( 'Create entries under Releases, add their version and categories, assign a Product, then publish. Use the shortcode on an existing page.', 'coocadev-releasego' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ccrg_retention">';
		wp_nonce_field( 'ccrg_retention' );
		echo '<label><input type="checkbox" name="remove_data" value="1" ' . checked( get_option( 'ccrg_remove_data', false ), true, false ) . '> ' . esc_html__( 'Delete ReleaseGo releases, product terms and settings when uninstalling. This permanently removes this plugin’s release history.', 'coocadev-releasego' ) . '</label>';
		submit_button( __( 'Save retention preference', 'coocadev-releasego' ) );
		echo '</form></div>';
	}

	/** Explicit POST, capability and nonce guard, with strict checkbox semantics. */
	public static function retention(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'post' !== $method || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'This action requires an authorized POST request.', 'coocadev-releasego' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ccrg_retention' );
		if ( isset( $_POST['remove_data'] ) && '1' !== $_POST['remove_data'] ) {
			wp_die( esc_html__( 'The retention checkbox is invalid.', 'coocadev-releasego' ), '', array( 'response' => 400 ) );
		}
		update_option( 'ccrg_remove_data', isset( $_POST['remove_data'] ), false );
		wp_safe_redirect( add_query_arg( 'page', 'ccrg-settings', admin_url( 'options-general.php' ) ) );
		exit;
	}

	/** Core retains author attribution/revisions as for ordinary editorial content. */
	public static function privacy(): void {
		wp_add_privacy_policy_content( 'Coocadev ReleaseGo', '<p>' . esc_html__( 'ReleaseGo stores release notes as WordPress posts with author IDs, dates, revisions, product terms and version/category metadata. Published notes may appear on public pages through a shortcode. Drafts, private entries and password-protected entries are excluded. Avoid personal or confidential information in published notes. Core user/content privacy and site retention procedures apply; no data is sent to Coocadev. Deactivation and default uninstall retain history. Explicit uninstall removal deletes only ReleaseGo content, its product taxonomy and settings.', 'coocadev-releasego' ) . '</p>' );
	}
}
