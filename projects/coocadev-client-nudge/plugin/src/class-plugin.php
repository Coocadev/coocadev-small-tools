<?php
/**
 * Private task board and bounded, opt-in reminder worker.
 *
 * @package CoocadevClientNudge
 */

namespace Coocadev\ClientNudge;

defined( 'ABSPATH' ) || exit;

/** Independently scoped product controller and domain operations. */
final class Plugin {
	public const TYPE = 'cccn_task';
	/**
	 * Return a site-specific consent key because user metadata is shared in multisite.
	 *
	 * @return string Validated result of this operation.
	 */
	public static function consent_key(): string {
		return 'cccn_optin_' . get_current_blog_id(); }

	/**
	 * Schedule future opt-in processing without sending activation emails.
	 *
	 * @param bool $network Whether activation was requested for the entire network.
	 */
	public static function activate( bool $network = false ): void {
		if ( $network ) {
			wp_die( esc_html__( 'Activate Client Nudge individually on each site.', 'coocadev-client-nudge' ) ); }
		if ( ! wp_next_scheduled( 'cccn_reminders' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'cccn_reminders' ); }
	}
	/**
	 * Remove the scheduled worker while preserving user data.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'cccn_reminders' ); }
	/**
	 * Register only this product's hooks and shortcode.
	 */
	public static function boot(): void {
		register_post_type(
			self::TYPE,
			array(
				'public'       => false,
				'show_ui'      => false,
				'show_in_rest' => false,
				'supports'     => array( 'title', 'editor' ),
				'rewrite'      => false,
				'map_meta_cap' => false,
				'capabilities' => array_fill_keys( array( 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts', 'create_posts' ), 'manage_options' ),
			)
		);
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_cccn_action', array( self::class, 'handle' ) );
		add_action( 'cccn_reminders', array( self::class, 'reminders' ) );
		add_shortcode( 'cccn_tasks', array( self::class, 'board' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
		add_action( 'deleted_user', array( self::class, 'user_deleted' ) );
		add_action( 'admin_init', array( self::class, 'privacy_policy' ) );
	}
	/**
	 * Register the settings screen with its required capability.
	 */
	public static function menu(): void {
		add_management_page( __( 'Client Nudge', 'coocadev-client-nudge' ), __( 'Client Nudge', 'coocadev-client-nudge' ), 'read', 'cccn-tasks', array( self::class, 'admin' ) ); }
	/**
	 * Read a bounded page of private tasks assigned to one account.
	 *
	 * @param int  $user WordPress account ID on the current site.
	 * @param int  $page One-based page; erasure always reads page one to avoid skipping deleted rows.
	 * @param bool $pending Whether completed tasks must be excluded.
	 * @param bool $all_statuses Whether privacy cleanup must include trash and nonstandard statuses.
	 * @return array Validated result of this operation.
	 */
	public static function tasks( int $user, int $page = 1, bool $pending = false, bool $all_statuses = false ): array {
		$meta = array(
			array(
				'key'     => 'cccn_assignee',
				'value'   => $user,
				'compare' => '=',
			),
		);
		if ( $pending ) {
			$meta[] = array(
				'key'     => 'cccn_done',
				'value'   => '0',
				'compare' => '=',
			); }
		return get_posts(
			array(
				'post_type'        => self::TYPE,
				'post_status'      => $all_statuses ? array_keys( get_post_stati() ) : 'private',
				'posts_per_page'   => 50,
				'paged'            => max( 1, $page ),
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'meta_query'       => $meta,
				'suppress_filters' => false,
			)
		);
	}
	/**
	 * Create a private task only for an administrator and an existing client.
	 *
	 * @param string $title Plain-text task title.
	 * @param string $detail Plain-text task instructions.
	 * @param int    $assignee Existing client account ID.
	 * @param string $due Local calendar date in YYYY-MM-DD format.
	 * @return int|\WP_Error Validated result of this operation.
	 */
	public static function create( string $title, string $detail, int $assignee, string $due ): int|\WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'forbidden', __( 'Not allowed.', 'coocadev-client-nudge' ) ); }
		if ( ! get_user_by( 'id', $assignee ) || ! is_user_member_of_blog( $assignee ) || '' === trim( $title ) || strlen( $title ) > 180 || strlen( $detail ) > 5000 || ! self::date_valid( $due ) ) {
			return new \WP_Error( 'invalid', __( 'Check title, client and due date.', 'coocadev-client-nudge' ) ); }
		return wp_insert_post(
			array(
				'post_type'    => self::TYPE,
				'post_status'  => 'private',
				'post_title'   => sanitize_text_field( $title ),
				'post_content' => sanitize_textarea_field( $detail ),
				'meta_input'   => array(
					'cccn_assignee' => $assignee,
					'cccn_due'      => $due,
					'cccn_done'     => '0',
				),
			),
			true
		);
	}
	/**
	 * Reject impossible calendar dates instead of allowing date normalization.
	 *
	 * @param string $date Calendar date to validate.
	 * @return bool Validated result of this operation.
	 */
	private static function date_valid( string $date ): bool {
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		return $parsed && $parsed->format( 'Y-m-d' ) === $date;
	}
	/**
	 * Mark an owned task complete; repeating the operation is harmless.
	 *
	 * @param int $id Task or account ID belonging to this operation.
	 * @return bool Validated result of this operation.
	 */
	public static function complete( int $id ): bool {
		if ( ! current_user_can( 'read' ) || ! is_user_member_of_blog() || self::TYPE !== get_post_type( $id ) || 'private' !== get_post_status( $id ) || ( ! current_user_can( 'manage_options' ) && (int) get_post_meta( $id, 'cccn_assignee', true ) !== get_current_user_id() ) ) {
			return false; }
		update_post_meta( $id, 'cccn_done', '1' );
		return true;
	}
	/**
	 * Change email consent only for the currently authenticated recipient.
	 *
	 * @param int  $user WordPress account ID on the current site.
	 * @param bool $enabled Explicit recipient consent state.
	 * @return bool Validated result of this operation.
	 */
	public static function opt_in( int $user, bool $enabled ): bool {
		if ( get_current_user_id() !== $user || ! $user ) {
			return false; }
		update_user_meta( $user, self::consent_key(), $enabled ? '1' : '0' );
		return true;
	}
	/**
	 * Authorize a POST, validate its fields and persist only the allowlisted values.
	 */
	public static function handle(): void {
		if ( 'post' !== ( isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) || ! is_user_logged_in() || ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'coocadev-client-nudge' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'cccn_action' );
		$action = isset( $_POST['operation'] ) && is_string( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$result = false;
		if ( 'consent' === $action ) {
			$choice = self::posted( 'enabled' );
			if ( ! in_array( $choice, array( '0', '1' ), true ) ) {
				wp_die( esc_html__( 'Invalid consent choice.', 'coocadev-client-nudge' ), '', array( 'response' => 400 ) ); }
			$result = self::opt_in( get_current_user_id(), '1' === $choice );
		} elseif ( 'complete' === $action || 'delete' === $action ) {
			$task_input = self::posted( 'task' );
			$id         = ctype_digit( $task_input ) ? (int) $task_input : 0;
			$result     = 'complete' === $action ? self::complete( $id ) : ( current_user_can( 'manage_options' ) && self::TYPE === get_post_type( $id ) && wp_delete_post( $id, true ) );
		} elseif ( 'create' === $action ) {
			$title    = self::posted( 'title' );
			$detail   = self::posted( 'detail' );
			$assignee = self::posted( 'assignee' );
			$due      = self::posted( 'due' );
			if ( ! ctype_digit( $assignee ) ) {
				wp_die( esc_html__( 'Invalid client.', 'coocadev-client-nudge' ), '', array( 'response' => 400 ) ); }
			$result = self::create( $title, $detail, (int) $assignee, $due );
		} elseif ( 'retention' === $action && current_user_can( 'manage_options' ) ) {
			if ( isset( $_POST['delete_data'] ) && '1' !== self::posted( 'delete_data' ) ) {
				wp_die( esc_html__( 'Invalid setting.', 'coocadev-client-nudge' ), '', array( 'response' => 400 ) ); }
			update_option( 'cccn_delete_data', isset( $_POST['delete_data'] ), false );
			$result = true;
		}
		if ( ! $result || is_wp_error( $result ) ) {
			wp_die( esc_html__( 'The task could not be changed. Check your access and fields.', 'coocadev-client-nudge' ), '', array( 'response' => 400 ) ); }
		wp_safe_redirect( admin_url( 'tools.php?page=cccn-tasks&updated=1' ) );
		exit;
	}
	/**
	 * Build a nonce-protected form opening using the WordPress admin-post endpoint.
	 *
	 * @param string $action Allowlisted operation name.
	 * @param int    $id Task or account ID belonging to this operation.
	 * @return string Validated result of this operation.
	 */
	private static function form( string $action, int $id = 0 ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cccn_action"><input type="hidden" name="operation" value="' . esc_attr( $action ) . '"><input type="hidden" name="task" value="' . esc_attr( (string) $id ) . '">' . wp_nonce_field( 'cccn_action', '_wpnonce', true, false );
	}
	/**
	 * Render only the current user's tasks and recipient-controlled email consent.
	 *
	 * @return string Validated result of this operation.
	 */
	public static function board(): string {
		if ( ! current_user_can( 'read' ) || ! is_user_member_of_blog() ) {
			return '<p>' . esc_html__( 'Sign in to view your tasks.', 'coocadev-client-nudge' ) . '</p>'; }
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		} nocache_headers();
		$user    = get_current_user_id();
		$enabled = '1' === get_user_meta( $user, self::consent_key(), true );
		$html    = '<section aria-label="' . esc_attr__( 'My client tasks', 'coocadev-client-nudge' ) . '"><h2>' . esc_html__( 'My tasks', 'coocadev-client-nudge' ) . '</h2>';
		$html   .= self::form( 'consent' ) . '<p>' . esc_html__( 'Weekly reminder emails are optional. Only unfinished tasks are included. You can stop them here at any time.', 'coocadev-client-nudge' ) . '</p><input type="hidden" name="enabled" value="' . ( $enabled ? '0' : '1' ) . '"><button type="submit">' . ( $enabled ? esc_html__( 'Stop weekly emails', 'coocadev-client-nudge' ) : esc_html__( 'Enable weekly emails to my account address', 'coocadev-client-nudge' ) ) . '</button></form><ul>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination never changes task data.
		$page  = isset( $_GET['cccn_page'] ) && is_string( $_GET['cccn_page'] ) && ctype_digit( sanitize_text_field( wp_unslash( $_GET['cccn_page'] ) ) ) ? max( 1, min( 100000, absint( $_GET['cccn_page'] ) ) ) : 1;
		$tasks = self::tasks( $user, $page );
		foreach ( $tasks as $task ) {
			$done  = '1' === get_post_meta( $task->ID, 'cccn_done', true );
			$html .= '<li><strong>' . esc_html( $task->post_title ) . '</strong><p>' . nl2br( esc_html( $task->post_content ) ) . '</p><p>' . esc_html( get_post_meta( $task->ID, 'cccn_due', true ) ) . ' — ' . ( $done ? esc_html__( 'Done', 'coocadev-client-nudge' ) : esc_html__( 'Pending', 'coocadev-client-nudge' ) ) . '</p>';
			if ( ! $done ) {
				$html .= self::form( 'complete', $task->ID ) . '<button type="submit">' . esc_html__( 'Mark done', 'coocadev-client-nudge' ) . '</button></form>'; }
			$html .= '</li>';
		}
		if ( ! $tasks ) {
			$html .= '<li>' . esc_html__( 'No tasks assigned yet.', 'coocadev-client-nudge' ) . '</li>'; }
		$html .= '</ul><nav aria-label="' . esc_attr__( 'Task pages', 'coocadev-client-nudge' ) . '">';
		if ( $page > 1 ) {
			$html .= '<a href="' . esc_url( add_query_arg( 'cccn_page', $page - 1 ) ) . '">' . esc_html__( 'Previous', 'coocadev-client-nudge' ) . '</a> '; }
		if ( count( $tasks ) === 50 ) {
			$html .= '<a href="' . esc_url( add_query_arg( 'cccn_page', $page + 1 ) ) . '">' . esc_html__( 'Next', 'coocadev-client-nudge' ) . '</a>'; }
		return $html . '</nav></section>';
	}
	/**
	 * Render an escaped administration screen and its nonce-protected forms.
	 */
	public static function admin(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Client Nudge', 'coocadev-client-nudge' ) . '</h1>';
		// The board is generated entirely by this class and escapes each leaf value.
		echo wp_kses( self::board(), self::allowed_html() );
		if ( current_user_can( 'manage_options' ) ) {
			echo '<h2>' . esc_html__( 'Create a client task', 'coocadev-client-nudge' ) . '</h2>' . wp_kses( self::form( 'create' ), self::allowed_html() );
			echo '<p><label>' . esc_html__( 'Title', 'coocadev-client-nudge' ) . '<input required name="title" maxlength="180"></label></p><p><label>' . esc_html__( 'Instructions', 'coocadev-client-nudge' ) . '<textarea name="detail" maxlength="5000"></textarea></label></p><p><label>' . esc_html__( 'Client account ID', 'coocadev-client-nudge' ) . '<input required type="number" min="1" name="assignee"></label></p><p><label>' . esc_html__( 'Due date', 'coocadev-client-nudge' ) . '<input required type="date" name="due"></label></p><button class="button button-primary">' . esc_html__( 'Create task', 'coocadev-client-nudge' ) . '</button></form>';
			$tasks = get_posts(
				array(
					'post_type'      => self::TYPE,
					'post_status'    => 'private',
					'posts_per_page' => 50,
					'orderby'        => 'ID',
					'order'          => 'DESC',
				)
			);
			echo '<h2>' . esc_html__( 'Recent tasks for all clients', 'coocadev-client-nudge' ) . '</h2><ul>';
			foreach ( $tasks as $task ) {
				echo '<li>' . esc_html( $task->post_title ) . ' — ' . esc_html( (string) get_post_meta( $task->ID, 'cccn_assignee', true ) ) . wp_kses( self::form( 'delete', $task->ID ), self::allowed_html() ) . '<button class="button">' . esc_html__( 'Delete permanently', 'coocadev-client-nudge' ) . '</button></form></li>'; }
			echo '</ul>' . wp_kses( self::form( 'retention' ), self::allowed_html() ) . '<label><input type="checkbox" name="delete_data" value="1" ' . checked( get_option( 'cccn_delete_data', false ), true, false ) . '>' . esc_html__( 'Permanently delete all Client Nudge tasks and consent when uninstalling', 'coocadev-client-nudge' ) . '</label><p><button class="button">' . esc_html__( 'Save retention', 'coocadev-client-nudge' ) . '</button></p></form>';
		}
		echo '</div>';
	}
	/**
	 * Allow the specific form elements emitted by the task board renderer.
	 *
	 * @return array Validated result of this operation.
	 */
	private static function allowed_html(): array {
		return array_merge(
			wp_kses_allowed_html( 'post' ),
			array(
				'form'    => array(
					'method' => true,
					'action' => true,
				),
				'input'   => array(
					'type'  => true,
					'name'  => true,
					'value' => true,
				),
				'button'  => array( 'type' => true ),
				'section' => array( 'aria-label' => true ),
			)
		); }
	/**
	 * Process a bounded recipient batch with atomic weekly mail claims.
	 *
	 * @param int|null $now UTC Unix timestamp or null for the current time.
	 * @return int Validated result of this operation.
	 */
	public static function reminders( ?int $now = null ): int {
		$now = $now ?? time();
		self::purge_claims( $now );
		$cursor = max( 0, (int) get_option( 'cccn_cursor', 0 ) );
		$users  = get_users(
			array(
				'number'     => 50,
				'offset'     => $cursor,
				'orderby'    => 'ID',
				'order'      => 'ASC',
				'meta_key'   => self::consent_key(),
				'meta_value' => '1',
			)
		);
		update_option( 'cccn_cursor', count( $users ) < 50 ? 0 : $cursor + 50, false );
		$sent = 0;
		foreach ( $users as $user ) {
			$tasks = self::tasks( $user->ID, 1, true );
			if ( ! $tasks || ! is_email( $user->user_email ) ) {
					continue; }
			// A unique option is an atomic weekly claim. A successful transport acceptance is never retried automatically.
			$week = gmdate( 'o-W', $now );
			$key  = 'cccn_sent_' . $user->ID . '_' . $week;
			delete_option( 'cccn_sent_' . $user->ID . '_' . gmdate( 'o-W', $now - WEEK_IN_SECONDS ) );
			if ( '1' !== get_user_meta( $user->ID, self::consent_key(), true ) || ! add_option( $key, $now, '', false ) ) {
				continue; }
			$lines = array( __( 'Your unfinished tasks:', 'coocadev-client-nudge' ) );
			foreach ( $tasks as $task ) {
				$lines[] = '- ' . $task->post_title . ' (' . get_post_meta( $task->ID, 'cccn_due', true ) . ')'; }
			$lines[] = __( 'View tasks or stop reminders:', 'coocadev-client-nudge' ) . ' ' . admin_url( 'tools.php?page=cccn-tasks' );
			// Claim before mail: a crash after acceptance must not cause a duplicate weekly reminder.
			if ( wp_mail( $user->user_email, __( 'Your weekly client task reminder', 'coocadev-client-nudge' ), implode( "\n", $lines ) ) ) {
				++$sent;
			} else {
				delete_option( $key ); }
		}
		return $sent;
	}
	/**
	 * Register the standard WordPress personal-data exporter.
	 *
	 * @param array $items Existing privacy registry entries.
	 * @return array Validated result of this operation.
	 */
	public static function exporters( array $items ): array {
		$items['cccn-tasks'] = array(
			'exporter_friendly_name' => __( 'Client Nudge tasks', 'coocadev-client-nudge' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $items; }
	/**
	 * Register the standard WordPress personal-data eraser.
	 *
	 * @param array $items Existing privacy registry entries.
	 * @return array Validated result of this operation.
	 */
	public static function erasers( array $items ): array {
		$items['cccn-tasks'] = array(
			'eraser_friendly_name' => __( 'Client Nudge tasks', 'coocadev-client-nudge' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $items; }
	/**
	 * Export only the requested account's assigned tasks and consent.
	 *
	 * @param string $email Account email supplied by the verified WordPress privacy workflow.
	 * @param int    $page One-based page; erasure always reads page one to avoid skipping deleted rows.
	 * @return array Validated result of this operation.
	 */
	public static function export( string $email, int $page = 1 ): array {
		$user  = get_user_by( 'email', $email );
		$tasks = $user ? self::tasks( $user->ID, $page, false, true ) : array();
		$data  = array();
		foreach ( $tasks as $task ) {
			$data[] = array(
				'group_id'    => 'cccn-tasks',
				'group_label' => __( 'Client Nudge tasks', 'coocadev-client-nudge' ),
				'item_id'     => 'cccn-' . $task->ID,
				'data'        => array(
					array(
						'name'  => __( 'Task', 'coocadev-client-nudge' ),
						'value' => $task->post_title,
					),
					array(
						'name'  => __( 'Instructions', 'coocadev-client-nudge' ),
						'value' => $task->post_content,
					),
					array(
						'name'  => __( 'Due', 'coocadev-client-nudge' ),
						'value' => get_post_meta( $task->ID, 'cccn_due', true ),
					),
					array(
						'name'  => __( 'Completed', 'coocadev-client-nudge' ),
						'value' => '1' === get_post_meta( $task->ID, 'cccn_done', true ) ? __( 'Yes', 'coocadev-client-nudge' ) : __( 'No', 'coocadev-client-nudge' ),
					),
					array(
						'name'  => __( 'Stored task status', 'coocadev-client-nudge' ),
						'value' => $task->post_status,
					),
				),
			); }
		if ( $user && 1 === $page ) {
			$data[] = array(
				'group_id'    => 'cccn-tasks',
				'group_label' => __( 'Client Nudge tasks', 'coocadev-client-nudge' ),
				'item_id'     => 'cccn-consent-' . $user->ID,
				'data'        => array(
					array(
						'name'  => __( 'Weekly email consent', 'coocadev-client-nudge' ),
						'value' => get_user_meta( $user->ID, self::consent_key(), true ),
					),
				),
			); }
		return array(
			'data' => $data,
			'done' => count( $tasks ) < 50,
		);
	}
	/**
	 * Erase one bounded batch; repeat from the first page after deletion.
	 *
	 * @param string $email Account email supplied by the verified WordPress privacy workflow.
	 * @param int    $page One-based page; erasure always reads page one to avoid skipping deleted rows.
	 * @return array Validated result of this operation.
	 */
	public static function erase( string $email, int $page = 1 ): array {
		$page     = max( 1, $page ); // WordPress may increment this value, but deletions always read the first remaining batch.
		$user     = get_user_by( 'email', $email );
		$tasks    = $user ? self::tasks( $user->ID, 1, false, true ) : array();
		$removed  = false;
		$retained = false;
		foreach ( $tasks as $task ) {
			if ( wp_delete_post( $task->ID, true ) ) {
				$removed = true;
			} else {
				$retained = true; }
		}
		$had_consent = $user && metadata_exists( 'user', $user->ID, self::consent_key() );
		if ( $user ) {
			delete_user_meta( $user->ID, self::consent_key() );
			self::erase_claims( $user->ID ); }
		return array(
			'items_removed'  => $removed || $had_consent,
			'items_retained' => $retained,
			'messages'       => $retained ? array( __( 'Some tasks were retained by a WordPress deletion filter. Contact the site administrator.', 'coocadev-client-nudge' ) ) : array(),
			'done'           => $retained || count( $tasks ) < 50,
		);
	}
	/**
	 * Remove tasks owned by a deleted account on the current site.
	 *
	 * @param int $id Task or account ID belonging to this operation.
	 */
	public static function user_deleted( int $id ): void {
		// Remove ownership data in bounded batches; normal account deletion runs outside a public request.
		do {
			$tasks   = self::tasks( $id, 1, false, true );
			$deleted = 0;
			foreach ( $tasks as $task ) {
				if ( wp_delete_post( $task->ID, true ) ) {
					++$deleted; }
			}
			$count = count( $tasks );
		} while ( 50 === $count && $deleted > 0 );
		self::erase_claims( $id );
	}
	/**
	 * Delete bounded weekly deduplication claims for one account.
	 *
	 * @param int $id Task or account ID belonging to this operation.
	 */
	private static function erase_claims( int $id ): void {
		global $wpdb;
		$keys = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s LIMIT 1000', $wpdb->options, $wpdb->esc_like( 'cccn_sent_' . $id . '_' ) . '%' ) );
		foreach ( $keys as $key ) {
			delete_option( $key ); }
	}

	/**
	 * Read one scalar POST field without silently coercing arrays or stripping markup.
	 *
	 * @param string $key Field or group name from the controller allowlist.
	 * @return string Validated plain text; domain validators enforce enums and dates.
	 */
	private static function posted( string $key ): string {
		check_admin_referer( 'cccn_action' );
		if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
			wp_die( esc_html__( 'Invalid field.', 'coocadev-client-nudge' ), '', array( 'response' => 400 ) ); }
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Type checked here and losslessly validated as plain text immediately below.
		$raw   = wp_unslash( $_POST[ $key ] );
		$clean = sanitize_textarea_field( $raw );
		if ( $clean !== $raw ) {
			wp_die( esc_html__( 'Use plain text without markup.', 'coocadev-client-nudge' ), '', array( 'response' => 400 ) ); }
		return $clean;
	}

	/**
	 * Remove expired reminder claims in small batches, including claims of opted-out users.
	 *
	 * @param int $now UTC Unix timestamp.
	 */
	private static function purge_claims( int $now ): void {
		global $wpdb;
		$keys = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT 100', $wpdb->options, $wpdb->esc_like( 'cccn_sent_' ) . '%', $now - 8 * DAY_IN_SECONDS ) );
		foreach ( $keys as $key ) {
			delete_option( $key ); }
	}
	/** Add an accurate, optional policy paragraph for site administrators to review. */
	public static function privacy_policy(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( 'Client Nudge', wpautop( esc_html__( 'Client Nudge stores private client tasks, due dates, completion state and the assigned account ID. Each recipient controls optional weekly reminder consent. Emails use the site mail provider and contain pending task titles and dates. Weekly deduplication records are purged in bounded batches after eight days. Tasks and consent remain until deleted by the site manager, the WordPress privacy eraser, or explicitly configured uninstall cleanup. No tracking cookies, IP logging or external analytics are added by this plugin.', 'coocadev-client-nudge' ) ) );
		}
	}
}
