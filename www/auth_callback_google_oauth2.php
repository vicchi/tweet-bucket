<?php

include ('include/init.php');

loadlib ('google_oauth2');
loadlib ('google_users');
loadlib ('random');
loadlib ('log');

log_trace ("this is $_SERVER[SCRIPT_NAME] on {$GLOBALS['cfg']['environment']}");

# Are the bare essentials for authenticating with Google configured?

if (!$GLOBALS['cfg']['google_oauth2_client_id'] || 
		!$GLOBALS['cfg']['google_oauth2_client_secret']) {
	$GLOBALS['error']['oauth2_missing_credentials'] = 1;
	$GLOBALS['smarty']->display ('page_signin_google_oauth2.txt');
	exit ();
}

# Work out how we ended up here. If there's a state parameter in the response from 
# Google's OAuth2 server *and* if it contains a referrer *and* if that referrer is the
# PHP script to add Google authentication to an existing account then we treat this as
# such. In all other cases, we assume this is a Google authenticated signin.

$state = array ();
if ($state_query = get_str ('state')) {
	$raw_state = unserialize (rawurldecode ($state_query));
	foreach ($raw_state as $state_item) {
		$state_values = explode ('=', $state_item);
		$state[$state_values[0]] = $state_values[1];
	}
}

foreach ($state as $key => $value) {
	log_trace ('state[' . $key . '] = ' . $value);
}

$is_signin = true;

if (array_key_exists ('referrer', $state)) {
	$loc = strpos ($state['referrer'], 'account_google_oauth2');
	if ($loc !== false) {
		log_trace ('found account referrer');
		$is_signin = false;
	}
	else {
		log_trace ('did not find account referrer');
	}
}
else {
	log_trace ('no referrer in state');
}

if ($is_signin) {
	log_trace ('this is a signin');
	
	# Create a new account, authenticated via Google. We can't be already logged in
	# and signins must be enabled
	
	if ($GLOBALS['cfg']['user']['id']) {
		header ('location: ' . $GLOBALS['cfg']['abs_root_url']);
		exit ();
	}
}

else {
	log_trace ('this is an account add');
	
	# Add Google authentication to an existing account. We must be signed in and signins
	# must be enabled
	
	if (!$GLOBALS['cfg']['user']['id']) {
		header ('location: ' . $GLOBALS['cfg']['abs_root_url']);
		exit ();
	}
}

if (!$GLOBALS['cfg']['enable_feature_signin']) {
	$GLOBALS['smarty']->display ('page_signin_disabled.txt');
	exit ();
}

# See what Google's OAuth2 gave us back, one of either a temporary authorisation code
# or an error (which we take to be the absence of a code)

$code = get_str ('code');

log_trace ('code: ' . $code);

if (!$code) {
	$GLOBALS['error']['oauth2_missing_auth_code'] = 1;
	$GLOBALS['smarty']->display ('page_auth_callback_google_oauth2.txt');
	exit ();
}

# The only thing that the temporary authorisation code is good for is getting a (semi)
# permanent access token and a permanent refresh token (used to get another access token
# when the current one expires)

$args = array (
	'code' => $code
);

log_trace ('getting access token');
$rsp = google_oauth2_get_access_token ($args);
if (!$rsp['ok']) {
	$GLOBALS['error']['oauth2_access_token'] = 1;
	$GLOBALS['smarty']->display ('page_auth_callback_google_oauth2.txt');
	exit ();
}

# So far, so good. We've now got the (semi) permanent access token (this thing will expire,
# Google's like that) and a permanent refresh token.

$data = $rsp['data'];
$access_token = $data['access_token'];
$refresh_token = $data['refresh_token'];

log_trace ('access token: ' . $access_token);
log_trace ('refresh token: ' . $refresh_token);

# Finally, we need to call Google's UserInfo Profile API, signed with our access token
# to get this user's Google Profile ID, which we need to create their Flamework user
# account

$url = 'https://www.googleapis.com/oauth2/v1/userinfo';
$params = array (
	'alt' => 'json'
);
$url = google_oauth2_wrap_url ($url, $params, $access_token);

log_trace ('calling ' . $url);

$rsp = http_get ($url);
if (!$rsp['ok']) {
	$GLOBALS['error']['oauth2_user_profile'] = 1;
	$GLOBALS['smarty']->display ('page_auth_callback_google_oauth2.txt');
	exit ();
}

# And there was much rejoicing.

$json = json_decode ($rsp['body'], true);
if (!$json) {
	$GLOBALS['error']['oauth2_user_profile'] = 1;
	$GLOBALS['smarty']->display ('page_auth_callback_google_oauth2.txt');
	exit ();
}

$google_id = $json['id'];
$google_name = $json['name'];

log_trace ('google id: ' . $google_id);
log_trace ('google name: ' . $google_name);

if ($is_signin) {
	# This is a brand new user; signups allowed here?
	if (!$GLOBALS['cfg']['enable_feature_signup']) {
		$GLOBALS['smarty']->display ('page_signup_disabled.txt');
		exit ();
	}

	# As this is a Google OAuth2 user, spoof the password to be some random gibberish,
	# spoof the email to be 'google-user-id'@donotsend-google.com and make the username
	# the Google username (basically first plus last name, not the Google Account email
	# address)
	
	# First up, create the Flamework user account
	
	$create = array (
		'username' => $google_name,
		'email' => $google_id . '@donotsend-google.com',
		'password' => random_string (32)
	);

	$user = users_create_user ($create);
	if (!$user) {
		$GLOBALS['error']['dberr_user_create'] = 1;
		$GLOBALS['smarty']->display ('page_auth_callback_google_oauth2.txt');
		exit ();
	}
	
	# Do we already have a Google user account, possibly previously revoked or deleted?
	# If we do, update the access and refresh token (and reactivate it if deleted)
	
	$google_user = google_users_get_by_google_id ($google_id);
	if ($user_id = $google_user['user_id']) {
		$update = array (
			'user_id' => $user['id'],
			'deleted' => 0
		);

		if ($access_token) {
			$update['oauth_token'] = $access_token;
		}
		if ($refresh_token) {
			$update['refresh_token'] = $refresh_token;
		}
		
		if (!empty ($update)) {
			$rsp = google_users_update_user ($google_user, $update);
			if (!$rsp['ok']) {
				$GLOBALS['error']['dberr_googleuser_update'] = 1;
				$GLOBALS['smarty']->display ('page_auth_callback_google_oauth2.txt');
				exit ();
			}
		}
	}

	# Otherwise, create the Google user account
	
	$create = array (
		'user_id' => $user['id'],
		'google_id' => $google_id,
		'oauth_token' => $access_token,
		'refresh_token' => $refresh_token
	);
	
	$google_user = google_users_create_user ($create);
	if (!$google_user) {
		users_delete_user ($user);
		$GLOBALS['error']['dberr_googleuser_create'] = 1;
		$GLOBALS['smarty']->display ('page_auth_callback_google_oauth2.txt');
		exit ();
	}
}

else {
	# We're adding Google authentication to an existing account, do we already have a Google
	# account for this user's Google ID ... the user id on this Google account must match
	# the currently logged in user
	
	$google_user = google_users_get_by_google_id ($google_id);
	if ($user_id = $google_user['user_id']) {
		if ($user_id != $GLOBALS['cfg']['user']['id']) {
			# Something's gone wrong, the logged in user's id doesn't match the user id
			# in the Google account we already have for this Google ID
			
			$GLOBALS['error']['dberr_googleuser_mismatch'] = 1;
			$GLOBALS['smarty']->display ('page_auth_callback_google_oauth2.txt');
			exit ();
		}
		
		$update = array ();
		if ($access_token) {
			$update['oauth_token'] = $access_token;
		}
		if ($refresh_token) {
			$update['refresh_token'] = $refresh_token;
		}
		
		if (!empty ($update)) {
			$rsp = google_users_update_user ($google_user, $update);
			if (!$rsp['ok']) {
				$GLOBALS['error']['dberr_googleuser_update'] = 1;
				$GLOBALS['smarty']->display ('page_auth_callback_google_oauth2.txt');
				exit ();
			}
		}
	}
	
	# There's no Google account for this user, we should probably create one
	
	$create = array (
		'user_id' => $GLOBALS['cfg']['user']['id'],
		'google_id' => $google_id,
		'oauth_token' => $access_token,
		'refresh_token' => $refresh_token
	);
	$google_user = google_users_create_user ($create);
	if (!$google_user) {
		$GLOBALS['error']['dberr_googleuser_create'] = 1;
		$GLOBALS['smarty']->display ('page_auth_callback_google_oauth2.txt');
		exit ();
	}
}
# All done, now finish logging the user in (setting cookies, etc.) and
# redirecting them to some specific page if necessary.

$redir = (isset ($state['redir'])) ? $state['redir'] : '';

if ($is_signin) {
	log_trace ('finishing logging in');
	login_do_login ($user, $redir);
	exit ();
}

else {
	if (empty ($redir)) {
		$redir = '/account/';
	}
	log_trace ('redirecting to ' . $redir);
	header ('location: ' . $redir);
}

?>