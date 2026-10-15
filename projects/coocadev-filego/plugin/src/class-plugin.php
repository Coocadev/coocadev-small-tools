<?php
/**
 * Hook wiring and public download aliases.
 *
 * Connects lifecycle hooks and the deliberately public download alias.
 * The alias is a no-store temporary redirect rather than a PDF stream.
 * Public reads do not require an admin nonce, but must still validate
 * the current site attachment graph before exposing a target URL.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevFileGo
 */

namespace Coocadev\FileGo;

defined( 'ABSPATH' ) || exit;

/**
 * Site-scoped FileGo Plugin component.
 */
final class Plugin {
	/**
	 * Install site-local tables and capabilities; reject unsupported network activation.
	 *
	 * @param bool $network_wide Whether network activation was requested.
	 */
	public static function activate( bool $network_wide ): void {
		if ( $network_wide ) {
			wp_die( esc_html__( 'Activate FileGo separately on each site. Network activation is not supported.', 'coocadev-filego' ) );
		}
		if ( ! Store::install() ) {
			wp_die( esc_html__( 'FileGo needs two InnoDB tables. Ask your hosting provider to enable transactional storage.', 'coocadev-filego' ) );
		}
		self::rewrite();
		flush_rewrite_rules( false );
	}

	/**
	 * Remove the active alias rewrite rule while retaining documents and files.
	 */
	public static function deactivate(): void {
		global $wp_rewrite;
		unset( $wp_rewrite->extra_rules_top['^filego/([a-z0-9-]+)/?$'] );
		flush_rewrite_rules( false );
	}

	/**
	 * Attach runtime handlers without performing front-end database work.
	 */
	public static function boot(): void {
		add_action( 'init', array( self::class, 'rewrite' ) );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_action( 'template_redirect', array( self::class, 'resolve' ), 0 );
		add_filter( 'pre_delete_attachment', array( Documents::class, 'protect_attachment' ), 10, 3 );
		add_filter( 'pre_trash_post', array( Documents::class, 'protect_trash' ), 10, 2 );
		Admin::register();
		Privacy::register();
	}

	/**
	 * Register the optional pretty permanent-link route.
	 */
	public static function rewrite(): void {
		add_rewrite_rule( '^filego/([a-z0-9-]+)/?$', 'index.php?ccfg_download=$matches[1]', 'top' );
	}

	/**
	 * Allow the explicit public document query variable.
	 *
	 * @param array $vars Existing public query variables.
	 * @return array Operation result.
	 */
	public static function query_vars( array $vars ): array {
		$vars[] = 'ccfg_download';
		return $vars;
	}

	/**
	 * Build the pretty or plain permanent URL using the site configuration.
	 *
	 * @param string $slug Unique lower-case permanent-link identifier.
	 * @return string Operation result.
	 */
	public static function url( string $slug ): string {
		return get_option( 'permalink_structure' ) ? home_url( '/filego/' . rawurlencode( $slug ) . '/' ) : add_query_arg( 'ccfg_download', $slug, home_url( '/' ) );
	}

	/**
	 * Resolve the live database pointer without persistent caching or PDF body reads.
	 *
	 * @param string $slug Unique lower-case permanent-link identifier.
	 * @return string|\WP_Error Operation result.
	 */
	public static function target( string $slug ): string|\WP_Error {
		global $wpdb;
		if ( ! preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,98}[a-z0-9])?$/D', $slug ) ) {
			return Documents::failure( 'not_found', __( 'Document not found.', 'coocadev-filego' ), 404 );
		}
		$version = $wpdb->get_row( $wpdb->prepare( "SELECT v.* FROM %i d INNER JOIN %i v ON v.id = d.current_version_id AND v.document_id = d.id WHERE d.slug = %s AND d.status = 'published' AND v.state IN ('published','retired') LIMIT 1", Store::table( 'documents' ), Store::table( 'versions' ), $slug ) );
		if ( ! $version || is_wp_error( Documents::ownership( $version ) ) ) {
			return Documents::failure( 'not_found', __( 'Document not found.', 'coocadev-filego' ), 404 );
		}
		return (string) wp_get_attachment_url( (int) $version->attachment_id );
	}

	/**
	 * Serve a public GET or HEAD redirect with no-store, or a controlled 404/405.
	 */
	public static function resolve(): void {
		$value = get_query_var( 'ccfg_download', null );
		if ( null === $value ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'X-Robots-Tag: noindex' );
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( ! in_array( $method, array( 'get', 'head' ), true ) ) {
			status_header( 405 );
			header( 'Allow: GET, HEAD' );
			exit;
		}
		$target = is_string( $value ) ? self::target( $value ) : new \WP_Error( 'invalid' );
		if ( is_wp_error( $target ) ) {
			status_header( 404 );
			if ( 'head' !== $method ) {
				echo esc_html__( 'Document not found.', 'coocadev-filego' );
			}
			exit;
		}
		wp_safe_redirect( $target, 302, 'Coocadev FileGo' );
		exit;
	}
}
