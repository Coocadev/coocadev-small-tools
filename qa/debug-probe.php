<?php
/**
 * Local bootstrap target for Visual Studio Code PHP Debug / Xdebug.
 * This is a debugger transport/bootstrap test; product behavior has separate tests.
 * Copyright 2026 Coocadev. SPDX-License-Identifier: GPL-2.0-or-later.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( '127.0.0.1', 'localhost' ), true ) ) {
    throw new RuntimeException( 'The debug probe requires the isolated local QA site.' );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$catalog = json_decode( file_get_contents( dirname( __DIR__ ) . '/catalog.json' ), true );
$checks = array();
foreach ( $catalog['products'] as $product ) {
    $file = $product['slug'] . '/' . $product['slug'] . '.php';
    $metadata = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
    if ( ! is_plugin_active( $file ) || $metadata['Version'] !== $catalog['version'] ) {
        throw new RuntimeException( 'Activate the exact catalog version of ' . $product['slug'] . ' before debugging.' );
    }
    $checks[] = array( 'plugin' => $product['slug'], 'version' => $metadata['Version'], 'active' => true );
}
if ( ! WP_DEBUG || ! WP_DEBUG_LOG || WP_DEBUG_DISPLAY ) {
    throw new RuntimeException( 'Enable WP_DEBUG and WP_DEBUG_LOG, with display off, on this local site.' );
}
WP_CLI::log( 'DEBUG_BOOTSTRAP_OK ' . count( $checks ) );
WP_CLI::log( wp_json_encode( array( 'wp' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'xdebug' => phpversion( 'xdebug' ), 'plugins' => $checks ) ) );
