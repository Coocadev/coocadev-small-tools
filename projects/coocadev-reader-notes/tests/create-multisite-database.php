<?php
/** Create only the dedicated local Reader Notes multisite QA database. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'ccst_reader_notes' !== DB_NAME || '127.0.0.1:3317' !== DB_HOST ) {
	throw new RuntimeException( 'Unexpected QA source database.' );
}
global $wpdb;
if ( false === $wpdb->query( 'CREATE DATABASE IF NOT EXISTS ccst_reader_notes_multisite CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' ) ) {
	throw new RuntimeException( 'Dedicated QA database creation failed.' );
}
echo "Reader Notes multisite QA database created.\n";
