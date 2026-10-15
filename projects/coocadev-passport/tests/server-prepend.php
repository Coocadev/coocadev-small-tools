<?php
/**
 * Isolated single-thread QA server only: prevent background cron loopback deadlock.
 * No production configuration or global PHP settings are changed by this fixture.
 * Copyright (c) 2026 Coocadev. SPDX-License-Identifier: GPL-2.0-or-later.
 */
$ccpp_root = dirname( __DIR__, 3 );
$ccpp_allowed = array_filter( array( realpath( $ccpp_root . '/qa/sites/filego' ), realpath( $ccpp_root . '/qa/min-sites/filego' ) ) );
if ( PHP_SAPI !== 'cli-server' || ! in_array( realpath( $_SERVER['DOCUMENT_ROOT'] ?? '' ), $ccpp_allowed, true ) ) {
    throw new RuntimeException( 'This prepend is restricted to the isolated FileGo development server.' );
}
define( 'DISABLE_WP_CRON', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
