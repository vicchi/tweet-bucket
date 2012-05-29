<?php

# Google uses OAuth2 for authentication and signing, so we don't need an OAuth2 library
# (at least not yet), plus we use Flamework's lib_http for actually pushing stuff across
# the network

loadlib ("http");

#################################################################

$GLOBALS['cfg']['google_oauth2_endpoint'] = 'https://accounts.google.com/o/oauth2';
$GLOBALS['cfg']['google_oauth2_auth_url'] = '/auth';
$GLOBALS['cfg']['google_oauth2_token_url'] = '/token';

#################################################################

function google_oauth2_get_auth_url () {
	$endpoint = $GLOBALS['cfg']['google_oauth2_endpoint'];
	$url = $endpoint . $GLOBALS['cfg']['google_oauth2_auth_url'];
	
	$root_url = $GLOBALS['cfg']['abs_root_url'];
	$redirect_uri = $root_url . $GLOBALS['cfg']['google_oauth2_redirect_uri'];
	
	$params = array (
		'response_type' => 'code',
		'client_id' => $GLOBALS['cfg']['google_oauth2_client_id'],
		'redirect_uri' => $redirect_uri,
		'scope' => $GLOBALS['cfg']['google_oauth2_scope'],
		'access_type' => $GLOBALS['cfg']['google_oauth2_access_type'],
		'approval_prompt' => $GLOBALS['cfg']['google_oauth2_approval']
	);

	$url = google_oauth2_normalize_http_url ($url) . '?' . google_oauth2_to_postdata ($params);

	return $url;
}

function google_oauth2_get_access_token ($args) {
	$endpoint = $GLOBALS['cfg']['google_oauth2_endpoint'];
	$url = $endpoint . $GLOBALS['cfg']['google_oauth2_token_url'];
	
	$root_url = $GLOBALS['cfg']['abs_root_url'];
	$redirect_uri = $root_url . $GLOBALS['cfg']['google_oauth2_redirect_uri'];

	$headers = array (
		'Content-Type' => 'application/x-www-form-urlencoded'
	);
	
	$params = array (
		'client_id' => $GLOBALS['cfg']['google_oauth2_client_id'],
		'client_secret' => $GLOBALS['cfg']['google_oauth2_client_secret'],
		'redirect_uri' => $redirect_uri,
		'grant_type' => 'authorization_code'
	);
	
	$params = array_merge ($args, $params);
	$postdata = google_oauth2_to_postdata ($params);
	
	$rsp = http_post ($url, $postdata, $headers);
	if (!$rsp['ok']) {
		return $rsp;
	}
	
	$json = json_decode ($rsp['body'], true);
	if (!$json) {
		return array ('ok' => 0, 'error' => 'failed to parse response');
	}
	
	return array ('ok' => 1, 'data' => $json);
}

function oauth2_make_url ($params, $url) {
	$url = google_oauth2_normalize_http_url ($url) . '?' . google_oauth2_to_postdata ($params);
	
	return $url;
}

function google_oauth2_wrap_url ($url, $params, $access_token) {
	$params['access_token'] = $access_token;
	$url = google_oauth2_normalize_http_url ($url) . '?' . google_oauth2_to_postdata ($params);
	
	return $url;
}

function google_oauth2_to_postdata ($params) {
	$args = array ();
	
	foreach ($params as $key => $value) {
		$args[] = rawurlencode ($key) . '=' . rawurlencode ($value);
	}
	
	return implode ('&', $args);
}

function google_oauth2_normalize_http_url ($url) {
	$url_components = parse_url ($url);
	$port = '';
	
	if (array_key_exists ('port', $url_components) && $url_component['port'] != 80) {
		$port = ':' . $url_components['port'];
	}
	
	return $url_components['scheme'] .
		'://' .
		$url_components['host'] .
		$port .
		$url_components['path'];
}
?>