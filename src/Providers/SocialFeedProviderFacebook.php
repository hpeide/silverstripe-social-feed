<?php

namespace IsaacRankin\SocialFeed\Providers;


use League\OAuth2\Client\Provider\Facebook;
use IsaacRankin\SocialFeed\SocialFeedProviderInterface;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\RequiredFields;
use SilverStripe\ORM\Exception;
use SilverStripe\ORM\FieldType\DBField;


class SocialFeedProviderFacebook extends SocialFeedProvider implements SocialFeedProviderInterface
{
	private static $db = [
		'FacebookPageID' => 'Varchar(100)',
		'FacebookAppID' => 'Varchar(400)',
		'FacebookAppSecret' => 'Varchar(400)',
		'AccessToken' => 'Varchar(400)',
		'FacebookType' => 'Int',
	];

	private static $table_name = 'SocialFeedProviderFacebook';

	private static $singular_name = 'Facebook Provider';
	private static $plural_name = 'Facebook Providers';

	private static $summary_fields = [
		'Label',
		'Enabled',
		'FacebookPageID',
	];

	const POSTS_AND_COMMENTS = 0;
	const POSTS_ONLY = 1;
	private static $facebook_types = [
		self::POSTS_AND_COMMENTS => 'Page Posts and Comments',
		self::POSTS_ONLY => 'Page Posts Only',
	];

	private $type = 'facebook';

	public function getCMSFields()
	{
		$fields = parent::getCMSFields();
		$fields->addFieldToTab('Root.Main', LiteralField::create('sf_html_1', '<h4>To get the necessary Facebook API credentials you\'ll need to create a <a href="https://developers.facebook.com/apps" target="_blank">Facebook App.</a></h4><p>&nbsp;</p>'), 'Label');
		$fields->replaceField('FacebookType', DropdownField::create('FacebookType', 'Facebook Type', $this->config()->facebook_types));
		$fields->removeByName('AccessToken');
		return $fields;
	}

	public function getCMSValidator()
	{
		return new RequiredFields(['FacebookPageID', 'FacebookAppID', 'FacebookAppSecret']);
	}

	public function onBeforeWrite()
	{
		if ($this->FacebookAppID && $this->FacebookAppSecret) {
			$this->AccessToken = $this->FacebookAppID . '|' . $this->FacebookAppSecret;
		} else {
			if ($this->AccessToken) {
				$this->AccessToken = '';
			}
		}

		parent::onBeforeWrite();
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

	public function getFeedUncached()
	{
		$provider = new Facebook([
			'clientId' => $this->FacebookAppID,
			'clientSecret' => $this->FacebookAppSecret,
			// https://github.com/thephpleague/oauth2-facebook#graph-api-version
			'graphApiVersion' => 'v2.6',
		]);

		// For an App Access Token we can just use our App ID and App Secret pipped together
		// https://developers.facebook.com/docs/facebook-login/access-tokens#apptokens
		$accessToken = ($this->AccessToken) ? $this->AccessToken : $this->siteConfig->SocialFeedFacebookAppID . '|' . $this->siteConfig->SocialFeedFacebookAppSecret;

		// Setup query params for FB query
		$queryParameters = [
			// Get Facebook timestamps in Unix timestamp format
			'date_format' => 'U',
			// Explicitly supply all known 'fields' as the API was returning a minimal fieldset by default.
			'fields' => 'from,message,message_tags,story,story_tags,full_picture,source,link,object_id,name,caption,description,icon,privacy,type,status_type,created_time,updated_time,shares,is_hidden,is_expired,likes,comments',
			'access_token' => $accessToken,
		];
		$queryParameters = http_build_query($queryParameters);

		// Get all data for the FB page
		switch ($this->FacebookType) {
			case self::POSTS_AND_COMMENTS:
				$request = $provider->getRequest('GET', 'https://graph.facebook.com/' . $this->FacebookPageID . '/feed?' . $queryParameters);
				break;

			case self::POSTS_ONLY:
				$request = $provider->getRequest('GET', 'https://graph.facebook.com/' . $this->FacebookPageID . '/posts?' . $queryParameters);
				break;

			default:
				throw new Exception('Invalid FacebookType (' . $this->FacebookType . ')');
				break;
		}
		$result = $provider->getResponse($request);
		$output = json_decode($result->getBody(), 1);

		return $output['data'];
	}

	/**
	 * @return HTMLText
	 */
	public function getPostContent($post)
	{
		$text = isset($post['message']) ? $post['message'] : '';
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
		if (isset($post['actions'][0]['name']) && $post['actions'][0]['name'] === 'Share') {
			return $post['actions'][0]['link'];
		} else {
			if (isset($post['link']) && $post['link']) {
				// For $post['type'] === 'link' && $post['status_type'] === 'shared_story'
				return $post['link'];
			}
		}
		return null;
	}

	/**
	 * Get the user who made the post
	 *
	 * @param $post
	 * @return mixed
	 */
	public function getUserName($post)
	{
		return $post['from']['name'];
	}

	/**
	 * Get the primary image for the post
	 *
	 * @param $post
	 * @return mixed
	 */
	public function getImage($post)
	{
		return (isset($post['full_picture'])) ? $post['full_picture'] : null;
	}
}
