<?php

// Actions
add_action('topspin_flush_permalinks',						array('WP_Topspin_Hooks_Custom_Controller', 'flushPermalinks'));
add_action('topspin_post_action',							array('WP_Topspin_Hooks_Custom_Controller', 'postAction'));
add_action('topspin_register_post_types',					array('WP_Topspin_Hooks_Custom_Controller', 'registerPostTypes'));
add_action('topspin_cron_prefetching',						array('WP_Topspin_Hooks_Custom_Controller', 'prefetchApi'));

/**
 * Handles WordPress custom hooks
 *
 * @package WordPress
 * @subpackage Topspin
 */
class WP_Topspin_Hooks_Custom_Controller {

	/**
	 * Flush the WordPress rewrite rules
	 *
	 * @access public
	 * @static
	 * @return void
	 */
	public static function flushPermalinks() {
		flush_rewrite_rules();
	}

	/**
	 * Tospin Post Action callback
	 *
	 * @access public
	 * @static
	 * @return void
	 */
	public static function postAction() {
		if($_SERVER['REQUEST_METHOD']=='POST') {
			$action = (isset($_POST['topspin_post_action'])) ? $_POST['topspin_post_action'] : '';
			switch($action) {
				case 'navmenus_save':
					WP_Topspin::updateMenus($_POST['topspin']);
					do_action('topspin_notices_changes_saved');
					break;
				case 'sync_artists':
					WP_Topspin_Cache::syncArtists(TOPSPIN_API_PREFETCHING);
					do_action('topspin_notices_synced');
					break;
				case 'sync_offers':
					WP_Topspin_Cache::syncOffers(TOPSPIN_API_PREFETCHING);
					do_action('topspin_notices_synced');
					break;
				case 'reset_cron':
					WP_Topspin_Cron::resetSchedules();
					do_action('topspin_notices_schedules_reset');
					break;
				case 'purge_prefetch':
					WP_Topspin_Cache::purgePrefetch();
					break;
			}
		}
		// Display success message if available
		if(isset($_GET['settings-updated']) && $_GET['settings-updated']=='true') {
			do_action('topspin_flush_permalinks');
			do_action('topspin_notices_changes_saved');
		}
	}

	/**
	 * Callback action when the custom post types are registered
	 *
	 * @access public
	 * @static
	 * @return void
	 */
	public static function registerPostTypes() {
		// Custom post type CMS columns
		add_filter('manage_edit-' . TOPSPIN_CUSTOM_POST_TYPE_OFFER . '_columns', array('WP_Topspin_Hooks_Controller', 'offerCustomColumns'));
		add_filter('manage_edit-' . TOPSPIN_CUSTOM_POST_TYPE_ARTIST . '_columns', array('WP_Topspin_Hooks_Controller', 'artistCustomColumns'));

		// Custom post type CMS column outputs
		add_action('manage_' . TOPSPIN_CUSTOM_POST_TYPE_OFFER . '_posts_custom_column', array('WP_Topspin_Hooks_Controller', 'offerCustomColumnOutput'), 10, 2);
		add_action('manage_' . TOPSPIN_CUSTOM_POST_TYPE_ARTIST . '_posts_custom_column', array('WP_Topspin_Hooks_Controller', 'artistCustomColumnOutput'), 10, 2);
	
		// Add theme support
		add_theme_support('post-thumbnails', array(TOPSPIN_CUSTOM_POST_TYPE_ARTIST, TOPSPIN_CUSTOM_POST_TYPE_OFFER));
	}

	/**
	 * Prefetches data and store them into a file
	 *
	 * @access public
	 * @static
	 * @global object $topspin_store_api
	 * @global object $topspin_artist_api
	 * @global array $topspin_artist_ids
	 * @return void
	 */
	public static function prefetchApi() {
		// Retrieve the cache folder
		$cacheFolder = WP_Topspin::getCacheFolder();
		
		// Check and make sure the cache folder exists
		if(wp_mkdir_p($cacheFolder)) {
			global $topspin_store_api, $topspin_artist_api, $topspin_artist_ids;

			// Prefetch artists
			$artists = $topspin_artist_api->getList();
			$artistsJson = json_encode($artists);
			WP_Topspin::writeToFile($cacheFolder . '/artists.json', $artistsJson);

			// Loop through each artist and create a JSON for that artist
			if(is_array($topspin_artist_ids) && count($topspin_artist_ids)) {
				foreach($topspin_artist_ids as $artist_id) {
					// Prefetch offer for the artist
					$offersData = array();			// the data to write (an array containing each request)
					$offersPage = 1;				// the current page
					do {
						$offersParams = array(
							'artist_id' => $artist_id,
							'page' => $offersPage,
							'per_page' => 100
						);
						$offers = $topspin_store_api->getList($offersParams);
						array_push($offersData, $offers);
						$offersPage++;
					} while($offersPage <= $offers->total_pages);
					$offersJson = json_encode($offersData);
					WP_Topspin::writeToFile($cacheFolder . '/offers-' . $artist_id . '.json', $offersJson);
				}
			}
		}
	}

}

?>