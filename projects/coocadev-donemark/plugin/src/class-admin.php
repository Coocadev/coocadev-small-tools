<?php
/**
 * Scoped list-table, quick-edit, bulk and settings UI.
 *
 * Integrates native post lists without adding public frontend assets.
 * Batch priming is limited to the main editing list to avoid N+1 reads
 * and unrelated query changes. Quick Edit saves Core content first;
 * only changed marks are then checked against the persisted fingerprint.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevDoneMark
 */

namespace Coocadev\DoneMark;

defined( 'ABSPATH' ) || exit;

/**
 * Site-scoped DoneMark Admin component.
 */
final class Admin {
	/**
	 * Request-local view data for the visible list only.
	 *
	 * @var array
	 */
	private static array $views = array();

	/**
	 * Register scoped WordPress hooks for this component.
	 */
	public static function register(): void {
		add_action(
			'admin_menu',
			static function () {
				add_options_page( __( 'DoneMark', 'coocadev-donemark' ), __( 'DoneMark', 'coocadev-donemark' ), 'manage_options', 'ccdm-settings', array( self::class, 'settings_page' ) );
			}
		);
		add_action( 'admin_post_ccdm_save', array( self::class, 'save_settings' ) );
		add_action( 'admin_post_ccdm_delete', array( self::class, 'delete_mark' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'current_screen', array( self::class, 'screen' ) );
		add_action( 'wp_ajax_inline-save', array( self::class, 'inline_screen' ), 0 );
		add_filter( 'the_posts', array( self::class, 'prime' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( self::class, 'filters' ), 10, 2 );
		add_filter( 'posts_where', array( self::class, 'where' ), 10, 2 );
	}

	/**
	 * Restore private column hooks for Core's Quick Edit replacement row.
	 */
	public static function inline_screen(): void {
		check_ajax_referer( 'inlineeditnonce', '_inline_edit' );
		$id   = isset( $_POST['post_ID'] ) && is_string( $_POST['post_ID'] ) ? absint( wp_unslash( $_POST['post_ID'] ) ) : 0;
		$post = $id ? get_post( $id ) : null;
		if ( $post && current_user_can( 'edit_post', $id ) ) {
			self::screen( convert_to_screen( 'edit-' . $post->post_type ) );
		}
	}

	/**
	 * Add native columns and Quick Edit only to applicable editing lists.
	 *
	 * @param mixed $screen Validated operation input or WordPress hook value.
	 */
	public static function screen( $screen ): void {
		if ( 'edit' !== $screen->base || ! Settings::applicable( $screen->post_type ) ) {
			return;
		}
		add_filter(
			'manage_' . $screen->post_type . '_posts_columns',
			static function ( $columns ) {
				$columns['ccdm_marks'] = __( 'DoneMark', 'coocadev-donemark' );
				return $columns;
			}
		);
		add_action( 'manage_' . $screen->post_type . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
		add_action( 'quick_edit_custom_box', array( self::class, 'quick_edit' ), 10, 2 );
	}

	/**
	 * Batch-fetch authoritative states only for the current main editing list.
	 *
	 * @param array $posts Validated operation input or WordPress hook value.
	 * @param mixed $query Validated operation input or WordPress hook value.
	 * @return array Operation result.
	 */
	public static function prime( array $posts, $query ): array {
		if ( ! is_admin() || ! $query->is_main_query() || 'edit.php' !== ( $GLOBALS['pagenow'] ?? '' ) || ! $posts ) {
			return $posts;
		}
		global $wpdb;
		$type = $query->get( 'post_type' );
		$type = $type ? $type : 'post';
		if ( ! is_string( $type ) || ! Settings::applicable( $type ) ) {
			return $posts;
		}
		$ids          = array_map( 'intval', wp_list_pluck( $posts, 'ID' ) );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only generated %d placeholders are interpolated.
		$persisted = $wpdb->get_results( $wpdb->prepare( "SELECT ID,post_type,post_title,post_content,post_excerpt FROM %i WHERE ID IN ($placeholders)", array_merge( array( $wpdb->posts ), $ids ) ), ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table and generated integer placeholders.
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE post_id IN ($placeholders)", array_merge( array( $wpdb->prefix . 'ccdm_states' ), $ids ) ), ARRAY_A );
		$by_post = array();
		$users   = array();
		foreach ( $rows as $row ) {
			$by_post[ $row['post_id'] ][] = $row;
			if ( $row['actor_user_id'] ) {
				$users[] = (int) $row['actor_user_id'];
			}
		}
		if ( $users ) {
			cache_users( array_unique( $users ) );
		}
		$definitions = Settings::read()['definitions'];
		foreach ( $persisted as $post ) {
			$marks                            = array_values( array_filter( $definitions, static fn( $mark ) => ! $mark['archived'] && in_array( $post['post_type'], $mark['post_types'], true ) ) );
			self::$views[ (int) $post['ID'] ] = States::compose( $post, $by_post[ $post['ID'] ] ?? array(), $marks );
		}
		return $posts;
	}

	/**
	 * Translate the current review or distribution state.
	 *
	 * @param array $mark Validated operation input or WordPress hook value.
	 * @return string Operation result.
	 */
	public static function label( array $mark ): string {
		if ( $mark['stale'] ) {
			return __( 'Needs review', 'coocadev-donemark' );
		}
		if ( 'review' === $mark['type'] ) {
			return $mark['done'] ? __( 'Matches reviewed version', 'coocadev-donemark' ) : __( 'Not reviewed', 'coocadev-donemark' );
		}
		return $mark['done'] ? __( 'Done', 'coocadev-donemark' ) : __( 'Not done', 'coocadev-donemark' );
	}

	/**
	 * Render private escaped mark controls for one editable post.
	 *
	 * @param string $column Validated operation input or WordPress hook value.
	 * @param int    $id Validated operation input or WordPress hook value.
	 */
	public static function column( string $column, int $id ): void {
		if ( 'ccdm_marks' !== $column || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		$view = self::$views[ $id ] ?? States::view( $id );
		if ( is_wp_error( $view ) ) {
			return;
		}
		echo '<div class="ccdm-marks" data-post="' . esc_attr( (string) $id ) . '" data-state="' . esc_attr( wp_json_encode( $view ) ) . '">';
		foreach ( $view['marks'] as $index => $mark ) {
			if ( 3 === $index ) {
				echo '<details><summary>' . esc_html__( 'More marks', 'coocadev-donemark' ) . '</summary>';
			}
			$text = $mark['label'] . ': ' . self::label( $mark );
			echo '<button type="button" class="button-link ccdm-toggle" data-mark="' . esc_attr( $mark['id'] ) . '" aria-pressed="' . esc_attr( $mark['done'] && ! $mark['stale'] ? 'true' : 'false' ) . '">' . esc_html( $text ) . '</button>';
			if ( $mark['changed_at_gmt'] ) {
				echo '<small>' . esc_html( $mark['actor'] . ' · ' . $mark['changed_at_gmt'] . ' UTC' ) . '</small>';
			}
		}
		if ( count( $view['marks'] ) > 3 ) {
			echo '</details>';
		}
		echo '<span class="ccdm-status" role="status" aria-live="polite"></span></div>';
	}

	/**
	 * Render the native Quick Edit mark field container.
	 *
	 * @param string $column Validated operation input or WordPress hook value.
	 * @param string $post_type Validated operation input or WordPress hook value.
	 */
	public static function quick_edit( string $column, string $post_type ): void {
		if ( 'ccdm_marks' === $column && Settings::applicable( $post_type ) ) {
			echo '<fieldset class="inline-edit-col-right"><div class="inline-edit-col ccdm-quick"><strong>' . esc_html__( 'DoneMark — changes apply after Save', 'coocadev-donemark' ) . '</strong><div class="ccdm-quick-fields"></div><p>' . esc_html__( 'Only checked changes are submitted. Review covers saved title, content and excerpt.', 'coocadev-donemark' ) . '</p></div></fieldset>';
		}
	}

	/**
	 * Load local assets only on relevant administration screens.
	 *
	 * @param string $hook Validated operation input or WordPress hook value.
	 */
	public static function enqueue( string $hook ): void {
		$screen = get_current_screen();
		if ( 'settings_page_ccdm-settings' === $hook ) {
			wp_enqueue_style( 'ccdm-admin', plugins_url( 'assets/admin.css', FILE ), array(), VERSION );
			return;
		}
		if ( 'edit.php' !== $hook || ! $screen || ! Settings::applicable( $screen->post_type ) ) {
			return;
		}
		wp_enqueue_style( 'ccdm-admin', plugins_url( 'assets/admin.css', FILE ), array(), VERSION );
		wp_enqueue_script( 'ccdm-admin', plugins_url( 'assets/admin.js', FILE ), array( 'jquery', 'inline-edit-post', 'wp-i18n' ), VERSION, true );
		wp_set_script_translations( 'ccdm-admin', 'coocadev-donemark' );
		wp_localize_script(
			'ccdm-admin',
			'ccdmAdmin',
			array(
				'root'        => esc_url_raw( rest_url( 'coocadev-donemark/v1/' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'definitions' => Settings::applicable( $screen->post_type ),
			)
		);
	}

	/**
	 * Read a scalar read-only native list filter.
	 *
	 * @param string $key Validated operation input or WordPress hook value.
	 * @return string Operation result.
	 */
	private static function get_filter( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only native list filtering.
		return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	/**
	 * Render accessible native mark filters above the editing list.
	 *
	 * @param string $post_type Validated operation input or WordPress hook value.
	 * @param string $which Validated operation input or WordPress hook value.
	 */
	public static function filters( string $post_type, string $which ): void {
		$marks = Settings::applicable( $post_type );
		if ( 'top' !== $which || ! $marks ) {
			return;
		}
		echo '<label class="screen-reader-text" for="ccdm-filter-mark">' . esc_html__( 'Filter by mark', 'coocadev-donemark' ) . '</label><select id="ccdm-filter-mark" name="ccdm_mark"><option value="">' . esc_html__( 'All marks', 'coocadev-donemark' ) . '</option>';
		foreach ( $marks as $mark ) {
			echo '<option value="' . esc_attr( $mark['id'] ) . '" ' . selected( self::get_filter( 'ccdm_mark' ), $mark['id'], false ) . '>' . esc_html( $mark['label'] ) . '</option>';
		}
		echo '</select><label class="screen-reader-text" for="ccdm-filter-state">' . esc_html__( 'Mark state', 'coocadev-donemark' ) . '</label><select id="ccdm-filter-state" name="ccdm_state">';
		foreach ( array(
			'done'     => __( 'Done / reviewed', 'coocadev-donemark' ),
			'not_done' => __( 'Not done', 'coocadev-donemark' ),
			'stale'    => __( 'Needs review', 'coocadev-donemark' ),
		) as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( self::get_filter( 'ccdm_state' ), $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Scope indexed mark conditions to the native main editing query.
	 *
	 * @param string $where Validated operation input or WordPress hook value.
	 * @param mixed  $query Validated operation input or WordPress hook value.
	 * @return string Operation result.
	 */
	public static function where( string $where, $query ): string {
		if ( ! is_admin() || ! $query->is_main_query() || 'edit.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) {
			return $where;
		}
		$type = $query->get( 'post_type' );
		$type = $type ? $type : 'post';
		if ( ! is_string( $type ) ) {
			return $where;
		}
		$definitions = array_column( Settings::applicable( $type ), null, 'id' );
		$id          = self::get_filter( 'ccdm_mark' );
		$status      = self::get_filter( 'ccdm_state' );
		if ( ! isset( $definitions[ $id ] ) || ! in_array( $status, array( 'done', 'not_done', 'stale' ), true ) ) {
			return $where;
		}
		global $wpdb;
		$base = $wpdb->prepare( 'SELECT 1 FROM %i ccdm WHERE ccdm.post_id = %i.ID AND ccdm.mark_id = %s AND ccdm.is_done = 1', $wpdb->prefix . 'ccdm_states', $wpdb->posts, $id );
		if ( 'not_done' === $status ) {
			return $where . ' AND NOT EXISTS (' . $base . ')';
		}
		if ( 'review' === $definitions[ $id ]['type'] ) {
			$hash  = $wpdb->prepare( "SHA2(CONCAT('1:',OCTET_LENGTH(%i.post_title),':',%i.post_title,':',OCTET_LENGTH(%i.post_content),':',%i.post_content,':',OCTET_LENGTH(%i.post_excerpt),':',%i.post_excerpt),256)", $wpdb->posts, $wpdb->posts, $wpdb->posts, $wpdb->posts, $wpdb->posts, $wpdb->posts );
			$base .= 'stale' === $status ? ' AND (ccdm.hash_version <> 1 OR ccdm.reviewed_hash IS NULL OR ccdm.reviewed_hash <> ' . $hash . ')' : ' AND ccdm.hash_version = 1 AND ccdm.reviewed_hash = ' . $hash;
		} elseif ( 'stale' === $status ) {
			return $where . ' AND 1=0';
		}
		return $where . ' AND EXISTS (' . $base . ')';
	}

	/**
	 * Require administrator permission, POST and an action nonce.
	 *
	 * @param string $nonce Validated operation input or WordPress hook value.
	 */
	private static function guard( string $nonce ): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( ! current_user_can( 'manage_options' ) || 'post' !== $method ) {
			wp_die( esc_html__( 'You cannot manage DoneMark definitions.', 'coocadev-donemark' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce );
	}

	/**
	 * Read a validated expected settings revision after the form guard.
	 *
	 * @return int Operation result.
	 */
	private static function revision(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only called after guard and the action-specific check_admin_referer in each handler.
		$value = isset( $_POST['revision'] ) && is_string( $_POST['revision'] ) ? sanitize_text_field( wp_unslash( $_POST['revision'] ) ) : '';
		return ctype_digit( $value ) ? (int) $value : 0;
	}

	/**
	 * Validate and save definitions through the protected admin form.
	 */
	public static function save_settings(): void {
		self::guard( 'ccdm_save' );
		check_admin_referer( 'ccdm_save' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Definitions pass a strict typed validator below.
		$input       = isset( $_POST['marks'] ) && is_array( $_POST['marks'] ) ? wp_unslash( $_POST['marks'] ) : array();
		$definitions = array();
		foreach ( $input as $item ) {
			if ( ! is_array( $item ) ) {
				self::finish( new \WP_Error( 'ccdm_input', __( 'Invalid definition form.', 'coocadev-donemark' ) ) );
			}
			if ( '' === ( $item['id'] ?? '' ) && '' === ( $item['label'] ?? '' ) ) {
				continue;
			}
			$definitions[] = array(
				'id'         => $item['id'] ?? '',
				'label'      => $item['label'] ?? '',
				'type'       => $item['type'] ?? '',
				'post_types' => $item['post_types'] ?? array(),
				'archived'   => isset( $item['archived'] ) && '1' === $item['archived'],
			);
		}
		$remove = isset( $_POST['remove_data'] ) && is_string( $_POST['remove_data'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['remove_data'] ) );
		self::finish( Settings::save( $definitions, self::revision(), $remove ) );
	}

	/**
	 * Require explicit confirmation before deleting one definition.
	 */
	public static function delete_mark(): void {
		self::guard( 'ccdm_delete' );
		check_admin_referer( 'ccdm_delete' );
		$id = isset( $_POST['mark_id'] ) && is_string( $_POST['mark_id'] ) ? sanitize_text_field( wp_unslash( $_POST['mark_id'] ) ) : '';
		if ( ! isset( $_POST['confirm'] ) || ! is_string( $_POST['confirm'] ) || '1' !== sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) ) {
			self::finish( new \WP_Error( 'ccdm_confirm', __( 'Confirm permanent deletion first.', 'coocadev-donemark' ) ) );
		}
		self::finish( Settings::delete( $id, self::revision() ) );
	}

	/**
	 * Report an actual failure or redirect after a completed settings change.
	 *
	 * @param mixed $result Validated operation input or WordPress hook value.
	 */
	private static function finish( $result ): void {
		if ( is_wp_error( $result ) ) {
			wp_die(
				esc_html( $result->get_error_message() ),
				esc_html__( 'DoneMark settings', 'coocadev-donemark' ),
				array(
					'response'  => 'ccdm_conflict' === $result->get_error_code() ? 409 : 400,
					'back_link' => true,
				)
			);
		}
		wp_safe_redirect( add_query_arg( 'page', 'ccdm-settings', admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * Render administrator-only definition and data-retention controls.
	 */
	public static function settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = Settings::read();
		$marks    = $settings['definitions'];
		if ( count( $marks ) < 12 ) {
			$marks[] = array(
				'id'         => '',
				'label'      => '',
				'type'       => 'review',
				'post_types' => array( 'post', 'page' ),
				'archived'   => false,
			);
		}
		echo '<div class="wrap ccdm-settings"><h1>' . esc_html__( 'DoneMark', 'coocadev-donemark' ) . '</h1><p>' . esc_html__( 'Review marks track the exact saved title, content and excerpt. Changes to SEO settings, images, categories or dynamic block output are outside that check. Distribution marks stay done when text changes.', 'coocadev-donemark' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ccdm_save"><input type="hidden" name="revision" value="' . esc_attr( (string) $settings['revision'] ) . '">';
		wp_nonce_field( 'ccdm_save' );
		foreach ( $marks as $index => $mark ) {
			echo '<fieldset class="ccdm-definition"><legend><strong>' . esc_html( $mark['label'] ? $mark['label'] : __( 'Add a mark', 'coocadev-donemark' ) ) . '</strong></legend><input type="hidden" name="marks[' . esc_attr( (string) $index ) . '][id]" value="' . esc_attr( $mark['id'] ) . '">';
			echo '<p><label>' . esc_html__( 'Label', 'coocadev-donemark' ) . ' <input type="text" maxlength="40" name="marks[' . esc_attr( (string) $index ) . '][label]" value="' . esc_attr( $mark['label'] ) . '"></label></p><p><label>' . esc_html__( 'Type', 'coocadev-donemark' ) . ' <select name="marks[' . esc_attr( (string) $index ) . '][type]">';
			foreach ( array(
				'review'       => __( 'Review (tracks content)', 'coocadev-donemark' ),
				'distribution' => __( 'Distribution (completed task)', 'coocadev-donemark' ),
			) as $type => $label ) {
				if ( '' === $mark['id'] || $mark['type'] === $type ) {
					echo '<option value="' . esc_attr( $type ) . '" ' . selected( $mark['type'], $type, false ) . '>' . esc_html( $label ) . '</option>';
				}
			}
			echo '</select></label></p><div>';
			foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type => $object ) {
				if ( in_array( $type, array( 'attachment', 'revision', 'nav_menu_item' ), true ) ) {
					continue;
				}
				echo '<label class="ccdm-type"><input type="checkbox" name="marks[' . esc_attr( (string) $index ) . '][post_types][]" value="' . esc_attr( $type ) . '" ' . checked( in_array( $type, $mark['post_types'], true ), true, false ) . '> ' . esc_html( $object->label ) . '</label> ';
			}
			echo '</div><p><label><input type="checkbox" name="marks[' . esc_attr( (string) $index ) . '][archived]" value="1" ' . checked( $mark['archived'], true, false ) . '> ' . esc_html__( 'Archived (hide, keep states)', 'coocadev-donemark' ) . '</label></p></fieldset>';
		}
		echo '<p><label><input type="checkbox" name="remove_data" value="1" ' . checked( $settings['remove_data_on_uninstall'], true, false ) . '> ' . esc_html__( 'Remove DoneMark definitions and states when this plugin is deleted', 'coocadev-donemark' ) . '</label></p>';
		submit_button( __( 'Save marks', 'coocadev-donemark' ) );
		echo '</form><h2>' . esc_html__( 'Permanently delete a mark', 'coocadev-donemark' ) . '</h2>';
		foreach ( $settings['definitions'] as $mark ) {
			echo '<form class="ccdm-delete" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ccdm_delete"><input type="hidden" name="mark_id" value="' . esc_attr( $mark['id'] ) . '"><input type="hidden" name="revision" value="' . esc_attr( (string) $settings['revision'] ) . '">';
			wp_nonce_field( 'ccdm_delete' );
			echo '<label><input type="checkbox" name="confirm" value="1" required> ' . esc_html( $mark['label'] ) . ' — ' . esc_html__( 'delete this mark and all its saved states', 'coocadev-donemark' ) . '</label> ';
			submit_button( __( 'Delete', 'coocadev-donemark' ), 'delete', 'submit', false );
			echo '</form>';
		}
		echo '</div>';
	}
}
