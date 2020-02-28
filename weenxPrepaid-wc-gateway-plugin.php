<?php
/*
Plugin Name: Weenx Prepaid WooCommerce Gateway
Description: add weenx prepaid gateway to wooCommerce plugin
Version: 1.0.0
Author: Zoha Banam
License: GPLv2 or later
*/

define( 'WEENX_PREPAID_WC_GATEWAY_VERSION', '1.0.0' );
define( 'WEENX_PREPAID_WC_GATEWAY_MINIMUM_WP_VERSION', '5.4' );
define( 'WEENX_PREPAID_WC_GATEWAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once(WEENX_PREPAID_WC_GATEWAY_PLUGIN_DIR . 'weenxPrepaid-wc-gateway.php');