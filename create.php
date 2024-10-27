<?php

if ( ! function_exists('add_action') ) {
	require_once( dirname(__FILE__) . '/../../../wp-load.php' );
	//require_once('/var/www/arkli/www/wordpress/wp-load.php');
}

require_once( dirname(__FILE__) . '/common.php' );

class ArkliPluginCreate
{
	protected static function set_post_meta($post_id, $meta_key, $meta_value)
	{
		if ( ! add_post_meta( $post_id, $meta_key, $meta_value, true ) ) {
			update_post_meta( $post_id, $meta_key, $meta_value );
		}
	}

	private static function _build_url(array $parts)
	{
		$url = '';

		if (isset($parts['scheme'])) $url .= $parts['scheme'] . '://';

		// Build the auth segment
		if (isset($parts['user']) && isset($parts['pass'])) $url .= $parts['user'] . ':' . $parts['pass'] . '@';
		else if (isset($parts['user'])) $url .= $parts['user'] . '@';

		if (isset($parts['host'])) $url .= $parts['host'];
		if (isset($parts['port'])) $url .= ':' . $parts['port'];

		if (isset($parts['path'])) $url .= '/' . ltrim($parts['path'], '/');

		if (isset($parts['query'])) $url .= '?' . $parts['query'];

		if (isset($parts['fragment'])) $url .= '#' . $parts['fragment'];

		return $url;
	}

	protected static function _authorize($post_id, $redirect_url)
	{
		$api = ArkliPluginCommon::get_api();

		if ( isset( $_GET['oauth_token'] ) && isset( $_GET['oauth_verifier'] ) ) {
			try {
				$accessToken = $api->fetchAccessToken( $_GET['oauth_token'], $_GET['oauth_verifier'] );

				update_option( ArkliPluginCommon::AccessData, array(
					'key' => $accessToken->key,
					'secret' => $accessToken->secret,
					'channel_id' => 0,
					'channel_name' => '',
				) );

				return true;
			} catch ( ArkliApiException $e ) {
				self::set_post_meta( $post_id, ArkliPluginCommon::PostMetaError, $e->getMessage() );
				wp_redirect($redirect_url);
				return false;
			}
		} elseif ( ( ! isset( $_GET['oauth_token'] ) ) || ( ! isset( $_GET['oauth_token_secret'] ) ) ) {
			try {
				update_option( ArkliPluginCommon::AccessData, array(
					'key' => '',
					'secret' => '',
					'channel_id' => 0,
					'channel_name' => '',
				) );

				$return_url = WP_PLUGIN_URL . '/arkli/create.php?' . $_SERVER['QUERY_STRING'];
				$authorization_url = $api->getAuthorizationUrl( $return_url );
				wp_redirect($authorization_url);
				return false;
			} catch ( ArkliApiException $e ) {
				self::set_post_meta( $post_id, ArkliPluginCommon::PostMetaError, 'Probably api key or api secret is invalid.' );
				wp_redirect($redirect_url);
				return false;
			}
		}

		// just for case
		return true;
	}

	protected static function _prepare()
	{
		ArkliPluginCommon::check_permissions();
		$post_id = ( isset($_REQUEST['post_id']) ? $_REQUEST['post_id'] : '' );

		if ( ! $post_id ) {
			ArkliPluginCommon::show_error();
		}

		ArkliPluginCommon::check_nonce("arkli-plugin-post-{$post_id}");

		$post = get_post($post_id);

		if ( ! $post ) {
			ArkliPluginCommon::show_error();
		}

		$redirect_url = admin_url('post.php') . "?post={$post_id}&action=edit";

		if ( isset( $_GET['oauth_token'] ) && isset( $_GET['oauth_verifier'] ) ) {
			if ( ! self::_authorize( $post->ID, $redirect_url ) ) {
				return false;
			}
		}

		$access_data = get_option(ArkliPluginCommon::AccessData);

		if ( ( ! $access_data['key'] ) || ( ! $access_data['secret'] ) ) {
			if ( ! self::_authorize( $post->ID, $redirect_url ) ) {
				return false;
			}
		}

		$api = ArkliPluginCommon::get_api($access_data);

		try {
			// make test call to api, and ensure that user is authorized
			$api->mooShow();
		} catch ( ArkliApiException $e ) {
			if ( $e->getCode() == ArkliApiException::ErrorCodeOAuth || strpos($e->getMessage(), 'Invalid access token: ') === 0 ) {
				if ( ! self::_authorize( $post->ID, $redirect_url ) ) {
					return false;
				}
			} else {
				self::set_post_meta( $post->ID, ArkliPluginCommon::PostMetaError, $e->getMessage() );
				wp_redirect($redirect_url);
				return false;
			}
		}

		if ( ! $access_data['channel_id'] ) {
			try {
				// Build the identifier for the current user and url
				$url = home_url();
				$parts = parse_url($url);

				$current_user = wp_get_current_user();
				if ( !($current_user instanceof WP_User) ) {
					return false;
				}

				$parts['user'] = $current_user->user_login;

				$url = self::_build_url($parts);

				$channels = $api->channelsList('wordpress', null, $url);

				if (count($channels)) {
					$channel = $channels[0];
				} else {
					$channel = null;
				}

			} catch ( ArkliApiException $e ) {
				self::set_post_meta( $post->ID, ArkliPluginCommon::PostMetaError, $e->getMessage() );
				wp_redirect($redirect_url);
				return false;
			}

			if ($channel) {
				$access_data['channel_id'] = $channel['id'];
				$access_data['channel_name'] = $channel['account_name'];

				update_option( ArkliPluginCommon::AccessData, $access_data );
			} else {
				$message = 'No channel selected &mdash; please <a href="https://www.arkli.com/profile/index">sign in to Arkli</a> and add your blog (' . home_url() . ').';
				self::set_post_meta( $post->ID, ArkliPluginCommon::PostMetaError, $message );

				wp_redirect($redirect_url);
				return false;
			}
		}

		return array( $api, $post, $access_data, $redirect_url );
	}

	private static function _convert_post($post)
	{
		$categories = wp_get_post_categories($post->ID);
		$tags = join( ', ', array_map( create_function('$t', 'return $t->name;'), wp_get_post_tags($post->ID) ) );
		$body = nl2br($post->post_content);
		$post_at = strtotime($post->post_date_gmt) > 0 ? $post->post_date_gmt : strtotime('+1 year');
		$not_scheduled = (strtotime($post->post_date_gmt) <= 0);

		$headers = array(
			'title' => $post->post_title,
			'tags' => $tags,
			'categories' => (count($categories) ? $categories[0] : ''),
			'draft_id' => $post->ID,
		);

		return array($headers, $body, $post_at, $not_scheduled);
	}

	public static function _create_campaign($api, $post, $access_data)
	{
		list($headers, $body, $post_at, $not_scheduled) = self::_convert_post($post);

		try {
			$result = $api->campaignsCreate($post->post_title,
											$headers['tags'],
											$access_data['channel_id'],
											$post_at,
											$body,
											$headers);

			$final = $result['publications'][0];

			self::set_post_meta( $post->ID, ArkliPluginCommon::PostMetaError, '' );
			self::set_post_meta( $post->ID, ArkliPluginCommon::PostMetaData,  $final);
		} catch ( ArkliApiException $e ) {
			self::set_post_meta( $post->ID, ArkliPluginCommon::PostMetaError, $e->getMessage() );
		}
	}

	public static function _update_post($api, $post, $access_data)
	{
		list($headers, $body, $post_at, $not_scheduled) = self::_convert_post($post);

		$data = get_post_meta($post->ID, ArkliPluginCommon::PostMetaData, true);

		if (isset($data['id'])) {
			$id = $data['id'];
		} else {
			self::set_post_meta( $post->ID, ArkliPluginCommon::PostMetaError, 'missing post id' );
			return;
		}

		try {

			$result = $api->publicationsUpdate($id,
											   ($not_scheduled ? null : $post_at),
											   $body,
											   $headers);

			$result = array_merge($data, $result);

			self::set_post_meta( $post->ID, ArkliPluginCommon::PostMetaError, '' );
			self::set_post_meta( $post->ID, ArkliPluginCommon::PostMetaData, $result );
		} catch ( ArkliApiException $e ) {
			self::set_post_meta( $post->ID, ArkliPluginCommon::PostMetaError, $e->getMessage() );
		}
	}

	public static function _create_post($api, $post, $access_data, $campaign_id)
	{
		list($headers, $body, $post_at, $not_scheduled) = self::_convert_post($post);

		if (!$campaign_id) {
			$data = get_post_meta($post->ID, ArkliPluginCommon::PostMetaData, true);

			if (isset($data['campaign_id'])) {
				$campaign_id = $data['campaign_id'];
			} else {
				self::set_post_meta( $post->ID, ArkliPluginCommon::PostMetaError, 'missing campaign id' );
				return;
			}
		}

		try {

			$result = $api->publicationsCreate($campaign_id,
											   $access_data['channel_id'],
											   $post_at,
											   $body,
											   $headers);

			$result['campaign_id'] = $campaign_id;

			self::set_post_meta( $post->ID, ArkliPluginCommon::PostMetaError, '' );
			self::set_post_meta( $post->ID, ArkliPluginCommon::PostMetaData, $result );
		} catch ( ArkliApiException $e ) {
			self::set_post_meta( $post->ID, ArkliPluginCommon::PostMetaError, $e->getMessage() );
		}
	}

	protected static function _select_campaign($api, $post, $access_data)
	{
		try {
			$result = $api->campaignsList();
		} catch ( ArkliApiException $e ) {
			self::set_post_meta( $post->ID, ArkliPluginCommon::PostMetaError, $e->getMessage() );
			return;
		}

		self::set_post_meta( $post->ID, ArkliPluginCommon::PostMetaCampaigns, $result );

		if ( count($result) ) {
			self::set_post_meta( $post->ID, ArkliPluginCommon::PostMetaError, '' );
		} else {
			self::set_post_meta( $post->ID, ArkliPluginCommon::PostMetaError, "You didn't have existing campaigns in Arkli." );
		}
	}

	public static function init()
	{
		$data = self::_prepare();

		if ( ! $data ) {
			return;
		}

		list( $api, $post, $access_data, $redirect_url ) = $data;
		$mode = ( isset( $_REQUEST['mode'] ) ? $_REQUEST['mode'] : '' );

		switch ($mode) {
			case 'create_new':
				self::_create_campaign($api, $post, $access_data);
				break;

			case 'update':
				self::_update_post($api, $post, $access_data);
				break;

			case 'create_existing':
				$campaign_id = ( isset( $_REQUEST['campaign_id'] ) ? $_REQUEST['campaign_id'] : '' );

				if ($campaign_id) {
					self::_create_post($api, $post, $access_data, $campaign_id);
				} else {
					self::_create_campaign($api, $post, $access_data);
				}
				break;

			case 'select':
				self::_select_campaign( $api, $post, $access_data );
				break;

			default:
				self::set_post_meta( $post->ID, ArkliPluginCommon::PostMetaError, 'Internal error: create mode missing' );
				break;
		}

		wp_redirect($redirect_url);
	}
}

ArkliPluginCreate::init();
