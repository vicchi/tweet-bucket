<?php

include ('include/init.php');

loadlib ('google_oauth2');
loadlib ('log');

log_trace ("this is $_SERVER[SCRIPT_NAME] on {$GLOBALS['cfg']['environment']}");

$redir = (get_str ('redir')) ? get_str ('redir') : '/';
$referrer = ($_SERVER['SCRIPT_NAME']) ? ($_SERVER['SCRIPT_NAME']) : __FILE__;

if (!$GLOBALS['cfg']['user']['id']) {
	header ("location: {$redir}");
	exit ();
}

if (!$GLOBALS['cfg']['enable_feature_signin']) {
	$GLOBALS['smarty']->display ('page_signin_disabled.txt');
	exit ();
}

# Are the bare essentials for authenticating with Google configured?

if (!$GLOBALS['cfg']['google_oauth2_client_id'] || 
		!$GLOBALS['cfg']['google_oauth2_client_secret']) {
	$GLOBALS['error']['oauth2_missing_credentials'] = 1;
	$GLOBALS['smarty']->display ('page_signin_google_oauth2.txt');
	exit ();
}

# Build the authentication URL; where we redirect the user to Google to ask for permission
# to authenticate

$args = array (
	'referrer=' . basename ($referrer)
);

if ($redir = get_str ('redir')) {
	$args[] = 'redir=' . $redir;
}

$url = google_oauth2_get_auth_url ($args);

# OK. Go!

header ('location: ' . $url);

?>