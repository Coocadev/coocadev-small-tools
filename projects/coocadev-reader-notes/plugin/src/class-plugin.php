<?php
/**
 * WordPress integration, neutral UI shells, settings and privacy lifecycle.
 *
 * Registers neutral shells, scoped assets, settings and privacy hooks.
 * Page-cacheable HTML must contain no personal text, user ID or nonce.
 * The browser obtains session state separately and rechecks it before
 * showing private notes after visibility or navigation boundaries.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevReaderNotes
 */

namespace Coocadev\ReaderNotes;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin module for private reader notes.
 */
final class Plugin {
	/**
	 * Whether neutral asset configuration has been enqueued.
	 *
	 * @var bool
	 */
	private static bool $configured = false;

	/**
	 * Connect plugin features to the relevant WordPress lifecycle hooks.
	 *
	 * @return void Operation result.
	 */
	public static function boot(): void {
		add_action( 'init', array( self::class, 'init' ) );
		add_action( 'rest_api_init', array( Rest::class, 'register' ) );
		add_filter( 'rest_post_dispatch', array( Rest::class, 'headers' ), 10, 3 );
		add_filter(
			'rest_pre_serve_request',
			static function ( bool $served, mixed $response, \WP_REST_Request $request ): bool {
				if ( str_starts_with( $request->get_route(), '/' . Rest::NS . '/' ) ) {
					header_remove( 'Access-Control-Allow-Origin' );
					header_remove( 'Access-Control-Allow-Credentials' );
				}
				return $served;
			},
			20,
			3
		);
		add_action( 'wp_ajax_ccrn_bootstrap', array( Rest::class, 'bootstrap' ) );
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_init', array( self::class, 'admin_init' ) );
		add_action( 'admin_post_ccrn_settings', array( self::class, 'save_settings' ) );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_filter( 'the_content', array( self::class, 'automatic' ), 20 );
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
		add_action( 'before_delete_post', array( self::class, 'detach' ) );
		add_action( 'delete_user', array( self::class, 'delete_user' ) );
		add_action( 'remove_user_from_blog', array( self::class, 'remove_member' ), 10, 2 );
		add_action( 'add_user_to_blog', array( self::class, 'add_member' ), 10, 3 );
	}

	/**
	 * Read settings with an unlimited default owner quota.
	 *
	 * @return array Operation result.
	 */
	public static function settings(): array {
		$stored = get_option( 'ccrn_settings', array() );
		return array_merge(
			array(
				'post_types'  => array( 'post', 'page' ),
				'automatic'   => false,
				'quota'       => null,
				'delete_data' => false,
				'revision'    => 0,
			),
			is_array( $stored ) ? $stored : array()
		);
	}

	/**
	 * Register local assets, dynamic blocks and classic shortcodes.
	 *
	 * @return void Operation result.
	 */
	public static function init(): void {
		wp_register_style( 'ccrn-notes', plugins_url( 'assets/notes.css', FILE ), array(), VERSION );
		wp_register_script( 'ccrn-notes', plugins_url( 'assets/notes.js', FILE ), array(), VERSION, true );
		wp_register_script( 'ccrn-blocks', plugins_url( 'assets/blocks.js', FILE ), array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor' ), VERSION, true );
		wp_set_script_translations( 'ccrn-blocks', 'coocadev-reader-notes' );
		register_block_type( dirname( FILE ) . '/blocks/note', array( 'render_callback' => array( self::class, 'note_block' ) ) );
		register_block_type( dirname( FILE ) . '/blocks/list', array( 'render_callback' => array( self::class, 'list_block' ) ) );
		add_shortcode( 'ccrn_note', array( self::class, 'shortcode' ) );
		add_shortcode( 'ccrn_my_notes', array( self::class, 'list_block' ) );
	}

	/**
	 * Enqueue only the feature's assets with entirely non-personal configuration.
	 *
	 * @return void Operation result.
	 */
	private static function assets(): void {
		wp_enqueue_style( 'ccrn-notes' );
		wp_enqueue_script( 'ccrn-notes' );
		if ( self::$configured ) {
			return;
		}
		self::$configured = true;
		// No user ID, personal text or nonce may enter this page-cacheable configuration.
		wp_localize_script(
			'ccrn-notes',
			'ccrnConfig',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'api'     => rest_url( Rest::NS . '/' ),
				'login'   => wp_login_url(),
				'strings' => array(
					'loading'        => __( 'Loading your notes…', 'coocadev-reader-notes' ),
					'login'          => __( 'Sign in to keep your notes', 'coocadev-reader-notes' ),
					'private'        => __( 'Only you can access these notes through this plugin. Site and database administrators can access stored data.', 'coocadev-reader-notes' ),
					'label'          => __( 'My private note', 'coocadev-reader-notes' ),
					'save'           => __( 'Save', 'coocadev-reader-notes' ),
					'saved'          => __( 'Saved', 'coocadev-reader-notes' ),
					'unsaved'        => __( 'Unsaved changes', 'coocadev-reader-notes' ),
					'saving'         => __( 'Saving…', 'coocadev-reader-notes' ),
					'delete'         => __( 'Delete note', 'coocadev-reader-notes' ),
					'confirmDelete'  => __( 'Delete this note permanently?', 'coocadev-reader-notes' ),
					'confirmAll'     => __( 'Permanently delete all your notes? This cannot be undone.', 'coocadev-reader-notes' ),
					'erase'          => __( 'Delete all my notes', 'coocadev-reader-notes' ),
					'export'         => __( 'Export text', 'coocadev-reader-notes' ),
					'exportAll'      => __( 'Export all notes', 'coocadev-reader-notes' ),
					'copy'           => __( 'Copy my text', 'coocadev-reader-notes' ),
					'copied'         => __( 'Copied', 'coocadev-reader-notes' ),
					'retry'          => __( 'Retry', 'coocadev-reader-notes' ),
					'error'          => __( 'Could not complete the request. Please retry.', 'coocadev-reader-notes' ),
					'conflict'       => __( 'This note changed in another tab. Compare the saved text below before choosing.', 'coocadev-reader-notes' ),
					'load'           => __( 'Load saved version', 'coocadev-reader-notes' ),
					'replace'        => __( 'Replace saved version', 'coocadev-reader-notes' ),
					'confirmReplace' => __( 'Replace the saved text with your current text?', 'coocadev-reader-notes' ),
					'search'         => __( 'Search my notes', 'coocadev-reader-notes' ),
					'next'           => __( 'Load more', 'coocadev-reader-notes' ),
					'unavailable'    => __( 'Source no longer available', 'coocadev-reader-notes' ),
					'empty'          => __( 'You have no notes here yet.', 'coocadev-reader-notes' ),
					'new'            => __( 'Not saved yet', 'coocadev-reader-notes' ),
					'paused'         => __( 'Your notes are hidden until your session can be checked. Retry when online.', 'coocadev-reader-notes' ),
					'erasing'        => __( 'Erasure is in progress. Continue deleting to finish.', 'coocadev-reader-notes' ),
					'readonly'       => __( 'Notes are read-only. The site administrator should check database storage.', 'coocadev-reader-notes' ),
					'limit'          => __( 'Maximum 10,000 characters.', 'coocadev-reader-notes' ),
				),
			)
		);
	}

	/**
	 * Return a cacheable neutral shell with no personal note or nonce.
	 *
	 * @param string $mode Mode.
	 * @param int    $post_id Post id.
	 * @return string Operation result.
	 */
	private static function shell( string $mode, int $post_id = 0 ): string {
		self::assets();
		return '<section class="ccrn-shell" data-ccrn-mode="' . esc_attr( $mode ) . '" data-ccrn-post="' . esc_attr( (string) $post_id ) . '"><p>' . esc_html__( 'Your notes load privately after checking your sign-in.', 'coocadev-reader-notes' ) . '</p><noscript>' . esc_html__( 'JavaScript is required for private notes and conflict-safe saving.', 'coocadev-reader-notes' ) . '</noscript></section>';
	}

	/**
	 * Bind a note shell to the source article in block context.
	 *
	 * @param array          $attributes Attributes.
	 * @param string         $content Content.
	 * @param \WP_Block|null $block Block.
	 * @return string Operation result.
	 */
	public static function note_block( array $attributes = array(), string $content = '', ?\WP_Block $block = null ): string {
		$post_id = (int) ( $block?->context['postId'] ?? get_the_ID() );
		return $post_id > 0 ? self::shell( 'note', $post_id ) : '';
	}

	/**
	 * Bind a classic note shell to the current singular article.
	 *
	 * @return string Operation result.
	 */
	public static function shortcode(): string {
		// Always bind to the current queried article, never a client-supplied owner.
		return is_singular() ? self::shell( 'note', get_queried_object_id() ) : '';
	}

	/**
	 * Return a cacheable shell for the reader's personal note list.
	 *
	 * @return string Operation result.
	 */
	public static function list_block(): string {
		return self::shell( 'list' );
	}

	/**
	 * Append an opted-in note shell only to supported singular content.
	 *
	 * @param string $content Content.
	 * @return string Operation result.
	 */
	public static function automatic( string $content ): string {
		if ( self::settings()['automatic'] && is_singular( self::settings()['post_types'] ) && in_the_loop() && is_main_query() && ! has_block( 'coocadev-reader-notes/note' ) && ! has_shortcode( $content, 'ccrn_note' ) ) {
			$content .= self::shell( 'note', get_the_ID() );
		}
		return $content;
	}

	/**
	 * Register settings and personal notes screens with distinct capabilities.
	 *
	 * @return void Operation result.
	 */
	public static function menu(): void {
		add_options_page( __( 'Reader Notes', 'coocadev-reader-notes' ), __( 'Reader Notes', 'coocadev-reader-notes' ), 'manage_options', 'ccrn-settings', array( self::class, 'settings_page' ) );
		$notes_hook = add_users_page( __( 'My notes', 'coocadev-reader-notes' ), __( 'My notes', 'coocadev-reader-notes' ), 'read', 'ccrn-my-notes', array( self::class, 'my_notes' ) );
		add_action( 'load-' . $notes_hook, array( Rest::class, 'no_cache' ) );
	}

	/**
	 * Describe the plugin's real data handling to the privacy-policy editor.
	 *
	 * @return void Operation result.
	 */
	public static function admin_init(): void {
		// Use a Settings API nonce form with a dedicated atomic revision-aware POST handler.
		// Deliberately do not allow options.php to bypass that compare-and-swap handler.
		wp_add_privacy_policy_content( 'Coocadev Reader Notes', wpautop( esc_html__( 'When signed-in readers save notes, this site stores their user ID, the source article ID, note text and save dates. Notes are private to that reader in the plugin interface, but site/database administrators and backup operators can access storage. No data is sent to Coocadev or external services. Notes remain until the reader deletes them, a confirmed privacy erasure is processed, or the account is removed. Deleting a source article keeps the reader’s text without its source link. Site backups are controlled by the site owner. The plugin stores no note text in cookies or browser persistent storage.', 'coocadev-reader-notes' ) ) );
	}

	/**
	 * Show storage diagnostics only on the plugin settings screen.
	 *
	 * @return void Operation result.
	 */
	public static function notice(): void {
		$screen = get_current_screen();
		if ( current_user_can( 'manage_options' ) && $screen && 'settings_page_ccrn-settings' === $screen->id && ! Database::healthy() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Reader Notes storage is not transactional. Writing is disabled. Restore the plugin’s two InnoDB tables or reactivate after fixing database permissions.', 'coocadev-reader-notes' ) . '</p></div>';
		}
	}

	/**
	 * Render escaped administrator settings and an authenticated form.
	 *
	 * @return void Operation result.
	 */
	public static function settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = self::settings();
		echo '<div class="wrap"><h1>' . esc_html__( 'Reader Notes', 'coocadev-reader-notes' ) . '</h1><p>' . esc_html__( 'Insert the Reader Note or My Notes block, or use [ccrn_note] and [ccrn_my_notes]. Notes use normal WordPress access rules; membership systems require a tested deny-only integration.', 'coocadev-reader-notes' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		settings_fields( 'ccrn_settings' );
		echo '<input type="hidden" name="action" value="ccrn_settings"><input type="hidden" name="revision" value="' . esc_attr( (string) $settings['revision'] ) . '">';
		echo '<fieldset><legend>' . esc_html__( 'Allowed source types', 'coocadev-reader-notes' ) . '</legend>';
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			echo '<p><label><input type="checkbox" name="post_types[]" value="' . esc_attr( $type->name ) . '" ' . checked( in_array( $type->name, $settings['post_types'], true ), true, false ) . '> ' . esc_html( $type->labels->name ) . '</label></p>';
		}
		echo '</fieldset><p><label><input type="checkbox" name="automatic" value="1" ' . checked( $settings['automatic'], true, false ) . '> ' . esc_html__( 'Automatically show notes after article content', 'coocadev-reader-notes' ) . '</label></p>';
		echo '<p><label for="ccrn-quota">' . esc_html__( 'Notes per user (leave blank for no quota)', 'coocadev-reader-notes' ) . '</label><br><input id="ccrn-quota" type="number" min="1" name="quota" value="' . esc_attr( null === $settings['quota'] ? '' : (string) $settings['quota'] ) . '"></p>';
		echo '<p>' . esc_html__( 'This optional site storage policy is free to change or remove. Lowering it never deletes existing notes or blocks editing them.', 'coocadev-reader-notes' ) . '</p>';
		echo '<p><label><input type="checkbox" name="delete_data" value="1" ' . checked( $settings['delete_data'], true, false ) . '> ' . esc_html__( 'Delete all plugin data when uninstalling (permanent)', 'coocadev-reader-notes' ) . '</label></p>';
		submit_button();
		echo '</form></div>';
	}

	/**
	 * Validate and atomically replace administrator settings by revision.
	 *
	 * @return void Operation result.
	 */
	public static function save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot change these settings.', 'coocadev-reader-notes' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ccrn_settings-options' );
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'post' !== $method ) {
			wp_die( esc_html__( 'Use a POST request.', 'coocadev-reader-notes' ), '', array( 'response' => 405 ) );
		}
		$revision = isset( $_POST['revision'] ) && is_string( $_POST['revision'] ) ? sanitize_text_field( wp_unslash( $_POST['revision'] ) ) : '';
		$quota    = isset( $_POST['quota'] ) && is_string( $_POST['quota'] ) ? sanitize_text_field( wp_unslash( $_POST['quota'] ) ) : null;
		$types    = isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] ) ? map_deep( wp_unslash( $_POST['post_types'] ), 'sanitize_key' ) : array();
		if ( count( array_filter( $types, 'is_string' ) ) !== count( $types ) ) {
			wp_die( esc_html__( 'Source types must be a flat list of names.', 'coocadev-reader-notes' ), '', array( 'response' => 400 ) );
		}
		$allowed = array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) );
		if ( ! preg_match( '/^[0-9]{1,9}$/', $revision ) || null === $quota || ( '' !== $quota && ! preg_match( '/^[1-9][0-9]{0,8}$/', $quota ) ) || array_diff( $types, $allowed ) ) {
			wp_die( esc_html__( 'Invalid settings. Use a positive quota or leave it blank.', 'coocadev-reader-notes' ), '', array( 'response' => 400 ) );
		}
		foreach ( array( 'automatic', 'delete_data' ) as $flag ) {
			if ( isset( $_POST[ $flag ] ) && ( ! is_string( $_POST[ $flag ] ) || '1' !== sanitize_text_field( wp_unslash( $_POST[ $flag ] ) ) ) ) {
				wp_die( esc_html__( 'Invalid checkbox value.', 'coocadev-reader-notes' ), '', array( 'response' => 400 ) );
			}
		}
		$next = array(
			'post_types'  => array_values( array_unique( $types ) ),
			'automatic'   => isset( $_POST['automatic'] ),
			'quota'       => '' === $quota ? null : (int) $quota,
			'delete_data' => isset( $_POST['delete_data'] ),
			'revision'    => (int) $revision + 1,
		);
		$old  = get_option( 'ccrn_settings', null );
		if ( (int) self::settings()['revision'] !== (int) $revision ) {
			wp_die( esc_html__( 'Settings changed in another tab. Reload before saving.', 'coocadev-reader-notes' ), '', array( 'response' => 409 ) );
		}
		global $wpdb;
		if ( null === $old ) {
			$ok = add_option( 'ccrn_settings', $next, '', true );
		} else {
			$ok = 1 === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, maybe_serialize( $next ), 'ccrn_settings', maybe_serialize( $old ) ) );
			wp_cache_delete( 'ccrn_settings', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}
		if ( ! $ok ) {
			wp_die( esc_html__( 'Settings changed or could not be saved. Reload before trying again.', 'coocadev-reader-notes' ), '', array( 'response' => 409 ) );
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=ccrn-settings' ) );
		exit;
	}

	/**
	 * Render the authenticated personal-management page's neutral shell.
	 *
	 * @return void Operation result.
	 */
	public static function my_notes(): void {
		if ( ! Access::member( get_current_user_id() ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'My notes', 'coocadev-reader-notes' ) . '</h1>';
		echo wp_kses(
			self::shell( 'list' ),
			array(
				'section'  => array(
					'class'          => true,
					'data-ccrn-mode' => true,
					'data-ccrn-post' => true,
				),
				'p'        => array(),
				'noscript' => array(),
			)
		);
		echo '</div>';
	}

	/**
	 * Register the WordPress personal-data exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array Operation result.
	 */
	public static function exporters( array $exporters ): array {
		$exporters['ccrn-notes'] = array(
			'exporter_friendly_name' => __( 'Reader Notes', 'coocadev-reader-notes' ),
			'callback'               => array( self::class, 'privacy_export' ),
		);
		return $exporters;
	}

	/**
	 * Export only the requested subject's notes and permitted source metadata.
	 *
	 * @param string $email Email.
	 * @param int    $page Page.
	 * @return array Operation result.
	 */
	public static function privacy_export( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user || ! Access::member( $user->ID ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY id ASC LIMIT 50 OFFSET %d', Database::table(), $user->ID, max( 0, $page - 1 ) * 50 ), ARRAY_A );
		$data = array();
		foreach ( $rows as $row ) {
			$note   = Access::public_note( $row, $user->ID, true );
			$fields = array(
				array(
					'name'  => __( 'Note', 'coocadev-reader-notes' ),
					'value' => esc_html( $note['content'] ),
				),
				array(
					'name'  => __( 'Updated (UTC)', 'coocadev-reader-notes' ),
					'value' => esc_html( $note['updated_at_gmt'] ),
				),
			);
			if ( $note['source'] ) {
				$fields[] = array(
					'name'  => __( 'Source', 'coocadev-reader-notes' ),
					'value' => esc_url( $note['source']['url'] ),
				);
			}
			$data[] = array(
				'group_id'    => 'ccrn-notes',
				'group_label' => __( 'Reader Notes', 'coocadev-reader-notes' ),
				'item_id'     => 'ccrn-note-' . $note['id'],
				'data'        => $fields,
			);
		}
		return array(
			'data' => $data,
			'done' => count( $rows ) < 50,
		);
	}

	/**
	 * Register the WordPress personal-data eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array Operation result.
	 */
	public static function erasers( array $erasers ): array {
		$erasers['ccrn-notes'] = array(
			'eraser_friendly_name' => __( 'Reader Notes', 'coocadev-reader-notes' ),
			'callback'             => array( self::class, 'privacy_erase' ),
		);
		return $erasers;
	}

	/**
	 * Continue bounded erasure while preserving the stale-write barrier.
	 *
	 * @param string $email Email.
	 * @param int    $page Page.
	 * @return array Operation result.
	 */
	public static function privacy_erase( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}
		$result = Database::erase( $user->ID, null, null, 1 === $page );
		if ( is_wp_error( $result ) ) {
			return array(
				'items_removed'  => false,
				'items_retained' => true,
				'messages'       => array( $result->get_error_message() ),
				'done'           => false,
			);
		}
		return array(
			'items_removed'  => $result['removed'] > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => $result['done'],
		);
	}

	/**
	 * Keep note text while removing a permanently deleted source link.
	 *
	 * @param int $post_id Post id.
	 * @return void Operation result.
	 */
	public static function detach( int $post_id ): void {
		global $wpdb;
		$wpdb->update( Database::table(), array( 'post_id' => null ), array( 'post_id' => $post_id ), array( '%d' ), array( '%d' ) );
	}

	/**
	 * Erase account notes and keep a write-blocking epoch tombstone.
	 *
	 * @param int $user_id User id.
	 * @return void Operation result.
	 */
	public static function delete_user( int $user_id ): void {
		$result = Database::account( $user_id );
		if ( is_wp_error( $result ) ) {
			// Run before Core removes the account: failure must not strand private text without its owner.
			wp_die( esc_html__( 'Reader Notes could not safely erase this account’s notes. Restore its database storage and retry removing the account.', 'coocadev-reader-notes' ), '', array( 'response' => 503 ) );
		}
	}

	/**
	 * Erase notes in the site whose membership is being removed.
	 *
	 * @param int $user_id User id.
	 * @param int $blog_id Blog id.
	 * @return void Operation result.
	 */
	public static function remove_member( int $user_id, int $blog_id ): void {
		$switched = get_current_blog_id() !== $blog_id;
		if ( $switched ) {
			switch_to_blog( $blog_id );
		}
		// Core normally switches to the target site before this hook; that site may never have installed Reader Notes.
		if ( get_option( 'ccrn_schema_version' ) ) {
			self::delete_user( $user_id );
		}
		if ( $switched ) {
			restore_current_blog();
		}
	}

	/**
	 * Allow a newly restored member to start a fresh notes epoch.
	 *
	 * @param int    $user_id User id.
	 * @param string $role Assigned role.
	 * @param int    $blog_id Blog id.
	 * @return void
	 */
	public static function add_member( int $user_id, string $role, int $blog_id ): void {
		$switched = get_current_blog_id() !== $blog_id;
		if ( $switched ) {
			switch_to_blog( $blog_id );
		}
		if ( get_option( 'ccrn_schema_version' ) ) {
			Database::account( $user_id, true );
		}
		if ( $switched ) {
			restore_current_blog();
		}
	}
}
