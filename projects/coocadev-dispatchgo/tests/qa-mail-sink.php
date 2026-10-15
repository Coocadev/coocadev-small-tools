<?php
/** QA-only fixture. Never package this file with the plugin. */
if ( defined( 'ABSPATH' ) && preg_match( '~/qa/(?:min-)?sites/dispatchgo/$~', str_replace( '\\', '/', ABSPATH ) ) ) {
    add_filter( 'pre_wp_mail', '__return_true' );
}
