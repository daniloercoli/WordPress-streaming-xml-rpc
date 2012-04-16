<?php
/*
Plugin Name: Streaming XML-RPC
Plugin URI: 
Description: This plugin enables a streaming mode (low-memory profile) on the XML-RPC requests handler.
Version: 0.1
Author: Danilo Ercoli
Author URI: 
*/

function init_fast_xml_rpc() {
	require_once(ABSPATH . WPINC . '/class-IXR.php');
	require_once(ABSPATH . WPINC . '/class-wp-xmlrpc-server.php');
	require_once('class-fast-wp-xmlrpc-server.php');
	fast_wp_xmlrpc_server::dont_log_me('---------Plugin Loaded-------');
		global $HTTP_RAW_POST_DATA;
		if (empty($HTTP_RAW_POST_DATA)) {
			fast_wp_xmlrpc_server::dont_log_me('raw data is NOT set. YEAHH!');
		} else {
			// even though phpinfo() shows 'always_populate_raw_post_data' Off, $HTTP_RAW_POST_DATA is defined.
			fast_wp_xmlrpc_server::dont_log_me('raw data already set. Booooo!');
		}
	fast_wp_xmlrpc_server::dont_log_me('Cache dir set: '.constant( 'FAST_XMLRPC_PLUGIN_CACHE_DIR' ));
	add_filter( 'wp_xmlrpc_server_class',  create_function( null, "return 'fast_wp_xmlrpc_server';" ) );
}
add_action( 'plugins_loaded', 'init_fast_xml_rpc' );

/**
 * global constant for the plugin directory
 */
define( 'FAST_XMLRPC_PLUGIN_DIR', dirname( __FILE__ ) );
define( 'FAST_XMLRPC_PLUGIN_URL', plugins_url() . '/' . wp_basename( dirname( __FILE__ ) ) );
defined( 'FAST_XMLRPC_PLUGIN_CACHE_DIR' ) || define( 'FAST_XMLRPC_PLUGIN_CACHE_DIR', sys_get_temp_dir() );
define( 'FAST_XMLRPC_ENABLE_LOG', true );