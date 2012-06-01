<?
	#
	# $Id$
	#

	include("include/init.php");
	
	loadlib ('google_users');
	loadlib ('twitter_users');

	login_ensure_loggedin("/account");

	$google_auth_status = 0;
	$twitter_auth_status = 0;
	
	$google_user = google_users_get_by_user_id ($GLOBALS['cfg']['user']['id']);
	if ($google_user['user_id']) {
		$google_auth_status = 1;
	}
	
	$twitter_user = twitter_users_get_by_user_id ($GLOBALS['cfg']['user']['id']);
	if ($twitter_user['user_id']) {
		$twitter_auth_status = 1;
	}
	
	$smarty->assign ('has_google_account', $google_auth_status);
	$smarty->assign ('has_twitter_account', $twitter_auth_status);
	
	#
	# output
	#

	$smarty->display("page_account.txt");
?>