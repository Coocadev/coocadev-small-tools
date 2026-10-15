<?php
/** Read-only WP-CLI --require diagnostic. Logs stack function/file names, never arguments. */
$GLOBALS['wp_filter']['doing_it_wrong_run'][10][] = array(
    'function' => static function( $function, $message ) {
        if ( '_load_textdomain_just_in_time' !== $function || ! str_contains( $message, 'woocommerce' ) ) { return; }
        $frames = array_map( static fn( $frame ) => array_intersect_key( $frame, array_flip( array( 'file', 'line', 'function', 'class' ) ) ), debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) );
        file_put_contents( __DIR__ . '/../evidence/early-translation-trace.json', json_encode( $frames, JSON_PRETTY_PRINT ) );
    },
    'accepted_args' => 2,
);
