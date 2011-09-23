<?php
/*
Plugin Name: Twitter Importer
Plugin URI: http://wordpress.org/extend/plugins/twitter-importer/
Description: Based on the RSS Importer, the Twitter importer pages through Twitter's RSS feeds of your tweets and imports each tweet as a post. You can import as Wordpress' default post type or choose a custom post type you have setup.
Author: Brad Touesnard
Version: 0.2
Author URI: http://bradt.ca/
*/

if ( !defined('WP_LOAD_IMPORTERS') )
	return;

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( !class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require_once $class_wp_importer;
}

class Twitter_Import {

	var $posts = array ();
	var $username;
	var $post_type;
	var $author;

	function header() {
		echo '<div class="wrap">';
		screen_icon();
		echo '<h2>'.__('Import Twitter').'</h2>';
	}

	function footer() {
		echo '</div>';
	}

	function unhtmlentities($string) { // From php.net for < 4.3 compat
		$trans_tbl = get_html_translation_table(HTML_ENTITIES);
		$trans_tbl = array_flip($trans_tbl);
		return strtr($string, $trans_tbl);
	}

	function greet() {
		?>
		<div class="narrow">
		<p><?php _e('Howdy! This importer allows you to import all your Twitter posts as Wordpress posts. Ideal if you\'re planning on using <a href="http://wordpress.org/extend/plugins/twitter-tools/">Twitter Tools</a>.') ?></p>
		<form name="form1" method="post" action="admin.php?import=twitter&step=1">
		<table class="form-table">
		<tr valign="top">
			<th scope="row"><label for="username"><?php _e('Twitter Username') ?></label></th>
			<td><input type="text" name="username" id="username" /></td>
		</tr>
		<tr valign="top">
			<th scope="row"><label for="post_type"><?php _e('Post Type') ?></label></th>
			<td>
				<select name="post_type" id="post_type">
				<?php
				$post_types = array_diff(get_post_types(), array('page', 'attachment', 'revision', 'nav_menu_item'));
				foreach ($post_types as $key) {
					$post_type = get_post_type_object($key);
					printf('<option value="%s">%s</option>', $key, $post_type->labels->singular_name);
				}
				?>
				</select>
				<br />
				<a href="<?php echo admin_url('/plugin-install.php?tab=search&type=term&s=custom+post+type'); ?>">You can create new custom post types with a plugin.</a>
			</td>
		</tr>
		<tr valign="top">
			<th scope="row"><label for="author"><?php _e('Author') ?></label></th>
			<td>
				<select name="author" id="author">
				<?php
				$authors = get_users_of_blog();
				foreach ($authors as $user) {
					$usero = new WP_User($user->user_id);
					$author = $usero->data;
					// Only list users who are allowed to publish
					if (! $usero->has_cap('publish_posts')) {
						continue;
					}
					printf('<option value="%s">%s</option>', $author->ID, $author->user_nicename);
				}
				?>
				</select>
			</td>
		</tr>
		</table>
		
		<p class="submit">
			<input type="submit" name="Submit" class="button" value="<?php _e('Import') ?>" />
		</p>
		</form>
		</div>
		<?php
	}

	function get_posts($importdata) {
		global $wpdb;

		set_magic_quotes_runtime(0);
		$importdata = str_replace(array ("\r\n", "\r"), "\n", $importdata);

		preg_match_all('|<item>(.*?)</item>|is', $importdata, $this->posts);
		$this->posts = $this->posts[1];
		$index = 0;
		foreach ($this->posts as $post) {
			preg_match('|<title>(.*?)</title>|is', $post, $post_title);
			$post_title = str_replace(array('<![CDATA[', ']]>', $this->username . ': '), '', $wpdb->escape( trim($post_title[1]) ));
			$post_title = stripslashes($post_title);
			$post_title = substr($post_title, 0, 30) . '...';
			$post_title = addslashes($post_title);

			preg_match('|<pubdate>(.*?)</pubdate>|is', $post, $post_date_gmt);

			if ($post_date_gmt) {
				$post_date_gmt = strtotime($post_date_gmt[1]);
			} else {
				// if we don't already have something from pubDate
				preg_match('|<dc:date>(.*?)</dc:date>|is', $post, $post_date_gmt);
				$post_date_gmt = preg_replace('|([-+])([0-9]+):([0-9]+)$|', '\1\2\3', $post_date_gmt[1]);
				$post_date_gmt = str_replace('T', ' ', $post_date_gmt);
				$post_date_gmt = strtotime($post_date_gmt);
			}

			$post_date_gmt = gmdate('Y-m-d H:i:s', $post_date_gmt);
			$post_date = get_date_from_gmt( $post_date_gmt );

			$post_type = $this->post_type;

			preg_match('|<description>(.*?)</description>|is', $post, $post_content);
			$post_content = $wpdb->escape($this->unhtmlentities(trim($post_content[1])));

			// Clean up content
			$post_content = preg_replace('|<(/?[A-Z]+)|e', "'<' . strtolower('$1')", $post_content);
			$post_content = str_replace('<br>', '<br />', $post_content);
			$post_content = str_replace('<hr>', '<hr />', $post_content);
			$post_content = str_replace($this->username . ': ', '', $post_content);
			
			$post_name = '';

			$post_author = $this->author;
			$post_status = 'publish';
			$this->posts[$index] = compact('post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_status', 'post_type', 'post_name');
			$index++;
		}
	}

	function import_posts() {
		foreach ($this->posts as $post) {
			echo "<li>".__('Importing post...');

			extract($post);

			if ($post_id = post_exists($post_title, $post_content, $post_date)) {
				_e('Post already imported');
			} else {
				$post_id = wp_insert_post($post);
				if ( is_wp_error( $post_id ) )
					return $post_id;
				if (!$post_id) {
					_e("Couldn't get post ID");
					return;
				}

				if (0 != count($categories))
					wp_create_categories($categories, $post_id);
				_e('Done !');
			}
			echo '</li>';
		}
	}

	function import() {
		$this->username = $_POST['username'];
		$this->post_type = $_POST['post_type'];
		$this->author = $_POST['author'];
		
		if(!function_exists('wp_remote_fopen')) {
			_e("Sorry, your version of Wordpress does not support the 'wp_remote_fopen' function. Please upgrade your version of Wordpress.");
			return;
		}

		echo '<ol>';

		$url = 'http://twitter.com/statuses/user_timeline/'.$this->username.'.rss?page=';
		$i = 1;
		do {
			$page_url = $url . $i;
			$data = wp_remote_fopen($page_url);

			$this->get_posts($data);
			
			$result = $this->import_posts();
			if ( is_wp_error( $result ) )
				return $result;
			
			$i++;
		}
		while (!empty($this->posts));
		
		do_action('import_done', 'twitter');

		echo '</ol>';

		echo '<h3>';
		printf(__('All done. <a href="%s">Have fun!</a>'), get_option('home'));
		echo '</h3>';
	}

	function dispatch() {
		if (empty ($_GET['step']))
			$step = 0;
		else
			$step = (int) $_GET['step'];

		$this->header();

		switch ($step) {
			case 0 :
				$this->greet();
				break;
			case 1 :
				$result = $this->import();
				if ( is_wp_error( $result ) )
					echo $result->get_error_message();
				break;
		}

		$this->footer();
	}

	function RSS_Import() {
		// Nothing.
	}
}

$twitter_import = new Twitter_Import();

register_importer('twitter', __('Twitter'), __('Import all posts from your Twitter timeline.'), array ($twitter_import, 'dispatch'));
?>
