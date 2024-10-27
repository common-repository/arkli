<?php

class ArkliPluginCommon
{
	const AccessData = 'arkli_data_access';
	const PostMetaData = 'arkli_data';
	const PostMetaError = 'arkli_error';
	const PostMetaCampaigns = 'arkli_campaigns';

	public static function show_error()
	{
		wp_die( __('You do not have sufficient permissions to access this page.') );
	}

	public static function check_permissions()
	{
		if ( ! current_user_can('administrator') ) {
			self::show_error();
		}
	}
}
