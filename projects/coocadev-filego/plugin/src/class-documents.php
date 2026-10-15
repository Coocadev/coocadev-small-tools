<?php
/**
 * PDF version management; all write entry points require a trusted administrator.
 *
 * Domain boundary for upload, publication and deletion of public PDFs.
 * HTTP adapters handle CSRF, while this layer rechecks capability, site
 * ownership and storage constraints. All version filenames are immutable;
 * publication changes a logical pointer, never overwrites a live file.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevFileGo
 */

namespace Coocadev\FileGo;

defined( 'ABSPATH' ) || exit;

/**
 * Site-scoped FileGo Documents component.
 */
final class Documents {
	/**
	 * Exact attachment/version/token authorized for the in-process delete operation.
	 *
	 * @var array|null
	 */
	private static ?array $delete_context = null;

	/**
	 * Create a public-safe domain error with its intended HTTP status.
	 *
	 * @param string $code Stable plugin-specific error suffix.
	 * @param string $message Translated public-safe error message.
	 * @param int    $status Intended HTTP response status.
	 * @return \WP_Error Operation result.
	 */
	public static function failure( string $code, string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error( 'ccfg_' . $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Require management permission and transactional storage for mutations.
	 *
	 * @return bool|\WP_Error Operation result.
	 */
	public static function writable(): bool|\WP_Error {
		if ( ! current_user_can( 'ccfg_manage_documents' ) ) {
			return self::failure( 'forbidden', __( 'You cannot manage FileGo documents.', 'coocadev-filego' ), 403 );
		}
		if ( ! Store::transactional() ) {
			return self::failure( 'storage', __( 'FileGo requires InnoDB for both of its tables. Changes are disabled.', 'coocadev-filego' ), 503 );
		}
		return true;
	}

	/**
	 * Insert a uniquely named logical document without creating or publishing a file.
	 *
	 * @param string $title Document display title.
	 * @param string $slug Unique lower-case permanent-link identifier.
	 * @return int|\WP_Error Operation result.
	 */
	public static function create( string $title, string $slug ): int|\WP_Error {
		global $wpdb;
		$allowed = self::writable();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$title = sanitize_text_field( $title );
		if ( '' === $title || strlen( $title ) > 200 || ! preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,98}[a-z0-9])?$/D', $slug ) ) {
			return self::failure( 'input', __( 'Use a title up to 200 bytes and a unique lowercase slug with letters, numbers and hyphens (1–100 characters).', 'coocadev-filego' ) );
		}
		$ok = $wpdb->insert(
			Store::table( 'documents' ),
			array(
				'slug'           => $slug,
				'title'          => $title,
				'created_at_gmt' => current_time( 'mysql', true ),
				'updated_at_gmt' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			return self::failure( 'duplicate', __( 'This permanent-link slug already exists or the document could not be saved.', 'coocadev-filego' ), 409 );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update the display title while keeping the permanent slug unchanged.
	 *
	 * @param int    $document_id Site-local document identifier.
	 * @param int    $revision Expected document revision.
	 * @param string $title New display title.
	 * @return bool|\WP_Error Operation result.
	 */
	public static function update_title( int $document_id, int $revision, string $title ): bool|\WP_Error {
		global $wpdb;
		$allowed = self::writable();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$title = sanitize_text_field( $title );
		if ( '' === $title || strlen( $title ) > 200 ) {
			return self::failure( 'title', __( 'Provide a document title up to 200 bytes.', 'coocadev-filego' ) );
		}
		$changed = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET title = %s, revision = revision + 1, updated_at_gmt = %s WHERE id = %d AND revision = %d', Store::table( 'documents' ), $title, current_time( 'mysql', true ), $document_id, $revision ) );
		return 1 === $changed ? true : self::conflict();
	}

	/**
	 * Return the stricter of the product limit and the current WordPress upload limit.
	 *
	 * @return int Operation result.
	 */
	public static function limit(): int {
		return min( 20 * MB_IN_BYTES, (int) wp_max_upload_size() );
	}

	/**
	 * Inspect bounded PDF markers and server MIME without trusting the browser type.
	 *
	 * @param string $path Candidate local file path.
	 * @return bool|\WP_Error Operation result.
	 */
	public static function validate_pdf( string $path ): bool|\WP_Error {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return self::failure( 'missing', __( 'The document file is missing or unreadable.', 'coocadev-filego' ) );
		}
		$size = filesize( $path );
		if ( false === $size || $size < 20 || $size > self::limit() ) {
			return self::failure( 'size', __( 'This PDF is empty or larger than your current upload limit.', 'coocadev-filego' ) );
		}
		if ( ! class_exists( '\finfo' ) ) {
			return self::failure( 'inspector', __( 'The server must enable PHP Fileinfo before FileGo can accept PDFs.', 'coocadev-filego' ), 503 );
		}
		$inspector = new \finfo( FILEINFO_MIME_TYPE );
		if ( 'application/pdf' !== $inspector->file( $path ) ) {
			return self::failure( 'mime', __( 'The file is not recognized as a PDF.', 'coocadev-filego' ) );
		}
		// Local bounded reads avoid loading a 20 MiB document merely to inspect its two markers.
		try {
			$handle = new \SplFileObject( $path, 'rb' );
			$prefix = $handle->fread( 16 );
			$handle->fseek( -min( 2048, $size ), SEEK_END );
			$tail = $handle->fread( 2048 );
		} catch ( \RuntimeException $error ) {
			return self::failure( 'read', __( 'The uploaded PDF could not be read.', 'coocadev-filego' ) );
		} finally {
			unset( $handle );
		}
		if ( ! is_string( $prefix ) || ! preg_match( '/^%PDF-[12]\.[0-9]/', $prefix ) || ! is_string( $tail ) || ! preg_match( '/%%EOF\s*$/D', $tail ) ) {
			return self::failure( 'structure', __( 'The file does not have a valid PDF header and end marker.', 'coocadev-filego' ) );
		}
		return true;
	}

	/**
	 * Resolve an existing file only inside the current site’s canonical uploads directory.
	 *
	 * @param string $path Candidate local file path.
	 * @return string|\WP_Error Operation result.
	 */
	public static function local_path( string $path ): string|\WP_Error {
		$upload = wp_get_upload_dir();
		$base   = realpath( $upload['basedir'] );
		$real   = realpath( $path );
		if ( $upload['error'] || ! $base || ! $real || ! is_file( $real ) ) {
			return self::failure( 'missing', __( 'The local document file is unavailable.', 'coocadev-filego' ), 404 );
		}
		$base         = trailingslashit( wp_normalize_path( $base ) );
		$real         = wp_normalize_path( $real );
		$compare_base = '\\' === DIRECTORY_SEPARATOR ? strtolower( $base ) : $base;
		$compare_real = '\\' === DIRECTORY_SEPARATOR ? strtolower( $real ) : $real;
		if ( ! str_starts_with( $compare_real, $compare_base ) ) {
			return self::failure( 'path', __( 'The document must remain in this site’s local uploads directory.', 'coocadev-filego' ) );
		}
		return $real;
	}

	/**
	 * Validate a real upload and create a staged version through the WordPress upload pipeline.
	 *
	 * @param int    $document_id Current-site logical document identifier.
	 * @param array  $file One PHP upload record; it is never unslashed.
	 * @param string $note Optional version note.
	 * @return int|\WP_Error Operation result.
	 */
	public static function upload( int $document_id, array $file, string $note ): int|\WP_Error {
		global $wpdb;
		$allowed = self::writable();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( ! current_user_can( 'upload_files' ) || ! Store::document( $document_id ) ) {
			return self::failure( 'upload_forbidden', __( 'You cannot upload a version for this document.', 'coocadev-filego' ), 403 );
		}
		if ( ! isset( $file['tmp_name'], $file['name'], $file['error'], $file['size'] ) || ! is_string( $file['tmp_name'] ) || ! is_string( $file['name'] ) || ! is_int( $file['error'] ) || ! is_int( $file['size'] ) || UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return self::failure( 'upload', __( 'Choose one successfully uploaded PDF file.', 'coocadev-filego' ) );
		}
		if ( 'pdf' !== strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) || strlen( $note ) > 500 ) {
			return self::failure( 'input', __( 'Choose a PDF and keep the version note within 500 bytes.', 'coocadev-filego' ) );
		}
		$valid = self::validate_pdf( $file['tmp_name'] );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		// A generated single-extension name is never derived from the untrusted original name.
		$file['name'] = 'ccfg-' . wp_generate_uuid4() . '.pdf';
		$allowed_type = wp_check_filetype( $file['name'], get_allowed_mime_types() );
		if ( 'pdf' !== $allowed_type['ext'] || 'application/pdf' !== $allowed_type['type'] ) {
			return self::failure( 'policy', __( 'This site’s upload policy does not allow PDFs.', 'coocadev-filego' ), 403 );
		}
		$result = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => array( 'pdf' => 'application/pdf' ),
			)
		);
		if ( isset( $result['error'] ) ) {
			return self::failure( 'upload', sanitize_text_field( $result['error'] ) );
		}
		$path = self::local_path( $result['file'] );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$upload   = wp_get_upload_dir();
		$base     = trailingslashit( wp_normalize_path( (string) realpath( $upload['basedir'] ) ) );
		$relative = substr( $path, strlen( $base ) );
		$hash     = hash_file( 'sha256', $path );
		if ( ! $hash || strlen( $relative ) > 255 ) {
			wp_delete_file( $path );
			return self::failure( 'file', __( 'The uploaded PDF could not be recorded.', 'coocadev-filego' ), 500 );
		}
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'application/pdf',
				'post_title'     => Store::document( $document_id )->title,
				'post_status'    => 'inherit',
				'post_author'    => get_current_user_id(),
			),
			$path,
			0,
			true
		);
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $path );
			return self::failure( 'attachment', __( 'The PDF attachment could not be created.', 'coocadev-filego' ), 500 );
		}
		// Store the verified relative path consistently, including Windows path/case normalization.
		update_attached_file( $attachment_id, $relative );
		$ok = $wpdb->insert(
			Store::table( 'versions' ),
			array(
				'document_id'    => $document_id,
				'attachment_id'  => $attachment_id,
				'relative_path'  => $relative,
				'byte_size'      => filesize( $path ),
				'sha256'         => $hash,
				'mime'           => 'application/pdf',
				'note'           => sanitize_text_field( $note ),
				'created_by'     => get_current_user_id(),
				'created_at_gmt' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s' )
		);
		if ( ! $ok ) {
			wp_delete_attachment( $attachment_id, true );
			return self::failure( 'save', __( 'The version could not be saved. The published document was not changed.', 'coocadev-filego' ), 500 );
		}
		$id = (int) $wpdb->insert_id;
		update_post_meta( $attachment_id, '_ccfg_owner', 'coocadev-filego:' . get_current_blog_id() );
		update_post_meta( $attachment_id, '_ccfg_version_id', $id );
		return $id;
	}

	/**
	 * Verify the document attachment graph, local path, size and optional integrity hash.
	 *
	 * @param object $version Version row or numeric version identifier.
	 * @param bool   $hash Whether to verify the full SHA-256 before publication.
	 * @return string|\WP_Error Operation result.
	 */
	public static function ownership( object $version, bool $hash = false ): string|\WP_Error {
		$attachment_id = (int) $version->attachment_id;
		$post          = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type || 'trash' === $post->post_status || 'application/pdf' !== $post->post_mime_type || get_post_meta( $attachment_id, '_ccfg_owner', true ) !== 'coocadev-filego:' . get_current_blog_id() || (int) get_post_meta( $attachment_id, '_ccfg_version_id', true ) !== (int) $version->id ) {
			return self::failure( 'ownership', __( 'The attachment no longer matches this FileGo version.', 'coocadev-filego' ) );
		}
		$path = self::local_path( (string) get_attached_file( $attachment_id, true ) );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$base = trailingslashit( wp_normalize_path( (string) realpath( wp_get_upload_dir()['basedir'] ) ) );
		if ( substr( $path, strlen( $base ) ) !== $version->relative_path || filesize( $path ) !== (int) $version->byte_size || ( $hash && ! hash_equals( $version->sha256, (string) hash_file( 'sha256', $path ) ) ) ) {
			return self::failure( 'integrity', __( 'The file changed outside FileGo. Restore the original file or upload a new version.', 'coocadev-filego' ), 409 );
		}
		$url = wp_get_attachment_url( $attachment_id );
		if ( ! $url || wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
			return self::failure( 'offload', __( 'FileGo requires local files and same-host attachment URLs. Offloaded media is not supported.', 'coocadev-filego' ) );
		}
		return $path;
	}

	/**
	 * Atomically publish a verified version with document-first row locks and revision CAS.
	 *
	 * @param int $document_id Current-site logical document identifier.
	 * @param int $version_id Owned version identifier.
	 * @param int $revision Expected document revision submitted by the editor.
	 * @return bool|\WP_Error Operation result.
	 */
	public static function publish( int $document_id, int $version_id, int $revision ): bool|\WP_Error {
		global $wpdb;
		$allowed = self::writable();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$version = Store::version( $version_id );
		if ( ! $version || (int) $version->document_id !== $document_id || ! in_array( $version->state, array( 'staged', 'published', 'retired' ), true ) ) {
			return self::failure( 'version', __( 'This version cannot be published.', 'coocadev-filego' ), 409 );
		}
		$valid = self::ownership( $version, true );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$wpdb->query( 'START TRANSACTION' );
		try {
			$document = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', Store::table( 'documents' ), $document_id ) );
			$version  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', Store::table( 'versions' ), $version_id ) );
			if ( ! $document || (int) $document->revision !== $revision || ! $version || (int) $version->document_id !== $document_id || ! in_array( $version->state, array( 'staged', 'published', 'retired' ), true ) ) {
				return self::conflict();
			}
			$valid = self::ownership( $version );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE %i SET current_version_id = %d, status = 'published', revision = revision + 1, updated_at_gmt = %s WHERE id = %d AND revision = %d", Store::table( 'documents' ), $version_id, current_time( 'mysql', true ), $document_id, $revision ) );
			if ( 1 !== $changed ) {
				return self::conflict();
			}
			$old = $wpdb->query( $wpdb->prepare( "UPDATE %i SET state = 'retired' WHERE document_id = %d AND state = 'published' AND id <> %d", Store::table( 'versions' ), $document_id, $version_id ) );
			$new = $wpdb->update( Store::table( 'versions' ), array( 'state' => 'published' ), array( 'id' => $version_id ), array( '%s' ), array( '%d' ) );
			if ( false === $old || false === $new || false === $wpdb->query( 'COMMIT' ) ) {
				return self::failure( 'save', __( 'Publication could not be saved.', 'coocadev-filego' ), 500 );
			}
			return true;
		} finally {
			$wpdb->query( 'ROLLBACK' );
		}
	}

	/**
	 * Explain an optimistic concurrency conflict without exposing database details.
	 *
	 * @return \WP_Error Operation result.
	 */
	public static function conflict(): \WP_Error {
		return self::failure( 'conflict', __( 'Another editor changed this document. Reload before continuing.', 'coocadev-filego' ), 409 );
	}

	/**
	 * Atomically clear the public pointer with revision CAS while keeping every file.
	 *
	 * @param int $document_id Current-site logical document identifier.
	 * @param int $revision Expected document revision submitted by the editor.
	 * @return bool|\WP_Error Operation result.
	 */
	public static function unpublish( int $document_id, int $revision ): bool|\WP_Error {
		global $wpdb;
		$allowed = self::writable();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE %i SET current_version_id = NULL, status = 'unpublished', revision = revision + 1, updated_at_gmt = %s WHERE id = %d AND revision = %d", Store::table( 'documents' ), current_time( 'mysql', true ), $document_id, $revision ) );
		return 1 === $changed ? true : self::conflict();
	}

	/**
	 * Mark a safe version delete_pending before deleting its exact attachment.
	 *
	 * @param int $document_id Current-site logical document identifier.
	 * @param int $version_id Owned version identifier.
	 * @param int $revision Expected document revision submitted by the editor.
	 * @return bool|\WP_Error Operation result.
	 */
	public static function delete_version( int $document_id, int $version_id, int $revision ): bool|\WP_Error {
		global $wpdb;
		$allowed = self::writable();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$wpdb->query( 'START TRANSACTION' );
		try {
			$document = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', Store::table( 'documents' ), $document_id ) );
			$version  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', Store::table( 'versions' ), $version_id ) );
			if ( ! $document || ! $version || (int) $version->document_id !== $document_id || (int) $document->revision !== $revision || (int) $document->current_version_id === $version_id ) {
				return self::conflict();
			}
			if ( 'deleted' === $version->state ) {
				return true;
			}
			// Recover a crash after WP deleted the attachment but before the final history update.
			if ( 'delete_pending' === $version->state && ! get_post( (int) $version->attachment_id ) && current_user_can( 'manage_options' ) ) {
				$uploads  = wp_get_upload_dir();
				$relative = $version->relative_path;
				if ( ! preg_match( '#^(?:[0-9]{4}/[0-9]{2}/)?ccfg-[a-f0-9-]{36}(?:-[0-9]+)?\.pdf$#D', $relative ) || file_exists( trailingslashit( $uploads['basedir'] ) . $relative ) ) {
					return self::failure( 'repair', __( 'The attachment was removed but its file may remain. Ask your site administrator to repair this pending deletion.', 'coocadev-filego' ), 409 );
				}
				$done = $wpdb->update(
					Store::table( 'versions' ),
					array(
						'state'        => 'deleted',
						'delete_token' => null,
					),
					array( 'id' => $version_id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
				if ( 1 !== $done || false === $wpdb->query( 'COMMIT' ) ) {
					return self::conflict();
				}
				return true;
			}
			if ( ! current_user_can( 'delete_post', (int) $version->attachment_id ) ) {
				return self::failure( 'forbidden', __( 'You cannot delete this attachment.', 'coocadev-filego' ), 403 );
			}
			$path = self::ownership( $version );
			if ( is_wp_error( $path ) ) {
				return $path;
			}
			$referenced = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE current_version_id = %d LIMIT 1', Store::table( 'documents' ), $version_id ) );
			if ( $referenced ) {
				return self::conflict();
			}
			$token   = 'delete_pending' === $version->state ? $version->delete_token : wp_generate_password( 48, false, false );
			$marked  = $wpdb->update(
				Store::table( 'versions' ),
				array(
					'state'        => 'delete_pending',
					'delete_token' => $token,
				),
				array( 'id' => $version_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			$changed = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET revision = revision + 1, updated_at_gmt = %s WHERE id = %d AND revision = %d', Store::table( 'documents' ), current_time( 'mysql', true ), $document_id, $revision ) );
			if ( false === $marked || 1 !== $changed || false === $wpdb->query( 'COMMIT' ) ) {
				return self::conflict();
			}
		} finally {
			$wpdb->query( 'ROLLBACK' );
		}
		self::$delete_context = array(
			'attachment' => (int) $version->attachment_id,
			'document'   => $document_id,
			'version'    => $version_id,
			'token'      => $token,
		);
		try {
			$deleted = wp_delete_attachment( (int) $version->attachment_id, true );
		} finally {
			self::$delete_context = null;
		}
		clearstatcache( true, $path );
		if ( ! $deleted || get_post( (int) $version->attachment_id ) || is_file( $path ) ) {
			return self::failure( 'delete_pending', __( 'Deletion is pending. The version cannot be published. Retry deletion or ask your site administrator to check the attachment.', 'coocadev-filego' ), 409 );
		}
		$done = $wpdb->query( $wpdb->prepare( "UPDATE %i SET state = 'deleted', delete_token = NULL WHERE id = %d AND state = 'delete_pending' AND delete_token = %s", Store::table( 'versions' ), $version_id, $token ) );
		return 1 === $done ? true : self::failure( 'delete_pending', __( 'The file was removed, but its history needs repair.', 'coocadev-filego' ), 500 );
	}

	/**
	 * Prevent direct media deletion except for the exact pending deletion token.
	 *
	 * @param mixed    $delete Previous deletion-filter decision.
	 * @param \WP_Post $post WordPress attachment or post object.
	 * @param bool     $force_delete Whether WordPress requests permanent deletion.
	 * @return mixed Operation result.
	 */
	public static function protect_attachment( mixed $delete, \WP_Post $post, bool $force_delete ): mixed {
		global $wpdb;
		$version = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE attachment_id = %d AND state <> 'deleted'", Store::table( 'versions' ), $post->ID ) );
		if ( ! $version ) {
			return $delete;
		}
		$context  = self::$delete_context;
		$document = Store::document( (int) $version->document_id );
		if ( $context && $force_delete && 'delete_pending' === $version->state && $context['attachment'] === $post->ID && $context['version'] === (int) $version->id && $context['document'] === (int) $version->document_id && hash_equals( (string) $version->delete_token, $context['token'] ) && $document && (int) $document->current_version_id !== (int) $version->id ) {
			return $delete;
		}
		return false;
	}

	/**
	 * Prevent moving any managed version to WordPress media trash.
	 *
	 * @param mixed    $trash Previous trash-filter decision.
	 * @param \WP_Post $post WordPress attachment or post object.
	 * @return mixed Operation result.
	 */
	public static function protect_trash( mixed $trash, \WP_Post $post ): mixed {
		global $wpdb;
		if ( 'attachment' !== $post->post_type ) {
			return $trash;
		}
		$linked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE attachment_id = %d AND state <> 'deleted'", Store::table( 'versions' ), $post->ID ) );
		return $linked ? false : $trash;
	}
}
