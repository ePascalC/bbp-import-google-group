<?php
/*
Plugin Name: bbP Import Google Group
Description: Import a Google Group into bbPress topics
Plugin URI: https://wordpress.org/plugins/bbp-import-google-group/
Author: Pascal Casier
Author URI: http://casier.eu/wp-dev/
Text Domain: bbp-imp-gg
Version: 1.0.0
License: GPL2
*/

// No direct access
if ( !defined( 'ABSPATH' ) ) exit;

define ('BBPIMPGG_VERSION' , '1.0.0');

if(!defined('BBPIMPGG_PLUGIN_NAME'))
	define('BBPIMPGG_PLUGIN_NAME', plugin_basename( __FILE__ ));
if(!defined('BBPIMPGG_PLUGIN_DIR'))
	define('BBPIMPGG_PLUGIN_DIR', dirname(__FILE__));
if(!defined('BBPIMPGG_URL_PATH'))
	define('BBPIMPGG_URL_PATH', plugin_dir_url(__FILE__));

if (!is_admin()) {
	//echo 'Cheating ? You need to be admin to view this !';
	return;
} // is_admin

ini_set('max_execution_time', 1800); //1800 seconds = 30 minutes

function bbpimpgg_get_page($groupname, $page) {
	$topics = array();
	$is_next_page = false;
	
	$topics_per_page = 50;
	$forum_id = 5914;
	
	$start_page = (($page-1) * $topics_per_page)+1;
	$end_page = $page * $topics_per_page;
	
	$paging_suffix = '%5B' . $start_page . '-' . $end_page . '%5D';
	$url = 'https://groups.google.com/forum/?_escaped_fragment_=forum/' . $groupname . $paging_suffix;
	
	$doc1 = bbpimpgg_file_get_contents_utf8($url);
	if ($doc1 !== false) {
		// Check if there is a next page
		if (strpos($doc1, 'More topics &raquo;</a>')) {
			$is_next_page = true;
		}
		
		$doc = new DOMDocument;
		@$doc->loadHTML($doc1);
		$doc->preserveWhiteSpace = false;
		// Find the tables
		$tables = $doc->getElementsByTagName('table');
		$nbr_tables = $tables->length;
		// check here that there is only 1 table !

		// Get the rows
		$rows = $tables->item(0)->getElementsByTagName('tr');
		$nbr_rows = $rows->length;
		foreach ($rows as $row) {
		// loop each <tr>
			// Get unique ID
			$topic_id = $row->getElementsByTagName('td')->item(0)->getElementsByTagName('a')->item(0)->attributes->getNamedItem('href')->nodeValue;
			$topic_id = basename($topic_id);
			// Add the topic basic data in the array
			$topics[$topic_id] = array (
				'ID' => $topic_id,
				'title' => trim($row->getElementsByTagName('td')->item(0)->nodeValue),
				'author' => trim($row->getElementsByTagName('td')->item(1)->nodeValue),
			);
		}
	}
	
	foreach ($topics as $topic) {
		$topic_gg_id = $topic['ID'];
		$topic_author = $topic['author'];
		$topic_title = $topic['title'];
		$url = 'https://groups.google.com/forum/?_escaped_fragment_=topic/' . $groupname . '/' . $topic_gg_id;
		$doc1 = bbpimpgg_file_get_contents_utf8($url);
		if ($doc1 !== false) {
			if (strpos($doc1, 'title="This message has been hidden because it was flagged for abuse."')) {
echo 'Flagged as abuse : ' . $topic_gg_id . ' - ' . $url . '<br>';
				continue;
			}
			$doc = new DOMDocument;
			@$doc->loadHTML($doc1);
			$doc->preserveWhiteSpace = false;
			// Find the tables
			$tables = $doc->getElementsByTagName('table');
			$nbr_tables = $tables->length;
			// check here that there is only 1 table !
	
			// Get the rows
			$rows = $tables->item(0)->childNodes;
			$nbr_rows = $rows->length;
echo '<br>Rows : ' . $nbr_rows . '<br>';
			$first_row = true;
			foreach ($rows as $row) {
			// loop each <tr>
				// First TR is topic info
				if ($first_row) {
					// Get topic user and search user_id
					$topic_user_str = trim($row->getElementsByTagName('td')->item(1)->nodeValue);
					$user_id = bbpimpgg_get_user_id($topic_user_str);
					// Get topic time  (change to correct format)
					$topic_date_str = trim($row->getElementsByTagName('td')->item(2)->nodeValue);
					$topic_date = bbpimpgg_convert_gg_date_time($topic_date_str);
					$topic_body = $row->getElementsByTagName('td')->item(3)->nodeValue;
echo 'Topic : ' . $topic_gg_id . ' - ' . $topic_date_str . ' - ' . $url . '<br>';
echo $topic_date;
echo '<br>';
					// construct data for topic
					$topic_data = array (
						'post_parent' => $forum_id,
						'post_author' => $user_id,
						'post_title' => $topic_title,
						'post_content' => $topic_body,
						'post_date' => $topic_date,
					);
					$topic_meta = array (
						'gg_ID' => $topic_gg_id,
						'gg_url' => $url,
						'gg_group_name' => $groupname,
					);
					$topic_exist = bbpimpgg_gg_topic_exist($topic_gg_id);
					if ($topic_exist) {
						$topic_id = $topic_exist;
echo 'Topic already exist<br>';
					} else {
						$topic_id = bbp_insert_topic($topic_data, $topic_meta);
echo 'Topic inserted<br>';
					}
				$first_row = false;
				} else {
					// Get reply ID
					$reply_gg_id = $row->getElementsByTagName('td')->item(0)->getElementsByTagName('a')->item(0)->attributes->getNamedItem('href')->nodeValue;
					$reply_gg_id = basename($reply_gg_id);

echo '- Reply : ' . $reply_gg_id . '<br>';
					
					// Get reply user and search user_id
					$reply_user_str = trim($row->getElementsByTagName('td')->item(1)->nodeValue);
					$user_id = bbpimpgg_get_user_id($reply_user_str);
					// Get reply time  (change to correct format)
					$reply_date_str = trim($row->getElementsByTagName('td')->item(2)->nodeValue);
					$reply_date = bbpimpgg_convert_gg_date_time($reply_date_str);
					$reply_body = trim($row->getElementsByTagName('td')->item(3)->nodeValue);
					
					$reply_data = array (
						'post_parent' => $topic_id,
						'post_author' => $user_id,
						'post_content' => $reply_body,
						'post_date' => $reply_date,
					);
					$reply_meta = array (
						'forum_id' => $forum_id,
						'topic_id' => $topic_id,
						'gg_ID' => $reply_gg_id,
						'gg_url' => $url,
						'gg_group_name' => $groupname,
					);

					$reply_exist = bbpimpgg_gg_reply_exist($reply_gg_id);
					if ($reply_exist) {
						$reply_id = $reply_exist;
echo 'Reply already exist<br>';
					} else {
						$reply_id = bbp_insert_reply($reply_data, $reply_meta);
echo 'Reply inserted<br>';
					}
				}
			}
		}
	}

	return $is_next_page;
}

function bbpimpgg_tool_page() {
	$groupname = 'school-of-open';
	$page = 1;
	
	// Get csv with users from https://groups.google.com/forum/exportmembers/eusysadmins/
	
	$time_start = microtime(true);
	$is_next_page = true;
	while ($is_next_page) {
		$is_next_page = bbpimpgg_get_page($groupname, $page);
		$page = $page + 1;
$is_next_page = false;
	}
	$time_end = microtime(true);
	$time_diff_sec = intval($time_end - $time_start);
	echo '<br>' . $time_diff_sec . ' secs needed.<br>';
}

function bbpimpgg_add_admin_menu() {
	$confHook = add_management_page('bbPress Import Google Group', 'bbPress Import Google Group', 'delete_forums', 'bbpimpgg_tool', 'bbpimpgg_tool_page');
}
add_action('admin_menu', 'bbpimpgg_add_admin_menu');

function bbpimpgg_file_get_contents_utf8($fn) { 
	$opts = array( 
		'http' => array( 
		'method'=>"GET", 
		'header'=>"Content-Type: text/html; charset=utf-8" 
		) 
	); 

	$context = stream_context_create($opts); 
	$content = @file_get_contents($fn,false,$context); 
	
	$result = mb_convert_encoding($content, 'HTML-ENTITIES', "UTF-8");

	return $result; 
}

function bbpimpgg_convert_gg_date_time($topic_date_str) {
	$date_time = explode(" ",$topic_date_str);
	$date = explode("/",$date_time[0]);
	if ($date[2] > 69) { $date[2] = $date[2] + 1900; } else { $date[2] = $date[2] + 2000; }
	$new_date = array($date[2], $date[1], $date[0]);
	$date = implode("-",$new_date);
	$date_time = $date . ' ' . $date_time[1];
	$topic_date = $date_time;
	
	return $topic_date;
}

function bbpimpgg_gg_topic_exist($topic_gg_id) {
	return bbpimpgg_gg_topic_or_reply_exist($topic_gg_id, 'topic');
}

function bbpimpgg_gg_reply_exist($reply_gg_id) {
	return bbpimpgg_gg_topic_or_reply_exist($reply_gg_id, 'reply');
}

function bbpimpgg_gg_topic_or_reply_exist($post_gg_id, $post_type) {
	// Prepare the args
	$args = array(
		'post_type' => $post_type,
		'meta_query' => array(
			array(
				'key' => '_bbp_gg_ID',
				'value' => $post_gg_id
			)
		),
		'fields' => 'ids'
	);
	// perform the query
	$vid_query = new WP_Query( $args );

	$vid_ids = $vid_query->posts;

	// check if meta-key-value-pair exists
	if ( empty( $vid_ids ) ) {
		return false;
	} else {
		return $vid_ids[0];
	}
}

function bbpimpgg_get_user_id($gg_user_str) {
	// Set default user_id
	$user_id = 5;
	
	if (strlen($gg_user_str) < 2) {
		// 1 or no chars, use the standard user_id
		return $user_id;
	}
	
	// Check if username already created
	$args = array(  
		'meta_key'     => '_gg_user_str',
		'meta_value'   => $gg_user_str,
	);
	$blogusers = get_users( $args );
	
	// If found, return the user_id
	if (isset($blogusers[0])) {
		$user_id = $blogusers[0]->ID;
		return $user_id;
	}
	
	// First time this user is encountered, so create a new user
	
		// If user_login is longer than 60 characters, returns a WP_Error object.
		// If user_nicename is longer than 50 characters, returns a WP_Error object
	
	// Generate fake password
	$user_pass = wp_generate_password();
	
	// Generate the user_login
		// If less than 40 chars, add '_imp_gg'
		// If over 40 chars, take first 39 and add current time like '_170315172514' and '_imp_gg'
	if (strlen($gg_user_str) > 40) {
		// Over 40
		$user_login = substr($gg_user_str, 0, 39) . date("ymdHis") . '_imp_gg';
	} else {
		// Max 40
		$user_login = $gg_user_str . '_imp_gg';
	}
	
	// Create the new user
	$userdata = array(
		'user_login'  =>  $user_login,
		'user_pass'   =>  $user_pass,
		'user_nicename' => substr($gg_user_str, 0, 39),
		'display_name' => substr($gg_user_str, 0, 39),
	);
	$new_user_id = wp_insert_user( $userdata );
	
	// On success use the ID and add extra meta data
	if ( ! is_wp_error( $new_user_id ) ) {
		echo "New user created : ". $user_login . '<br>';
		$user_id = $new_user_id;
		
		// Add original user string in meta
		add_user_meta( $user_id, '_gg_user_str', $gg_user_str );
	}
	
	return $user_id;
}

?>
