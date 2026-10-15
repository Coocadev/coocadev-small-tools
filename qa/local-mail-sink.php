<?php
/**
 * Isolated QA transport sink, never included in an installable plugin.
 * Copyright 2026 Coocadev. SPDX-License-Identifier: GPL-2.0-or-later.
 */
defined( 'ABSPATH' ) || exit;
if ( 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
    return;
}
add_filter( 'pre_wp_mail', static function () { return true; }, PHP_INT_MAX );
