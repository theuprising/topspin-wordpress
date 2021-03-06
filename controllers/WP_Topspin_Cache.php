<?php

/**
 * Handles caching of Topspin data into WordPress
 *
 * @package WordPress
 * @subpackage Topspin
 */
class WP_Topspin_Cache {

	/**
	 * Retrieves the last cached time string
	 *
	 * #Scope
	 * * artists
	 * * offers
	 *
	 * @access public
	 * @static
	 * @param string $scope
	 * @return string
	 */
	public static function lastCached($scope) {
		$time = get_option(sprintf('topspin_last_cache_%s', $scope));
		return ($time) ? human_time_diff($time) : 'Never';
	}

	/**
	 * Pulls artists into WordPress
	 *
	 * @access public
	 * @static
	 * @global object $topspin_artist_api
	 * @global object $topspin_cached_artists
	 * @uses WP_MediaHandler						Saves the artist's avatar image
	 * @param bool $prefetch						Sync from prefetch if available? (default: true)
	 * @return void
	 */
	public static function syncArtists($prefetch=false) {
		set_time_limit(0);
		global $topspin_artist_api, $topspin_cached_artists;
		$topspin_cached_artists = array();
		$results = false;
		// Retrieve the prefetched JSON file
		$prefetchedFile = WP_Topspin::getCacheFolder() . 'artists.json';		
		// If load from prefetch and the prefetch file exists, load it into the result set
		if($prefetch && file_exists($prefetchedFile)) {
			$artistsJson = file_get_contents($prefetchedFile);
			$results = json_decode($artistsJson);
		}
		// Fetch a new one if it doesn't exist
		else {
			$params = array(
				'page' => 1,
				'per_page' => 100
			);
			$results = $topspin_artist_api->getList($params);
		}
		// Loop through and cache it!
		if($results && $results->total_entries) {
			foreach($results->artists as $artist) {
				// Retrieve the post ID for the artist
				$artistPostId = WP_Topspin::getArtistPostId($artist);
				// Create a new post array for update/create
				$artistPost = WP_Topspin::createArtist($artist);
				// Create if not exists
				if(!$artistPostId) {
					$artistPostId = wp_insert_post($artistPost);
				}
				// Update if exists
				else {
					$artistPost['ID'] = $artistPostId;
					$artistPostId = wp_update_post($artistPost);
				}
				if($artistPostId) {
					// Update the artist post meta
					WP_Topspin::updateArtistMeta($artistPostId, $artist);
					if($artist->avatar_image) {
						if(has_post_thumbnail($artistPostId)) {
							$artistAttachmentId = get_post_thumbnail_id($artistPostId);
							$artistAttachmentFile = get_attached_file($artistAttachmentId);
							// Delete the old file
							if(file_exists($artistAttachmentFile)) { unlink($artistAttachmentFile); }
							// Save the new file
							$artistAvatarFile = WP_MediaHandler::cacheURL($artist->avatar_image);
							// Update attachment file
							update_attached_file($artistAttachmentId,$artistAvatarFile['path']);
						}
						else {
							$artistAttachmentId = WP_MediaHandler::saveURL($artist->avatar_image,$artistPostId);
							if($artistAttachmentId) { set_post_thumbnail($artistPostId,$artistAttachmentId); }
						}
					}
					// Add to the global cached array
					array_push($topspin_cached_artists, $artistPostId);
				}
			}
			// Purge stray artists
			self::purgeStrayArtists();
			// Update last cached time
			update_option('topspin_last_cache_artists', time());
		}
	}

	/**
	 * Pulls offers into WordPress
	 * 
	 * @access public
	 * @static
	 * @global array $topspin_artist_ids
	 * @global array $topspin_cached_ids			An array of post IDs that were updated
	 * @global array $topspin_cached_terms			An array of terms that were updated
	 * @param bool $prefetch						Sync from prefetch if available? (default: true)
	 * @param bool $force							Force sync?
	 * @return void
	 */
	public static function syncOffers($prefetch=true, $force=false) {
		set_time_limit(0);
		global $topspin_artist_ids, $topspin_cached_ids, $topspin_cached_terms;
		// Set syncing flag to true
		update_option('topspin_is_syncing_offers', true);
		if($force || (TOPSPIN_API_VERIFIED && TOPSPIN_POST_TYPE_DEFINED && TOPSPIN_HAS_ARTISTS && TOPSPIN_HAS_SYNCED_ARTISTS)) {
			$topspin_cached_ids = array();
			$topspin_cached_terms = array();
			if($topspin_artist_ids && is_array($topspin_artist_ids)) {
				foreach($topspin_artist_ids as $artist_id) {
					$results = false;
					// Retrieve the prefetched JSON file
					$prefetchedFile = WP_Topspin::getCacheFolder() . 'offers-' . $artist_id . '.json';
					// If load from prefetch and the prefetch file exists, load it into the result set
					if($prefetch && file_exists($prefetchedFile)) {
						$offersJson = file_get_contents($prefetchedFile);
						$results = json_decode($offersJson);
						// If there are data
						if(is_array($results) && count($results)) {
							// Loop through each API data object and sync it!
							foreach($results as $offersData) {
								self::syncOffersData($offersData);
							}
						}
					}
					// Else, load from the API
					else {
						$params = array(
							'artist_id' => $artist_id
						);
						self::syncOffersPage($params);
					}
				}
				// Purge stray offers that are not in the global cached array
				self::purgeStrayOffers();
				self::purgeStrayTerms();

				// Update last cached time
				update_option('topspin_last_cache_offers', time());
				update_option('topspin_is_syncing_offers', false);
			}
		}
	}

	/**
	 * Pulls offers into WordPress based on parameters.
	 *
	 * Upon caching, the post ID gets stored into the global variable for
	 * verification after the caching has finished to delete existing posts
	 * that weren't found.
	 * 
	 * @access public
	 * @static
	 * @global object $topspin_store_api
	 * @param array $params
	 * @return void
	 */
	public static function syncOffersPage($params) {
		global $topspin_store_api;
		$defaults = array(
			'page' => 1,
			'per_page' => 100
		);
		$params = array_merge($defaults, $params);
		$results = $topspin_store_api->getList($params);
		self::syncOffersData($results);
		// If it's not the last page, sync the next page
		if($results->current_page < $results->total_pages) {
			$nextPageParams = array(
				'page' => $results->current_page+1
			);
			$params = array_merge($params, $nextPageParams);
			self::syncOffersPage($params);
		}
	}
	
	/**
	 * Syncs a Topspin API offer results into WordPress
	 *
	 * @param object $results						The Topspin returned response
	 * @static
	 * @global array $topspin_cached_ids			An array of post IDs that were updated
	 * @global array $topspin_cached_terms			An array of terms that were updated
	 * @return void
	 */
	public static function syncOffersData($results) {
		global $topspin_cached_ids, $topspin_cached_terms;
		if($results && $results->total_entries) {
			foreach($results->offers as $key=>$offer) {
				// Cache the offer
				$offerPostId = self::cacheOffer($offer);
				// Add to the global cached array
				array_push($topspin_cached_ids, $offerPostId);
				if(isset($offer->tags) && $offer->tags) {
					foreach($offer->tags as $tag) {
						if(!in_array($tag, $topspin_cached_terms)) { array_push($topspin_cached_terms, $tag); }
					}
				}
			}
		}
	}

	/**
	 * Syncs an individual offer post ID
	 *
	 * Updates the thumbnail, and all meta data
	 *
	 * @global object $topspin_store_api
	 * @param int $offer_id						The WordPress post ID for the offer
	 * @return bool
	 */
	public static function syncOffersSingle($offer_id) {
		global $topspin_store_api;

		// Retrieve the cached offer meta data
		$offerMeta = WP_Topspin::getOfferMeta($offer_id);

		// Retrieve the new offer meta data
		$newOffer = $topspin_store_api->getOffer($offerMeta->id);

		// Cache this offer
		$offerPostId = self::cacheOffer($newOffer);

		return true;

	}
	
	/**
	 * Caches the offer into WordPress
	 *
	 * @param object $offer			The offer data returned from the Topspin API
	 * @return ibt|bool				The new offer ID if successful
	 */
	public static function cacheOffer($offer) {
		// Retrieve the post ID for the offer
		$offerPostId = WP_Topspin::getOfferPostId($offer);

		// Create a new post array for update/create
		$offerPost = WP_Topspin::createOffer($offer);

		// Create if not exists
		if(!$offerPostId) {
			$offerPostId = wp_insert_post($offerPost);
		}
		// Update if exists
		else {
			$offerPost['ID'] = $offerPostId;
			$offerPostId = wp_update_post($offerPost);
		}

		if($offerPostId) {
			// Update the offer post meta
			WP_Topspin::updateOfferMeta($offerPostId, $offer);
		}

		// If an image exists, cache it!
		if(isset($offer->poster_image) && $offer->poster_image) {
			// If the post has a thumbnail, update it!
			if(has_post_thumbnail($offerPostId)) {
				$thumbPostId = get_post_thumbnail_id($offerPostId);
				$thumbAttachment = get_attached_file($thumbPostId);

				// Delete the old file
				if(file_exists($thumbAttachment)) { unlink($thumbAttachment); }
				// Save the new file
				$thumbFile = WP_MediaHandler::cacheURL($offer->poster_image);
				// Update attachment file (load image.php if needed)
				if(!function_exists('wp_generate_attachment_metadata')) { require_once(sprintf('%swp-admin/includes/image.php', ABSPATH)); }
				$attachData = wp_generate_attachment_metadata($thumbPostId, $thumbFile['path']);
				wp_update_attachment_metadata($thumbPostId, $attachData);
			}
			else {
				// Set the offer thumbnail
				$thumbPostId = WP_MediaHandler::saveURL($offer->poster_image, $offerPostId);
				if($thumbPostId) { set_post_thumbnail($offerPostId, $thumbPostId); }
			}
		}
		
		return ($offerPostId) ? $offerPostId : false;
	}
	
	/**
	 * Purges the database of stray artist posts that are not found in the global cached array
	 *
	 * @access public
	 * @static
	 * @global object $wpdb
	 * @return void
	 */
	public static function purgeStrayArtists() {
		$delete_IDs = WP_Topspin::getStrayArtists();
		if($delete_IDs) { WP_Topspin::deleteArtistIds($delete_IDs); }
	}
	
	/**
	 * Purges the database of stray offer posts that are not found in the global cached array
	 *
	 * @access public
	 * @static
	 * @global object $wpdb
	 * @return void
	 */
	public static function purgeStrayOffers() {
		$delete_IDs = WP_Topspin::getStrayOffers();
		if($delete_IDs) { WP_Topspin::deleteOfferIds($delete_IDs); }
	}
	
	/**
	 * Purges the database of stray terms that are not found in the global cached array
	 *
	 * @access public
	 * @static
	 * @global object $wpdb
	 * @return void
	 */
	public static function purgeStrayTerms() {
		global $topspin_cached_terms;
		$terms = get_terms('spin-tags');
		// Loops through all terms in the spin tags taxonomy and delete those that are not found in the global cached array
		foreach($terms as $term) {
			if(!in_array($term->name, $topspin_cached_terms)) {
				wp_delete_term($term->term_id, 'spin-tags');
			}
		}
	}
	
	/**
	 * Purges the cached prefetch files
	 *
	 * @access public
	 * @static
	 * @return void
	 */
	public static function purgePrefetch() {
		$files = glob(WP_Topspin::getCacheFolder() . '*');
		if(count($files)) {
			foreach($files as $file) {
				if(file_exists($file)) { unlink($file); }
			}
		}
	}

}

?>