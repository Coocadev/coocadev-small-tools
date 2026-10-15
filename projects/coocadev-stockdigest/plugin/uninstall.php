<?php
/** Remove configuration and tasks; preserve merchant product reminder metadata.
 *
 * @package CoocadevStockDigest
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
wp_clear_scheduled_hook( 'ccsd_daily' );
wp_clear_scheduled_hook( 'ccsd_continue' );
delete_option( 'ccsd_settings' );
delete_option( 'ccsd_run' );
