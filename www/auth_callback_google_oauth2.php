<?php

include ('include/init.php');

loadlib ('google_oauth2');
loadlib ('google_users');
loadlib ('random');

if ($GLOBALS['cfg']['user']['id']) {
	header ('location: ' . $GLOBALS['cfg']['abs_root_url']);
	exit ();
}

if (!$GLOBALS['cfg']['enable_feature_signin']) {
	$GLOBALS['smarty']->display ('page_signin_disabled.txt');
}

# See what Google's OAuth2 gave us back, one of either a temporary authorisation code
# or an error

$code = get_str ('code');
$error = get_str ('error');

if (($error) && (!$code)) {
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

# Finally, we need to call Google's UserInfo Profile API, signed with our access token
# to get this user's Google Profile ID, which we need to create their Flamework user
# account

$url = 'https://www.googleapis.com/oauth2/v1/userinfo';
$params = array (
	'alt' => 'json'
);
$url = google_oauth2_wrap_url ($url, $params, $access_token);

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
$username = $json['name'];

# Do we have a Google Account stored that matches the user's Google ID?

$google_user = google_users_get_by_google_id ($google_id);
if ($user_id = $google_user['google_id']) {
	$user = users_get_by_id ($user_id);
	
	# We do, but check we've stored their OAuth2 access and refresh tokens
	
	if ((!$google_user['oauth_token']) || !$google_user['refresh_token']) {
		$update = array (
			'oauth_token' => $access_token,
			'refresh_token' => $refresh_token
		);
		
		$rsp = google_users_update_user ($google_user, $update);
		if (!$rsp['ok']) {
			$GLOBALS['error']['dberr_googleuser_update'] = 1;
			$GLOBALS['smarty']->display ('page_auth_callback_google_oauth2.txt');
			exit ();
		}
	}
}

# So this is a new user; are we allowing signups?

else if (!$GLOBALS['cfg']['enable_feature_signup']) {
	$GLOBALS['smarty']->display ('page_signup_disabled.txt');
	exit ();
}

# Hello, new user! Now create two database entries in Users and GoogleUsers, joined by the
# primary id key on the Users table ...

else {
	$password = random_string (32);
	
	$user = users_create_user (array (
		'username' => $username,
		'email' => $google_id . '@donotsend-google.com',
		'password' => $password
	));
	
	if (!$user) {
		$GLOBALS['error']['dberr_user'] = 1;
		$GLOBALS['smarty']->display ('page_auth_callback_google_oauth2.txt');
		exit ();
	}
	
	$google_user = google_users_create_user (array (
		'user_id' => $user['id'],
		'google_id' => $google_id,
		'oauth_token' => $access_token,
		'refresh_token' => $refresh_token
	));

	if (!$google_user) {
		$GLOBALS['error']['dberr_googleuser'] = 1;
		$GLOBALS['smarty']->display ('page_auth_callback_google_oauth2.txt');
		exit ();
	}

# All done, now finish logging the user in (setting cookies, etc.) and
# redirecting them to some specific page if necessary.

$redir = (isset ($extra['redir'])) ? $extra['redir'] : '';

login_do_login ($user, $redir);
exit ();

}
?>