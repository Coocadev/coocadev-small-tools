<?php
require $argv[1].'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
uninstall_plugin('coocadev-donemark/coocadev-donemark.php');
echo "Uninstall hook completed.\n";
