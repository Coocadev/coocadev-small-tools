<?php
/**
 * Native admin UI and CSRF-protected handlers.
 *
 * HTTP and presentation adapter for the Documents domain service.
 * Keep POST method, management capability and action-specific nonce
 * checks ahead of parsing or writes. Posted revision numbers express
 * what the editor saw; they are conflict checks, not authorization.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevFileGo
 */

namespace Coocadev\FileGo;

defined( 'ABSPATH' ) || exit;

/**
 * Site-scoped FileGo Admin component.
 */
final class Admin {
	/**
	 * Register only the hooks required by this component.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_init', array( self::class, 'settings' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		foreach ( array( 'create', 'update_title', 'upload', 'publish', 'unpublish', 'delete_version', 'check' ) as $action ) {
			add_action( 'admin_post_ccfg_' . $action, array( self::class, 'handle_' . $action ) );
		}
		add_filter( 'attachment_fields_to_edit', array( self::class, 'attachment_notice' ), 10, 2 );
	}

	/**
	 * Add the document manager under the native Media menu.
	 */
	public static function menu(): void {
		add_media_page( __( 'FileGo documents', 'coocadev-filego' ), 'FileGo', 'ccfg_manage_documents', 'ccfg-documents', array( self::class, 'page' ) );
	}

	/**
	 * Enqueue local styles only on the FileGo admin screen.
	 *
	 * @param string $hook WordPress admin screen hook.
	 */
	public static function assets( string $hook ): void {
		if ( 'media_page_ccfg-documents' === $hook ) {
			wp_enqueue_style( 'ccfg-admin', plugins_url( 'assets/admin.css', FILE ), array(), VERSION );
		}
	}

	/**
	 * Register settings with WordPress capability and nonce handling.
	 */
	public static function settings(): void {
		register_setting(
			'ccfg_settings',
			'ccfg_remove_data',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( self::class, 'boolean' ),
				'default'           => false,
			)
		);
		register_setting(
			'ccfg_settings',
			'ccfg_warning_mb',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( self::class, 'warning' ),
				'default'           => 200,
			)
		);
	}

	/**
	 * Normalize the explicit uninstall consent checkbox.
	 *
	 * @param mixed $value Untrusted setting value or previously validated input.
	 * @return bool Operation result.
	 */
	public static function boolean( mixed $value ): bool {
		return in_array( $value, array( true, 1, '1' ), true );
	}

	/**
	 * Validate the administrator storage-warning threshold.
	 *
	 * @param mixed $value Untrusted setting value or previously validated input.
	 * @return int Operation result.
	 */
	public static function warning( mixed $value ): int {
		return is_scalar( $value ) && ctype_digit( (string) $value ) ? min( 100000, max( 1, (int) $value ) ) : 200;
	}

	/**
	 * Build the local document management URL through WordPress.
	 *
	 * @param int $id Site-local numeric identifier.
	 * @return string Operation result.
	 */
	public static function location( int $id = 0 ): string {
		$args = array( 'page' => 'ccfg-documents' );
		if ( $id ) {
			$args['document'] = $id;
		}
		return add_query_arg( $args, admin_url( 'upload.php' ) );
	}

	/**
	 * Terminate an admin action with an escaped message and HTTP status.
	 *
	 * @param \WP_Error $error Domain error to display.
	 */
	private static function fail( \WP_Error $error ): never {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
		wp_die(
			esc_html( $error->get_error_message() ),
			esc_html__( 'FileGo', 'coocadev-filego' ),
			array(
				'response'  => absint( $status ),
				'back_link' => true,
			)
		);
	}

	/**
	 * Read one bounded scalar form field, normalize WP slashes, and sanitize text.
	 *
	 * @param string $field Allowlisted form field name.
	 * @param int    $maximum Maximum input length in bytes.
	 * @return string Operation result.
	 */
	private static function text( string $field, int $maximum ): string {
		// Selected WP-slashed scalar POST fields only; no blanket REQUEST/FILES unslashing.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Scalar reader also reads the nonce itself; each mutation verifies it before calling a service. HTTP negative tests cover both paths.
		$value = isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : null;
		if ( null === $value || strlen( $value ) > $maximum ) {
			self::fail( Documents::failure( 'input', __( 'A form field is missing or invalid.', 'coocadev-filego' ) ) );
		}
		return $value;
	}

	/**
	 * Validate a non-negative integer form field before object access.
	 *
	 * @param string $field Allowlisted form field name.
	 * @param bool   $zero Whether zero is an allowed value.
	 * @return int Operation result.
	 */
	private static function number( string $field, bool $zero = false ): int {
		$value = self::text( $field, 18 );
		if ( ! preg_match( '/^(0|[1-9][0-9]*)$/D', $value ) || ( ! $zero && '0' === $value ) || (float) $value > PHP_INT_MAX ) {
			self::fail( Documents::failure( 'input', __( 'The document ID or revision is invalid.', 'coocadev-filego' ) ) );
		}
		return (int) $value;
	}

	/**
	 * Require POST and the dedicated management capability before processing.
	 */
	private static function authorize(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'post' !== $method ) {
			self::fail( Documents::failure( 'method', __( 'Use the form to make this change.', 'coocadev-filego' ), 405 ) );
		}
		if ( ! current_user_can( 'ccfg_manage_documents' ) ) {
			self::fail( Documents::failure( 'forbidden', __( 'You cannot manage FileGo documents.', 'coocadev-filego' ), 403 ) );
		}
	}

	/**
	 * Verify the action-specific cookie-session CSRF token.
	 *
	 * @param string $action Action identifier for the form or nonce.
	 */
	private static function nonce( string $action ): void {
		$nonce = self::text( '_wpnonce', 40 );
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			self::fail( Documents::failure( 'nonce', __( 'This form expired. Reload the page and try again.', 'coocadev-filego' ), 403 ) );
		}
	}

	/**
	 * Redirect after success or render the actual error without reporting a false success.
	 *
	 * @param mixed $result Domain operation result.
	 * @param int   $id Site-local numeric identifier.
	 */
	private static function complete( mixed $result, int $id ): never {
		if ( is_wp_error( $result ) ) {
			self::fail( $result );
		}
		wp_safe_redirect( add_query_arg( 'ccfg_saved', '1', self::location( $id ) ) );
		exit;
	}

	/**
	 * Create a document after capability and CSRF checks.
	 */
	public static function handle_create(): void {
		self::authorize();
		self::nonce( 'ccfg_create' );
		$result = Documents::create( self::text( 'title', 200 ), self::text( 'slug', 100 ) );
		self::complete( $result, is_int( $result ) ? $result : 0 );
	}

	/** Update the title after document-specific CSRF validation. */
	public static function handle_update_title(): void {
		self::authorize();
		$id = self::number( 'document' );
		self::nonce( 'ccfg_update_title_' . $id );
		self::complete( Documents::update_title( $id, self::number( 'revision', true ), self::text( 'title', 200 ) ), $id );
	}

	/**
	 * Accept one uploaded PDF after capability and document-specific CSRF checks.
	 */
	public static function handle_upload(): void {
		self::authorize();
		$id = self::number( 'document' );
		self::nonce( 'ccfg_upload_' . $id );
		if ( ! current_user_can( 'upload_files' ) ) {
			self::fail( Documents::failure( 'forbidden', __( 'You cannot upload files.', 'coocadev-filego' ), 403 ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified immediately above; upload() validates each FILES type, real upload path, actual MIME/size and generated filename. FILES must not be text-sanitized/unslashed.
		$file = isset( $_FILES['ccfg_pdf'] ) && is_array( $_FILES['ccfg_pdf'] ) ? $_FILES['ccfg_pdf'] : array();
		self::complete( Documents::upload( $id, $file, self::text( 'note', 500 ) ), $id );
	}

	/**
	 * Publish an owned version using the submitted document revision.
	 */
	public static function handle_publish(): void {
		self::authorize();
		$id = self::number( 'document' );
		self::nonce( 'ccfg_publish_' . $id );
		self::complete( Documents::publish( $id, self::number( 'version' ), self::number( 'revision', true ) ), $id );
	}

	/**
	 * Remove the public pointer without removing physical PDFs.
	 */
	public static function handle_unpublish(): void {
		self::authorize();
		$id = self::number( 'document' );
		self::nonce( 'ccfg_unpublish_' . $id );
		self::complete( Documents::unpublish( $id, self::number( 'revision', true ) ), $id );
	}

	/**
	 * Delete a non-current version only after explicit confirmation.
	 */
	public static function handle_delete_version(): void {
		self::authorize();
		$id      = self::number( 'document' );
		$version = self::number( 'version' );
		self::nonce( 'ccfg_delete_version_' . $version );
		if ( '1' !== self::text( 'confirm', 1 ) ) {
			self::fail( Documents::failure( 'confirm', __( 'Confirm that direct links to this version will stop working.', 'coocadev-filego' ) ) );
		}
		self::complete( Documents::delete_version( $id, $version, self::number( 'revision', true ) ), $id );
	}

	/**
	 * Inspect the current same-site redirect without following external destinations.
	 */
	public static function handle_check(): void {
		self::authorize();
		$id = self::number( 'document' );
		self::nonce( 'ccfg_check_' . $id );
		if ( ! current_user_can( 'manage_options' ) ) {
			self::fail( Documents::failure( 'forbidden', __( 'Only an administrator can run diagnostics.', 'coocadev-filego' ), 403 ) );
		}
		$document = Store::document( $id );
		if ( ! $document ) {
			self::fail( Documents::failure( 'missing', __( 'Document not found.', 'coocadev-filego' ), 404 ) );
		}
		$target   = Plugin::target( $document->slug );
		$response = wp_safe_remote_head(
			Plugin::url( $document->slug ),
			array(
				'timeout'             => 6,
				'redirection'         => 0,
				'limit_response_size' => 1024,
			)
		);
		$status   = 'unverified';
		if ( ! is_wp_error( $response ) && ! is_wp_error( $target ) ) {
			$cache  = wp_remote_retrieve_header( $response, 'cache-control' );
			$status = 302 === wp_remote_retrieve_response_code( $response ) && wp_remote_retrieve_header( $response, 'location' ) === $target && str_contains( strtolower( (string) $cache ), 'no-store' ) ? 'verified' : 'failed';
		}
		set_transient( 'ccfg_check_' . get_current_user_id() . '_' . $id, $status, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::location( $id ) );
		exit;
	}

	/**
	 * Explain why FileGo attachments must be managed through their document.
	 *
	 * @param array    $fields Existing WordPress attachment fields.
	 * @param \WP_Post $post WordPress attachment or post object.
	 * @return array Operation result.
	 */
	public static function attachment_notice( array $fields, \WP_Post $post ): array {
		global $wpdb;
		$document = $wpdb->get_var( $wpdb->prepare( "SELECT document_id FROM %i WHERE attachment_id = %d AND state <> 'deleted'", Store::table( 'versions' ), $post->ID ) );
		if ( $document ) {
			$fields['ccfg_document'] = array(
				'label' => 'FileGo',
				'input' => 'html',
				'html'  => '<p>' . esc_html__( 'This attachment is a FileGo version. Delete or publish it only through FileGo.', 'coocadev-filego' ) . ' <a href="' . esc_url( self::location( (int) $document ) ) . '">' . esc_html__( 'Manage document', 'coocadev-filego' ) . '</a></p>',
			);
		}
		return $fields;
	}

	/**
	 * Open a native POST form with action-specific nonce and immutable identifiers.
	 *
	 * @param string $action Action identifier for the form or nonce.
	 * @param string $nonce Action-specific nonce name.
	 * @param int    $document Document row or numeric document identifier.
	 * @param int    $revision Expected document revision submitted by the editor.
	 * @param int    $version Version row or numeric version identifier.
	 * @param bool   $upload Whether the form accepts a file.
	 */
	private static function form( string $action, string $nonce, int $document = 0, int $revision = 0, int $version = 0, bool $upload = false ): void {
		?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" 
		<?php
		if ( $upload ) {
			?>
			enctype="multipart/form-data"<?php } ?> class="ccfg-form">
		<input type="hidden" name="action" value="<?php echo esc_attr( 'ccfg_' . $action ); ?>">
		<input type="hidden" name="document" value="<?php echo esc_attr( (string) $document ); ?>">
		<input type="hidden" name="revision" value="<?php echo esc_attr( (string) $revision ); ?>">
		<input type="hidden" name="version" value="<?php echo esc_attr( (string) $version ); ?>">
		<?php wp_nonce_field( $nonce ); ?>
		<?php
	}

	/**
	 * Render the document list or one requested document after authorization.
	 */
	public static function page(): void {
		if ( ! current_user_can( 'ccfg_manage_documents' ) ) {
			self::fail( Documents::failure( 'forbidden', __( 'You cannot manage FileGo documents.', 'coocadev-filego' ), 403 ) );
		}
		$id       = isset( $_GET['document'] ) && is_scalar( $_GET['document'] ) ? absint( $_GET['document'] ) : 0;
		$document = $id ? Store::document( $id ) : null;
		?>
		<div class="wrap ccfg-wrap"><h1><?php esc_html_e( 'FileGo documents', 'coocadev-filego' ); ?></h1>
		<p><?php esc_html_e( 'Keep one link to the latest PDF. All versions are public. Unpublishing the permanent link does not hide old file URLs.', 'coocadev-filego' ); ?></p>
		<?php
		if ( isset( $_GET['ccfg_saved'] ) ) {
			?>
			<div class="notice notice-success" role="status"><p><?php esc_html_e( 'Change saved.', 'coocadev-filego' ); ?></p></div><?php } ?>
		<?php
		if ( ! Store::transactional() ) {
			?>
			<div class="notice notice-error"><p><?php esc_html_e( 'FileGo needs InnoDB for both of its tables. Changes are disabled.', 'coocadev-filego' ); ?></p></div><?php } ?>
		<?php
		if ( $document ) {
			self::detail( $document );
		} elseif ( $id ) {
			echo '<p>' . esc_html__( 'Document not found.', 'coocadev-filego' ) . '</p>';
		} else {
			self::listing();
			self::settings_form();
		}
		echo '</div>';
	}

	/**
	 * Render bounded search results and the create-document form.
	 */
	private static function listing(): void {
		$search = isset( $_GET['s'] ) && is_string( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$page   = isset( $_GET['paged'] ) && is_scalar( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$rows   = Store::listing( substr( $search, 0, 200 ), $page );
		?>
		<h2><?php esc_html_e( 'Create a document', 'coocadev-filego' ); ?></h2>
		<?php self::form( 'create', 'ccfg_create' ); ?>
		<label for="ccfg-title"><?php esc_html_e( 'Document title', 'coocadev-filego' ); ?></label>
		<input id="ccfg-title" name="title" required maxlength="200" class="regular-text">
		<label for="ccfg-slug"><?php esc_html_e( 'Permanent-link slug', 'coocadev-filego' ); ?></label>
		<input id="ccfg-slug" name="slug" required maxlength="100" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" aria-describedby="ccfg-slug-help" class="regular-text">
		<p id="ccfg-slug-help"><?php esc_html_e( 'Example: restaurant-menu. The slug cannot be changed later.', 'coocadev-filego' ); ?></p>
		<?php submit_button( __( 'Create document', 'coocadev-filego' ), 'primary', 'submit', false ); ?></form>
		<h2><?php esc_html_e( 'Your documents', 'coocadev-filego' ); ?></h2>
		<form method="get"><input type="hidden" name="page" value="ccfg-documents"><label class="screen-reader-text" for="ccfg-search"><?php esc_html_e( 'Search documents', 'coocadev-filego' ); ?></label><input id="ccfg-search" name="s" value="<?php echo esc_attr( $search ); ?>"><?php submit_button( __( 'Search', 'coocadev-filego' ), 'secondary', 'submit', false ); ?></form>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Document', 'coocadev-filego' ); ?></th><th><?php esc_html_e( 'Permanent link', 'coocadev-filego' ); ?></th><th><?php esc_html_e( 'Current version', 'coocadev-filego' ); ?></th><th><?php esc_html_e( 'Status', 'coocadev-filego' ); ?></th><th><?php esc_html_e( 'Updated (UTC)', 'coocadev-filego' ); ?></th></tr></thead><tbody>
		<?php foreach ( array_slice( $rows, 0, 20 ) as $row ) { ?>
		<tr><td><a href="<?php echo esc_url( self::location( (int) $row->id ) ); ?>"><?php echo esc_html( $row->title ); ?></a></td><td><a href="<?php echo esc_url( Plugin::url( $row->slug ) ); ?>"><?php echo esc_html( Plugin::url( $row->slug ) ); ?></a></td><td><?php echo esc_html( $row->current_version_id ?? '—' ); ?></td><td><?php echo 'published' === $row->status ? esc_html__( 'Published', 'coocadev-filego' ) : esc_html__( 'Unpublished', 'coocadev-filego' ); ?></td><td><?php echo esc_html( $row->updated_at_gmt ); ?></td></tr>
		<?php } ?>
		<?php
		if ( ! $rows ) {
			?>
			<tr><td colspan="5"><?php esc_html_e( 'No documents yet. Create one above, upload a PDF, then publish it.', 'coocadev-filego' ); ?></td></tr><?php } ?>
		</tbody></table>
		<?php self::pagination( $page, count( $rows ) > 20, self::location(), array( 's' => $search ), 'paged' ); ?>
		<?php
	}

	/**
	 * Render ordinary accessible previous and next links.
	 *
	 * @param int    $page One-based result page.
	 * @param bool   $more Whether another page may exist.
	 * @param string $url Trusted local base URL.
	 * @param array  $args Additional pagination query arguments.
	 * @param string $field Allowlisted form field name.
	 */
	private static function pagination( int $page, bool $more, string $url, array $args, string $field ): void {
		echo '<p class="ccfg-pagination">';
		if ( $page > 1 ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( array_merge( $args, array( $field => $page - 1 ) ), $url ) ) . '">' . esc_html__( 'Previous page', 'coocadev-filego' ) . '</a> ';
		}
		if ( $more ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( array_merge( $args, array( $field => $page + 1 ) ), $url ) ) . '">' . esc_html__( 'Next page', 'coocadev-filego' ) . '</a>';
		}
		echo '</p>';
	}

	/**
	 * Render version history, publication controls and upload diagnostics.
	 *
	 * @param object $document Document row or numeric document identifier.
	 */
	private static function detail( object $document ): void {
		$id       = (int) $document->id;
		$revision = (int) $document->revision;
		$page     = isset( $_GET['version_page'] ) && is_scalar( $_GET['version_page'] ) ? max( 1, absint( $_GET['version_page'] ) ) : 1;
		$versions = Store::versions( $id, $page );
		$stats    = Store::stats( $id );
		?>
		<p><a href="<?php echo esc_url( self::location() ); ?>"><?php esc_html_e( 'All documents', 'coocadev-filego' ); ?></a></p>
		<h2><?php echo esc_html( $document->title ); ?></h2>
		<?php self::form( 'update_title', 'ccfg_update_title_' . $id, $id, $revision ); ?>
		<label for="ccfg-edit-title"><?php esc_html_e( 'Document title', 'coocadev-filego' ); ?></label>
		<input id="ccfg-edit-title" name="title" maxlength="200" required class="regular-text" value="<?php echo esc_attr( $document->title ); ?>">
		<?php submit_button( __( 'Save title', 'coocadev-filego' ), 'secondary', 'submit', false ); ?></form>
		<label for="ccfg-alias"><?php esc_html_e( 'Copy this permanent link', 'coocadev-filego' ); ?></label>
		<input id="ccfg-alias" class="large-text" readonly value="<?php echo esc_attr( Plugin::url( $document->slug ) ); ?>">
		<p><?php echo esc_html( size_format( (int) $stats->bytes ) ); ?> · <?php /* translators: %d: number of stored PDF versions. */ echo esc_html( sprintf( _n( '%d stored version', '%d stored versions', (int) $stats->versions, 'coocadev-filego' ), (int) $stats->versions ) ); ?></p>
		<?php
		if ( (int) $stats->bytes > (int) get_option( 'ccfg_warning_mb', 200 ) * MB_IN_BYTES ) {
			?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'This document exceeds your storage warning threshold. Review old versions; nothing is deleted automatically.', 'coocadev-filego' ); ?></p></div><?php } ?>
		<?php
		if ( 'published' === $document->status ) {
			self::form( 'unpublish', 'ccfg_unpublish_' . $id, $id, $revision );
			submit_button( __( 'Unpublish permanent link', 'coocadev-filego' ), 'secondary', 'submit', false );
			echo '</form>'; }
		?>
		<?php if ( current_user_can( 'upload_files' ) ) { ?>
		<h2><?php esc_html_e( 'Upload a new version', 'coocadev-filego' ); ?></h2>
			<?php self::form( 'upload', 'ccfg_upload_' . $id, $id, $revision, 0, true ); ?>
		<label for="ccfg-pdf"><?php esc_html_e( 'Public PDF', 'coocadev-filego' ); ?></label><input type="file" id="ccfg-pdf" name="ccfg_pdf" accept="application/pdf,.pdf" required>
		<p><?php /* translators: %s: maximum PDF size. */ echo esc_html( sprintf( __( 'Current limit: %s. Uploading does not publish the new version.', 'coocadev-filego' ), size_format( Documents::limit() ) ) ); ?></p>
		<label for="ccfg-note"><?php esc_html_e( 'Version note (optional)', 'coocadev-filego' ); ?></label><input id="ccfg-note" name="note" maxlength="500" class="large-text">
			<?php submit_button( __( 'Upload version', 'coocadev-filego' ), 'primary', 'submit', false ); ?></form>
		<?php } ?>
		<h2><?php esc_html_e( 'Version history', 'coocadev-filego' ); ?></h2>
		<?php
		foreach ( $versions as $version ) {
			$current = (int) $document->current_version_id === (int) $version->id;
			?>
		<section class="ccfg-version"><h3><?php /* translators: %d: version identifier. */ echo esc_html( sprintf( __( 'Version %d', 'coocadev-filego' ), $version->id ) ); ?>
			<?php
			if ( $current ) {
				echo ' — ' . esc_html__( 'Current', 'coocadev-filego' ); }
			?>
			</h3>
		<p><?php echo esc_html( $version->created_at_gmt ); ?> UTC · <?php echo esc_html( size_format( (int) $version->byte_size ) ); ?> · <?php echo esc_html( $version->state ); ?></p><p><?php echo esc_html( $version->note ); ?></p>
			<?php if ( 'deleted' !== $version->state ) { ?>
				<?php
				$intact = Documents::ownership( $version ); if ( is_wp_error( $intact ) ) {
					?>
					<p role="alert"><?php echo esc_html( $intact->get_error_message() ); ?></p>
					<?php
				} else {
					?>
					<p><a href="<?php echo esc_url( wp_get_attachment_url( (int) $version->attachment_id ) ); ?>"><?php esc_html_e( 'View this public PDF', 'coocadev-filego' ); ?></a></p><?php } ?>
				<?php
				if ( ! $current && 'delete_pending' !== $version->state && ! is_wp_error( $intact ) ) {
					self::form( 'publish', 'ccfg_publish_' . $id, $id, $revision, (int) $version->id );
					submit_button( __( 'Publish / roll back to this version', 'coocadev-filego' ), 'secondary', 'submit', false );
					echo '</form>'; }
				?>
				<?php
				if ( ! $current && ( current_user_can( 'delete_post', (int) $version->attachment_id ) || ( 'delete_pending' === $version->state && ! get_post( (int) $version->attachment_id ) && current_user_can( 'manage_options' ) ) ) ) {
					self::form( 'delete_version', 'ccfg_delete_version_' . $version->id, $id, $revision, (int) $version->id );
					?>
		<label><input type="checkbox" name="confirm" value="1" required> <?php esc_html_e( 'Direct links to this version will stop working. Delete this PDF permanently.', 'coocadev-filego' ); ?></label>
					<?php
					submit_button( 'delete_pending' === $version->state ? __( 'Retry pending deletion', 'coocadev-filego' ) : __( 'Delete this version', 'coocadev-filego' ), 'delete', 'submit', false );
					echo '</form>'; }
				?>
		<?php } ?></section>
		<?php } ?>
		<?php self::pagination( $page, count( $versions ) === 20, self::location( $id ), array(), 'version_page' ); ?>
		<?php if ( current_user_can( 'manage_options' ) ) { ?>
		<h2><?php esc_html_e( 'Permanent-link diagnostic', 'coocadev-filego' ); ?></h2>
		<p><?php esc_html_e( 'Exclude /filego/ and the ccfg_download query from page/CDN caching. This check sends one request to your own site and cannot certify every visitor’s cache.', 'coocadev-filego' ); ?></p>
			<?php
			$check = get_transient( 'ccfg_check_' . get_current_user_id() . '_' . $id ); if ( $check ) {
				?>
				<p role="status">
				<?php
				echo esc_html(
					match ( $check ) {
								'verified' => __( 'The current response has the expected redirect and no-store header. This is not a global cache guarantee.', 'coocadev-filego' ),
						'failed' => __( 'The response did not match the current file or cache headers. Check your proxy/cache exclusions.', 'coocadev-filego' ),
						default => __( 'The check could not verify this link. It may be unpublished, or loopback requests may be unavailable.', 'coocadev-filego' ) }
				);
				?>
				</p><?php } ?>
			<?php
			self::form( 'check', 'ccfg_check_' . $id, $id, $revision );
			submit_button( __( 'Check permanent link', 'coocadev-filego' ), 'secondary', 'submit', false );
			echo '</form>';
			?>
			<?php
		}
	}

	/**
	 * Render administrator-only retention and storage-warning settings.
	 */
	private static function settings_form(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'FileGo settings', 'coocadev-filego' ); ?></h2>
		<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post" class="ccfg-form"><?php settings_fields( 'ccfg_settings' ); ?>
		<label for="ccfg-warning"><?php esc_html_e( 'Warn when one document exceeds this size (MiB)', 'coocadev-filego' ); ?></label><input type="number" id="ccfg-warning" name="ccfg_warning_mb" min="1" max="100000" value="<?php echo esc_attr( (string) get_option( 'ccfg_warning_mb', 200 ) ); ?>">
		<label><input type="checkbox" name="ccfg_remove_data" value="1" <?php checked( get_option( 'ccfg_remove_data', false ) ); ?>> <?php esc_html_e( 'Remove FileGo records on uninstall. Permanent links will stop working. PDF attachments and physical files are always kept.', 'coocadev-filego' ); ?></label>
		<?php submit_button(); ?></form>
		<?php
	}
}
