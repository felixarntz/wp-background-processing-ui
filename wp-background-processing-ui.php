<?php
/*
Plugin Name: WP Background Processing UI
Plugin URI: https://github.com/felixarntz/wp-background-processing-ui
Description: This library contains classes to perform background processes in WordPress including a visual UI. Based on A5hleyRich/wp-background-processing.
Version: 1.0.0
Author: Felix Arntz
Author URI: http://leaves-and-love.net
*/

require_once plugin_dir_path( __FILE__ ) . 'vendor/A5hleyRich/wp-background-processing/wp-background-processing.php';

require_once plugin_dir_path( __FILE__ ) . 'classes/wp-trackable-background-process.php';
