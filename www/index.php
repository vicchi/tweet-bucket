<?

	include('include/init.php');

	if (!$GLOBALS['cfg']['user']['id']) {
		$GLOBALS['smarty']->display ('page_index_loggedout.txt');
		exit ();
	}

	$url = '/account/';
	header ('location: ' . $url);

?>
