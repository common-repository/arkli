<?php
/*
Plugin Name: Arkli Social Anywhere
Plugin URI: http://www.arkli.com/
Description: Arkli is a communications platform that allows you to schedule multiple posts to Facebook, Twitter, LinkedIn (including groups) and (soon) Google+.
Version: 1.5
Author: Arkli Inc.
Author URI: http://www.arkli.com/
License: BSD
*/


require_once( dirname(__FILE__) . '/common.php' );

class ArkliPlugin
{
	public static function init()
	{
		register_activation_hook( __FILE__, array(__CLASS__, 'plugin_activated') );
		register_deactivation_hook( __FILE__, array(__CLASS__, 'plugin_deactivated') );

		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array(__CLASS__, 'add_meta_boxes') );
			add_filter( 'redirect_post_location', array(__CLASS__, 'redirect_post_location'), 10, 2 );
		}
	}

	public static function plugin_activated()
	{
	}

	public static function plugin_deactivated()
	{
		delete_option( ArkliPluginCommon::AccessData );
	}

	public static function add_meta_boxes()
	{
		add_meta_box( 'arklimetabox', 'Arkli', array(__CLASS__, 'metabox_content'), 'post', 'normal', 'high' );
	}

	public static function metabox_content($post)
	{
		require dirname(__FILE__) . '/metabox-template.php';
	}

	public static function redirect_post_location($location, $post_id)
	{
		$mode = ( isset( $_POST['arkli_create_campaign_mode'] ) ? $_POST['arkli_create_campaign_mode'] : '' );

		if ( in_array( $mode, array( 'create_new', 'update', 'select' ) ) ) {
			return WP_PLUGIN_URL . "/arkli/create.php?post_id={$post_id}&mode={$mode}&nonce=" . wp_create_nonce("arkli-plugin-post-{$post_id}");
		} elseif ( $mode == 'create_existing' ) {
			$campaign_id = isset( $_POST['arkli_create_campaign_id'] ) ? $_POST['arkli_create_campaign_id'] : '';
			return WP_PLUGIN_URL . "/arkli/create.php?post_id={$post_id}&mode={$mode}&nonce=" . wp_create_nonce("arkli-plugin-post-{$post_id}") . '&campaign_id=' . rawurlencode($campaign_id);
		}

		return $location;
	}
}

ArkliPlugin::init();
