<?php

namespace IsaacRankin\SocialFeed\Providers;

use League\OAuth2\Client\Provider\Instagram;
use IsaacRankin\SocialFeed\SocialFeedProviderInterface;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\RequiredFields;
use SilverStripe\Control\Director;
use SilverStripe\ORM\Exception;
use SilverStripe\ORM\FieldType\DBField;

class SocialFeedProviderInstagram extends SocialFeedProvider implements SocialFeedProviderInterface
{
	private static $db = [
		'ClientID' => 'Varchar(400)',
		'ClientSecret' => 'Varchar(400)',
		'AccessToken' => 'Varchar(400)',
	];

	private static $table_name = 'SocialFeedProviderInstagram';

	private static $singular_name = 'Instagram Provider';
	private static $plural_name = 'Instagram Providers';

	private $authBaseURL = 'https://api.instagram.com/oauth/authorize/';

	private $type = 'instagram';

	public function getCMSFields()
	{
		$fields = parent::getCMSFields();
		$fields->addFieldToTab('Root.Main', LiteralField::create('sf_html_1', '<h4>To get the necessary Instagram API credentials you\'ll need to create an <a href="https://www.instagram.com/developer/clients/manage/" target="_blank">Instagram Client.</a></h4>'), 'Label');
		$fields->addFieldToTab('Root.Main', LiteralField::create('sf_html_2', '<p>You\'ll need to add the following redirect URI <code>' . $this->getRedirectUri() . '</code> in the settings for the Instagram App.</p>'), 'Label');

		if ($this->ClientID && $this->ClientSecret) {
			$url = $this->authBaseURL . '?client_id=' . $this->ClientID . '&response_type=code&redirect_uri=' . $this->getRedirectUri() . '?provider_id=' . $this->ID;
			$fields->addFieldToTab('Root.Main', LiteralField::create('sf_html_3', '<p><a href="' . $url . '"><button type="button">Authorize App to get Access Token</a></button>'), 'Label');
		}

		return $fields;
	}

	public function getCMSValidator()
	{
		return RequiredFields::create(['ClientID', 'ClientSecret']);
	}

	/**
	 * Construct redirect URI using current class name - used during OAuth flow.
	 * @return string
	 */
	private function getRedirectUri()
	{
		return Director::absoluteBaseURL() . 'admin/social-feed/';
	}

	/**
	 * Fetch access token using code, used in the second step of OAuth flow.
	 *
	 * @param $accessCode
	 * @return \League\OAuth2\Client\Token\AccessToken
	 */
	public function fetchAccessToken($accessCode)
	{
		$provider = new Instagram([
			'clientId' => $this->ClientID,
			'clientSecret' => $this->ClientSecret,
			'redirectUri' => $this->getRedirectUri() . '?provider_id=' . $this->ID,
		]);

		//TODO: handle token expiry (as of 2016-08-03, Instagram access tokens don't expire.)
		//TODO: save returned user data?
		return $token = $provider->getAccessToken('authorization_code', [
			'code' => $accessCode,
		]);
	}

	/**
	 * Return the type of provider
	 *
	 * @return string
	 */
	public function getType()
	{
		return $this->type;
	}

	/**
	 * Fetch Instagram data for authorized user
	 *
	 * @return mixed
	 */
	public function getFeedUncached()
	{
		$provider = new Instagram([
			'clientId' => $this->ClientID,
			'clientSecret' => $this->ClientSecret,
			'redirectUri' => $this->getRedirectUri() . '?provider_id=' . $this->ID,
		]);

		$request = $provider->getRequest('GET', 'https://api.instagram.com/v1/users/self/media/recent/?access_token=' . $this->AccessToken);
		try {
			$result = $provider->getResponse($request);
		} catch (Exception $e) {
			$errorHelpMessage = '';
			if ($e->getCode() == 400) {
				// "Missing client_id or access_token URL parameter." or "The access_token provided is invalid."
				$model = 'IsaacRankin-SocialFeed-Providers-SocialFeedProviderInstagram';
				$modeluri = 'admin/social-feed/' . $model . '/EditForm/field/' . $model . '/item/';
				$cmsLink = Director::absoluteBaseURL() . $modeluri . $this->ID . '/edit';
				$errorHelpMessage = ' -- Go here ' . $cmsLink . ' and click "Authorize App to get Access Token" to restore Instagram feed.';
			}
			// Throw warning as we don't want the whole site to go down if Instagram starts failing.
			user_error($e->getMessage() . $errorHelpMessage, E_USER_WARNING);
			$result['data'] = [];
		}
		$output = json_decode($result->getBody(), 1);
		return $output['data'];
	}

	/**
	 * @return HTMLText
	 */
	public function getPostContent($post)
	{
		$text = isset($post['caption']['text']) ? $post['caption']['text'] : '';
		$result = DBField::create_field('HTMLText', $text);
		return $result;
	}

	/**
	 * Get the creation time from a post
	 *
	 * @param $post
	 * @return mixed
	 */
	public function getPostCreated($post)
	{
		return $post['created_time'];
	}

	/**
	 * Get the post URL from a post
	 *
	 * @param $post
	 * @return mixed
	 */
	public function getPostUrl($post)
	{
		return $post['link'];
	}

	/**
	 * Get the user who created the post
	 *
	 * @param $post
	 * @return mixed
	 */
	public function getUserName($post)
	{
		return $post['user']['username'];
	}

	/**
	 * Get the primary image for the post
	 *
	 * @param $post
	 * @return mixed
	 */
	public function getImage($post)
	{
		return $post['images']['standard_resolution']['url'];
	}
}
