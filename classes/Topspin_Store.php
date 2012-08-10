<?php

/*
 *	Class:				Topspin Store
 *
 *	Last Modified:		March 22, 2012
 *
 *	----------------------------------
 *	Change Log
 *	----------------------------------
 *	2012-03-21
 		- Added namespaces to several methods
 		- Deprecated some old methods
 		- Updated rebuildItems() method
 		- Updated item queries to pull based on the store's artist ID
 *	2012-02-06
 		- Added local image caching: new method cache_image()
 		- Fixed getItems() breaking when items with multiple tags are joined - [@jackdaw4 - https://github.com/topspin/topspin-wordpress/issues/31]
 *	2012-01-04
 		- Updated getArtistsList() to order by name ASC
 		- Updated rebuildArtists() to fetch/cache multiple pages (previously only caching the first 25 returned by the API)
 *	2011-09-23
 		- Updated product_get_most_popular_list() LIMIT format string
 		- Updated getStoreItems() to check count of offer types and tags before appending to query.
 		- Updated orders_get_list() and product_get_most_popular_list() to return items within the current artist ID.
 		- Updated getStoreItems() to unserialize the campaign serialized string.
 *	2011-09-22
 		- Added new method orders_get_list()
 		- Added new method orders_get_items_list()
 		- Updated getStores() to stores_get_nested_list()
 *	2011-09-19
 		- New method rebuildOrders()
 		- Updated rebuildItems() to parse the offer URL to retrieve/cache the campaign ID in it's own field
 		- Updated rebuildAll() to call the new method rebuildOrders()
 *	2011-09-07
 		- fixed PHP warning in rebuildItems() - [@ckcoyle - https://github.com/topspin/topspin-wordpress/issues/16]
 		- updated rebuildAll() to check for API credentials before rebuilding
 		- updated createStore() and updateStore() with new field: internal_name
 		- updated getStore() and getStores() to retrieve new field: internal_name
 		- updated getStores() with new parameter to fetch childs of a parent store
 *	2011-08-12
 		- updated getFilteredItems() to return items with new argument $order
 *	2011-08-05
 		- updated getStoreFeaturedItem() to return only those with an item ID set (featured item shortcode bug returning empty item)
 *	2011-08-01
 		- updated getStoreFeaturedItem() to return multiple featured items
 		- updated getStore() to return multiple featured items
 		- updated getSetting() with wpdb prefix for field selector
 *	2011-07-26
 		- removed cacheImage() (retained from pre-3.0)
 		- removed displayTabs() (retained from pre-3.0)
 		- removed rebuiltTags()
 		- updated process() boolean typo after file_get_contents()
 		- updated getStoreItems() "LIMIT" warning removed
 		- updated rebuildArtists() to delete old tags, and add new tags()
 			All tags are now cached saved into the database regardless of what artist ID is set and are connected via the new artist_id field in the topspin_tags table)
 		- updated getTagsList() to fetch tags based on the current artist ID
 		- updated getStores() to allow for fetch all statuses
 		- updated deleteStore() to have a 2nd parameter for force deletion and to delete all store settings
 		- updated getStoreTags() to select only distinct tag names (duplicate tags bug)
 		- new method createStoreFeaturedItems()
 		- new method updateStoreFeaturedItems()
 		- updated createStore() to call createStoreFeaturedItems()
 		- updated updateStore() to call updateStoreFeaturedItems() 
 		- updated several methods to prepare sql statement before querying
 			updateStoreOfferTypes()
 			updateStoreTags()
 			getStoreId()
 			getOfferTypes()
 		- added alias method getStoreFeaturedItems() for getStoreFeaturedItem()
 		- updated getStoreFeaturedItem() to read from the new featured item table
 *	2011-06-30
 		- updated getItemDefaultImage() default size parameter to "large"
 *	2011-04-12
 		- updated getStoreItems()
 			added GROUP BY item's ID in manual sorting query string
 *	2011-04-11
 		- new method setError()
 		- new method getError()
 		- updated getFilteredItems()
 			Added the 'default_image', 'default_image_large', and 'images' keys
 			Fixed the campaign object (unserialized to object)
 *	2011-04-08
 		- updated rebuildItems()
 			added caching for 'poster_image_source'
 		- updated getItem()
 			select new field 'poster_image_source'
 		- updated getFilteredItems()
 			select new field 'poster_image_source'
 		- updated getStoreItems()
 			select new field 'poster_image_source'
 			set default image depending on 'poster_image_source'
 		- new method getItemDefaultImage()
 *	2011-04-06
 		- updated setAPICredentials()
 			fixed api_username, api_key
 		- added new method getArtistsList()
 *	2011-04-05
 		- updated getItems()
 			now caches product images to the database in a new table
 		- new method getItemImages()
 		- updated getStoreItems()
 			calls getItemImages()
 		- updated getStoreTags()
 			query string prepare input
 		- updated getStores()
 			query string prepare input
 *	2011-03-23
 		- updated getTagList()
 			set default tag status (php warning fix)
 		- updated rebuildItems()
 			check if tag array exists before calling the loop, checks for offer, and mobile url (php warning fix)
 			recoded method to INSERT ON DUPLICATE KEY UPDATE and deletes old items (that are not found in the API retrieval), now returns a timestamp
 		- updated rebuildAll()
 			updates last cache with returend timestamp from rebuildItems()
 *
 */

class Topspin_Store {

	private $wpdb;
	private $artist_id;
	private $api_key;
	private $api_username;
	private $_error = '';

	private $offer_types = array(
		'key' => array(), //key value pairs
		'data' => array() //table data
	);
	
	private $_uploadDir = array(
		'wp' => array(),
		'topspin' => array()
	);

	/* Loaded Settings */
	private $_settings = array();
	private $_apiCalls = array(
		'calls' => array(),
		'last_call' => false,
		'total_calls' => 0
	);

	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->_uploadDir['wp'] = wp_upload_dir();
		$this->_set_uploads_dir($this->_uploadDir['wp']);

		//Load settings
		$this->_load_settings();
	}

	/* !----- PRIVATE METHODS ----- */
	private function _set_uploads_dir($uploadDir) {
		$this->_uploadDir['topspin'] = array(
			'path' => sprintf('%s/topspin',$uploadDir['basedir']),
			'url' => sprintf('%s/topspin',$uploadDir['baseurl'])
		);
		wp_mkdir_p($this->_uploadDir['topspin']['path']);
	}
	private function _load_settings() {
		/*
		 *	Loads the initial settings form the wp_topspin_stores table
		 */
		$sql = "SELECT `name`,`value` FROM `".$this->wpdb->prefix."topspin_settings`";
		$settings = $this->wpdb->get_results($sql);
		if(count($settings)) {
			foreach($settings as $setting) { $this->_settings[$setting->name] = $setting->value; }
		}
	}

	/* !----- GENERAL METHODS ----- */
	public function setError($msg) { $this->_error = $msg; }
	public function getError() { return $this->_error; }
	public function setAPICredentials($api_username,$api_key) {
		$this->api_username = $api_username;
		$this->api_key = $api_key;
	}

	/* !----- API METHODS ----- */
	public function api_get_calls() { return $this->_apiCalls; }
	public function api_total_calls() { return $this->_apiCalls['total_calls']; }
	public function api_check() {
		/*
		 *	Returns the error object on authorization failure
		 */
		$url = 'http://app.topspin.net/api/v1/artist';
		$data = json_decode($this->process($url,null,false));
		if(isset($data->error_detail) && strlen($data->error_detail)) { return $data; }
	}
	private function process($url,$post_args=null,$post=true) {
		/*
		 *	PARAMETERS
		 *		@url					The URL To make a call to
		 *		@post_args				The additional parameters to pass
		 *		@post					Whether the type of call is a POST call (false = GET)
		 *
		 *	RETURN
		 *		The returned resource on success
		 */
		$post_args = (is_array($post_args)) ? $post_args : array();
		// Build URL Query String if Not Post
		if(!$post && count($post_args)) { $url = sprintf('%s?%s',$url,http_build_query($post_args)); }
		// Use curl if installed on the system
		if(function_exists('curl_init')) {
			$ch = curl_init();
			curl_setopt($ch,CURLOPT_URL,$url);
			curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
			curl_setopt($ch,CURLOPT_USERPWD,$this->settings_get('topspin_api_username').':'.$this->settings_get('topspin_api_key'));
			curl_setopt($ch,CURLOPT_HTTPAUTH,CURLAUTH_ANY);
			curl_setopt($ch,CURLOPT_FRESH_CONNECT,1);
			curl_setopt($ch,CURLOPT_FORBID_REUSE,1);
			if($post) {
				curl_setopt($ch,CURLOPT_POST,true);
				curl_setopt($ch,CURLOPT_POSTFIELDS,$post_args);
			}
			$res = curl_exec($ch);
			$res_info = curl_getinfo($ch);
			$res_error = curl_error($ch);
			curl_close($ch);
			// CURL ERROR
			if($res_error) {
				$ts = $res_error;
				$res = '{"error_detail":"'.$ts.'","request_url":"'.$url.'"}';
			}
			else {
				## RESPONSE ERROR
				if($res_info['http_code']!=200) {
					$ts = '';
					switch($res_info['http_code']) {
						case '401':
							$ts = '401 Unauthorized request. Please check your API username and key.';
							break;
						case '404':
							$ts = '404 Target not found.';
							break;
						case '500':
							$ts = '500 Internal server error.';
							break;
						default:
							$ts = $res['http_code'].' Unknown error.';
							break;
					}
					$res = '{"error_detail":"'.$ts.'","request_url":"'.$url.'"}';
				}
			}
		}
		else {
			$context = @stream_context_create(array(
				'http' => array(
					'method' => 'POST',
					'header' => "Authorization: Basic " .
						base64_encode("$this->api_username:$this->api_key").
						"\r\nContent-type: application/x-www-form-urlencoded\r\n",
					'content' => $post_args,
				)
			));
			// get file without curl
	  		$res = file_get_contents(urlencode($url),false,$context);
			if($res===false) {
				// handle errors
				$ts = $http_response_header[0];
				if(ereg('^HTTP\\[0-9]+\.[0-9]+ 401 .*',$ts)) { $ts = '401 Unauthorized request. Please check your API username and key.'; }
				$res = '{"error_detail":"'.$ts.'","request_url":"'.$url.'"}';
			}
		}
		if($res) {
			array_push($this->_apiCalls['calls'],$url);
			$this->_apiCalls['last_call'] = $url;
			$this->_apiCalls['total_calls'] = count($this->_apiCalls['calls']);
			return $res;
		}
	}
	public function getArtistsTotalPages() {
		##	Retrieves the artists from the Artist Search API
		##	https://docs.topspin.net/tiki-index.php?page=Artist+Search+API
		##
		##	RETURN
		##		The number of total pages of Artists
		$url = 'http://app.topspin.net/api/v1/artist';
		$data = json_decode($this->process($url,null,false));
		if(isset($data->total_pages)) { return $data->total_pages; }
	}
	public function getArtists($page=1) {
		##	Retrieves the list of artists from the Artist Search API
		##	https://docs.topspin.net/tiki-index.php?page=Artist+Search+API
		##
		##	PARAMETERS
		##		@page (int)				Requested page number
		##
		##	RETURN
		##		A standard object containing the artist data
		$url = 'http://app.topspin.net/api/v1/artist';
		$post_args = array(
			'page' => $page
		);
		$data = json_decode($this->process($url,$post_args,false));
		$artists = array();
		if(isset($data->artists) && count($data->artists)) {
			foreach($data->artists as $item) {
				array_push($artists,$item);
			}
		}
		return $artists;
	}
	public function getArtist() {
		##	Retrieves the artist information from the Artist Search API
		##	https://docs.topspin.net/tiki-index.php?page=Artist+Search+API
		##
		##	RETURN
		##		A standard object containing the artist data
		$url = 'http://app.topspin.net/api/v1/artist';
		$data = json_decode($this->process($url,null,false));
		$artist = null;
		if(isset($data->artists) && count($data->artists)) {
			foreach($data->artists as $item) {
				if($item->id==$this->artist_id) {
					$artist = $item;
					break;
				}
			}
		}
		return $artist;
	}
	public function getTotalPages($opts) {
		/*
		 *	Retrieves the items/products/offers from the Store API
		 *	https://docs.topspin.net/tiki-index.php?page=Store+API
		 *
		 *	PARMETERS
		 *		@opts (array)					An array of options
		 *
		 *			@artist_id (int)			The artist ID
		 */
		$url = 'http://app.topspin.net/api/v1/offers';
		$data = json_decode($this->process($url,$opts,false));
		if(isset($data->total_pages)) { return $data->total_pages; }
	}
	public function ordersGetTotalPages() {
		##	Retrieves the total number of pages from the Order API
		##	https://docs.topspin.net/tiki-index.php?page=Order+API#View_Orders
		##
		##	RETURN
		##		The number of total pages of Orders
		$url = 'http://app.topspin.net/api/v1/order';
		$data = json_decode($this->process($url,null,false));
		if(isset($data->total_pages)) { return $data->total_pages; }
	}
	public function getItems($opts) {
		/*	Retrieves the items/products/offers from the Store API
		 *	https://docs.topspin.net/tiki-index.php?page=Store+API
		 *
		 *	PARAMETERS
		 *		@opts (array)					An array of options
		 *
		 *			@page (int)					Requested page number.
		 *			@offer_type (string)		Return offers of the given type. Valid types are: buy_button, email_for_media, bundle_widget (multi-track streaming player in the app) or single_track_player_widget.
		 *			@product_type (string)		Return offers for the given product type. Valid types: image, video, track, album, package, other_media, merchandise.
		 *			@tags (string)				Select spins by tag. Include multiple tags by separating them with a comma.
		 *			@updated_after (string)		date('Ymd')
		 *
		 *	RETURN
		 *		An array containing the list of items
		 */
		$url = 'http://app.topspin.net/api/v1/offers';
		$defaults = array(
			'page' => 1
		);
		$opts = array_merge($defaults,$opts);
		$data = json_decode($this->process($url,$opts,false));
		if($data && isset($data->offers)) { return $data->offers; }
	}
	public function getOrders($page=1) {
		##	Retrieves the orders from the Order API
		##	https://docs.topspin.net/tiki-index.php?page=Order+API#View_Orders
		##
		##	PARAMETERS
		##		@page				Requested page number.
		##
		##	RETURN
		##		An array containing the list of orders
		$url = 'http://app.topspin.net/api/v1/order';
		$post_args = array(
			'page' => $page
		);
		$data = json_decode($this->process($url,$post_args));
		return $data;
	}
	public function getTags() {
		##	Retrieves all spin tags under the account
		##
		##	RETURN
		##		An array containing all of the spin tags
		$artist = $this->getArtist();
		if($artist) { return $artist->spin_tags; }
	}
	public function getSKUs($campaign_id) {
		/*
			Retrieves all SKUs for the campaign_ID
		 */
		$url = 'https://app.topspin.net/api/v1/sku';
		$post_args = array(
			'campaign_id' => $campaign_id
		);
		$data = json_decode($this->process($url,$post_args));
		return $data;
	}
	/* !----- REBUILD CACHE METHODS ----- */

	public function cache_image($item_ID,$key,$size,$url) {
		/*
		 *	Returns the filename of the saved image file
		 */
		// Generate filenames for sizes based on item ID
		$filename = sprintf('%s-%s-%s.jpg',$item_ID,$key,$size);
		$path = sprintf('%s/%s',$this->_uploadDir['topspin']['path'],$filename);
		$res = copy($url,$path);
		if($res) { return $filename; }
	}

	public function rebuildAll() {
		##	Rebuilds the items, and artists database tables
		$apiStatus = $this->api_check();
		if(!$apiStatus || ($apiStatus && is_object($apiStatus) && !$apiStatus->error_detail)) {
			$this->rebuildArtists();
			$timestamp = $this->rebuildItems();
			$this->rebuildOrders();
			$this->settings_set('topspin_last_cache_all',$timestamp);
		}
	}

	public function rebuildOrders() {
		##	Rebuild and syncs the orders table with Topspin
		$totalOrdersPage = $this->ordersGetTotalPages();
		for($i=1;$i<=$totalOrdersPage;$i++) {
			$page = $this->getOrders($i);
			if(count($page->response)) {
				foreach($page->response as $order) {
					$data = array(
						'id' => $order->id,
						'artist_id' => $order->artist_id,
						'created_at' => $order->created_at,
						'subtotal' => $order->subtotal,
						'tax' => $order->tax,
						'currency' => $order->currency,
						'phone' => $order->phone,
						'shipping_method_calculator' => $order->shipping_method_calculator,
						'reshipment' => $order->reshipment,
						'fan' => $order->fan,
						'details_url' => $order->details_url,
						'exchange_rate' => $order->exchange_rate,
						'shipping_method_code' => $order->shipping_method_code,
						'shipping_address_firstname' => $order->shipping_address->firstname,
						'shipping_address_lastname' => $order->shipping_address->lastname,
						'shipping_address_address1' => $order->shipping_address->address1,
						'shipping_address_address2' => $order->shipping_address->address2,
						'shipping_address_city' => $order->shipping_address->city,
						'shipping_address_state' => $order->shipping_address->state,
						'shipping_address_postal_code' => $order->shipping_address->postal_code,
						'shipping_address_country' => $order->shipping_address->country,
						'shipping_address_phone' => $order->shipping_address->phone
					);
					$format = array('%d','%d','%s','%f','%f','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s');
					$tableFields = implode(',',array_keys($data));
					$tableFormat = implode(',',$format);
					$orderOnDuplicateUpdate = '';
					$keyIndex = 0;
					foreach($data as $key=>$field) {
						if($keyIndex>0) { $orderOnDuplicateUpdate .= ', '; }
						$orderOnDuplicateUpdate .= $key.'=VALUES('.$key.')';
						$keyIndex++;
					}
					$orderSQL = <<<EOD
					INSERT INTO {$this->wpdb->prefix}topspin_orders ({$tableFields}) VALUES ({$tableFormat})
					ON DUPLICATE KEY UPDATE {$orderOnDuplicateUpdate}
EOD;
					$this->wpdb->query($this->wpdb->prepare($orderSQL,$data));
					
					//Build items for the order
					foreach($order->items as $item) {
						$itemData = array(
							'id' => $item->id,
							'order_id' => $order->id,
							'campaign_id' => $item->campaign_id,
							'line_item_id' => $item->line_item_id,
							'spin_name' => $item->spin_name,
							'product_id' => $item->product_id,
							'product_name' => $item->product_name,
							'product_type' => $item->product_type,
							'merchandise_type' => $item->merchandise_type,
							'quantity' => $item->quantity,
							'sku_id' => $item->sku_id,
							'sku_upc' => $item->sku_upc,
							'sku_attributes' => serialize($item->sku_attributes),
							'factory_sku' => $item->factory_sku,
							'shipped' => $item->shipped,
							'status' => $item->status,
							'weight' => serialize($item->weight),
							'bundle_sku_id' => $item->bundle_sku_id,
							'shipping_date' => $item->shipping_date
						);
						$itemFormat = array('%d','%d','%d','%d','%s','%d','%s','%s','%s','%d','%d','%s','%s','%s','%d','%s','%s','%d','%s');
						$itemTableFields = implode(',',array_keys($itemData));
						$itemTableFormats = implode(',',$itemFormat);
						$itemOnDuplicateUpdate = '';
						$itemKeyIndex = 0;
						foreach($itemData as $itemKey=>$itemField) {
							if($itemKeyIndex>0) { $itemOnDuplicateUpdate .= ', '; }
							$itemOnDuplicateUpdate .= $itemKey.'=VALUES('.$itemKey.')';
							$itemKeyIndex++;
						}
						$itemSQL = <<<EOD
						INSERT INTO {$this->wpdb->prefix}topspin_orders_items ({$itemTableFields}) VALUES ({$itemTableFormats})
						ON DUPLICATE KEY UPDATE {$itemOnDuplicateUpdate}
EOD;
						$this->wpdb->query($this->wpdb->prepare($itemSQL,$itemData));
					}
				}
			}
		}
	}

	public function rebuildItems() {
		##	Rebuild and syncs the items table with Topspin
		##
		##	RETURNS
		##		The last modified timestamp
		$artists = $this->settings_get('topspin_artist_id');
		if(!is_array($artists)) { $artists = array($artists); }
		if(count($artists)) {
			$lastModified = time();
			foreach($artists as $artist_id) {
				$requestOptions = array('artist_id'=>$artist_id);
				$totalPages = $this->getTotalPages($requestOptions);
				$addedIDs = array();
				if($totalPages) {
					for($i=1;$i<=$totalPages;$i++) {
						$items = $this->getItems(array_merge($requestOptions,array('page'=>$i)));
						if($items && count($items)) {
							foreach($items as $item) {
								$campaign_id = '';
								if(isset($item->offer_url)) {
									$parts = parse_url($item->offer_url);
									$query = array();
									parse_str($parts['query'],$query);
									$campaign_id = $query['cId'];
								}
								//Retrieve inventory stock
								$skuData = false;
								$inStockQuantity = 0;
								if($item->offer_type=='buy_button') {
									$skuData = $this->getSKUs($campaign_id);
									if($skuData->status=='ok') {
										foreach($skuData->response->skus as $sku) { $inStockQuantity += $sku->in_stock_quantity; }
									}
								}
								$data = array(
									'id' => $item->id,
									'campaign_id' => $campaign_id,
									'artist_id' => $item->artist_id,
									'reporting_name' => $item->reporting_name,
									'embed_code' => $item->embed_code,
									'width' => $item->width,
									'height' => $item->height,
									'url' => $item->url,
									'poster_image' => $item->poster_image,
									'poster_image_source' => (isset($item->poster_image_source)) ? $item->poster_image_source : '',
									'product_type' => $item->product_type,
									'offer_type' => $item->offer_type,
									'description' => $item->description,
									'currency' => $item->currency,
									'price' => $item->price,
									'name' => $item->name,
									'campaign' => maybe_serialize($item->campaign),
									'offer_url' => (isset($item->offer_url)) ? $item->offer_url : '',
									'mobile_url' => (isset($item->mobile_url)) ? $item->mobile_url : '',
									'last_modified' => date('Y-m-d H:i:s',$lastModified),
									'created_date' => date('c'),
									'sku_data' => ($skuData && $skuData->status=='ok') ? maybe_serialize($skuData->response->skus) : '',
									'in_stock_quantity' => $inStockQuantity
								);
								$format = array('%d','%d','%d','%s','%s','%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d');
			
								## Get Keys
								$tableFields = implode(',',array_keys($data));
								$tableFormat = implode(',',$format);
								$onDuplicateKeyUpdate = '';
								$keyIndex = 0;
								foreach($data as $key=>$field) {
									if($key=="created_date") { continue; } //skip created_date field for UPDATE
									if($keyIndex>0) { $onDuplicateKeyUpdate .= ', '; }
									$onDuplicateKeyUpdate .= $key.'=VALUES('.$key.')';
									$keyIndex++;
								}
								## Add item
								$sql = <<<EOD
								INSERT INTO {$this->wpdb->prefix}topspin_items ({$tableFields}) VALUES ({$tableFormat})
								ON DUPLICATE KEY UPDATE {$onDuplicateKeyUpdate}
EOD;
								$this->wpdb->query($this->wpdb->prepare($sql,$data));

								##	Deletes old item tags
								$sql = 'DELETE FROM '.$this->wpdb->prefix.'topspin_items_tags WHERE item_id = %d';
								$this->wpdb->query($this->wpdb->prepare($sql,array($item->id)));
								##	Adds new item tag
								if(isset($item->tags) && is_array($item->tags)) {
									$tagFormat = array('%d','%s');
									foreach($item->tags as $tag) {
										$tagData = array(
											'item_id' => $item->id,
											'tag_name' => $tag
										);
										$this->wpdb->insert($this->wpdb->prefix.'topspin_items_tags',$tagData,$tagFormat);
									}
								} // end if tags exist

								##	Deletes old item images
								$sql = 'DELETE FROM '.$this->wpdb->prefix.'topspin_items_images WHERE item_id = %d';
								$this->wpdb->query($this->wpdb->prepare($sql,array($item->id)));
								##	Adds new item images
								if(isset($item->campaign->product->images) && is_array($item->campaign->product->images)) {
									$imageFormat = array('%d','%s','%s','%s','%s');
									foreach($item->campaign->product->images as $key=>$image) {
										$imageData = array(
											'item_id' => $item->id,
											'source_url' => $item->campaign->product->images[$key]->source_url,
											'small_url' => $item->campaign->product->images[$key]->small_url,
											'medium_url' => $item->campaign->product->images[$key]->medium_url,
											'large_url' => $item->campaign->product->images[$key]->large_url
										);
										$this->wpdb->insert($this->wpdb->prefix.'topspin_items_images',$imageData,$imageFormat);
									}
								} //end if images exist
			
								array_push($addedIDs,$item->id);
							} //end for each item
						} //end if items
					} //end for each page
				}
			}
			##	Removes all items that is not modified/inserted
			$sql = 'DELETE FROM '.$this->wpdb->prefix.'topspin_items WHERE last_modified < %s';
			$this->wpdb->query($this->wpdb->prepare($sql,array(date('Y-m-d H:i:s',$lastModified))));

			##	Removes all item tags that is not modified/inserted
			$sql = 'DELETE FROM '.$this->wpdb->prefix.'topspin_items_tags WHERE item_id NOT IN (SELECT id FROM '.$this->wpdb->prefix.'topspin_items)';
			$this->wpdb->query($this->wpdb->prepare($sql));

			##	Removes all item images that is not modified/inserted
			$sql = 'DELETE FROM '.$this->wpdb->prefix.'topspin_items_images WHERE item_id NOT IN (SELECT id FROM '.$this->wpdb->prefix.'topspin_items)';
			$this->wpdb->query($this->wpdb->prepare($sql));
			return $lastModified;
		}
	}

	public function rebuildArtists() {
		##	Rebuild and syncs the artsits table with Topspin
		$sql = 'TRUNCATE TABLE '.$this->wpdb->prefix.'topspin_artists';
		$this->wpdb->query($this->wpdb->prepare($sql));
		$totalPages = $this->getArtistsTotalPages();
		if($totalPages) {
			for($i=1;$i<=$totalPages;$i++) {
				$artists = $this->getArtists($i);
				if($artists && count($artists)) {
					foreach($artists as $artist) {
						$data = array(
							'id' => $artist->id,
							'name' => $artist->name,
							'avatar_image' => $artist->avatar_image,
							'url' => $artist->url,
							'description' => $artist->description,
							'website' => $artist->website
						);
						$this->wpdb->insert($this->wpdb->prefix.'topspin_artists',$data,array('%d','%s','%s','%s','%s','%s'));
						//Remove all old tags for this artist
						$this->wpdb->query($this->wpdb->prepare('DELETE FROM '.$this->wpdb->prefix.'topspin_tags WHERE `artist_id` = %d OR `artist_id` = %s',array(0,$artist->id)));
						//Adds all new tags for this artist
						foreach($artist->spin_tags as $tag) {
							$tagData =	array(
								'artist_id' => $artist->id,
								'name' => $tag
							);
							$this->wpdb->insert($this->wpdb->prefix.'topspin_tags',$tagData,array('%d','%s'));
						}
					}
				}
			} //end for $totalPages
		} //end if $totalPages
	}

	/* !----- SETTINGS ----- */
	public function settings_set($name,$value) {
		/*
		 *	Sets the value of a specified setting
		 *
		 *	PARAMETERS
		 *		@name				The name of the settings key
		 *		@value				The value to set
		 */
		$newvalue = maybe_serialize($value);
		if(!$this->settings_exists($name)) {
			$data = array(
				'name' => $name,
				'value' => $newvalue
			);
			$this->wpdb->insert($this->wpdb->prefix.'topspin_settings',$data,array('%s','%s'));
		}
		else { $this->wpdb->update($this->wpdb->prefix.'topspin_settings',array('value'=>$newvalue),array('name'=>$name),array('%s')); }
		$this->_load_settings();
	}
	public function settings_get($name) {
		/*
		 *	Retrieves the value of a specified setting
		 *
		 *	PARAMETERS
		 *		@name				The name of the settings key
		 *
		 *	RETURN
		 *		The value of the selected setting on success
		 */
		$value = (isset($this->_settings[$name])) ? $this->_settings[$name] : '';
		return maybe_unserialize($value);
	}
	public function settings_exists($name) {
		##	Checks if a specified setting exists
		##
		##	PARAMETERS
		##		@name				The name of the settings key
		##
		##	RETURN
		##		The count number of the specified settings key
		$sql = <<<EOD
		SELECT
			COUNT(id)
		FROM
			{$this->wpdb->prefix}topspin_settings
		WHERE
			name = %s
EOD;
		return $this->wpdb->get_var($this->wpdb->prepare($sql,$name));
	}
	
	/* !----- STORES ----- */
	public function createStore($post,$page_id) { return $this->stores_create($post,$page_id); } //3.4 - renamed to stores_update()
	public function updateStore($post,$store_id) { return $this->stores_update($post,$store_id); } //3.4 - renamed to stores_create()
	public function getStoreItems($store_id,$show_hidden=true,$artist_id=null) { return $this->stores_get_items($store_id,$show_hidden,$artist_id); } //3.4 - renamed to stores_get_items()
	public function getStoreItemsPage($items,$per_page,$page=1) { return $this->stores_get_items_page($items,$per_page,$page); }
	public function getItem($item_id) { return $this->items_get($item_id); } //3.4 - renamed to items_get()
	public function getFilteredItems($offer_types,$tags,$artist_id=null,$order='chronological') { return $this->items_get_filtered_list($offer_types,$tags,$artist_id,$order); } //3.4 - renamed to items_get_filtered_list()
	public function getStore($store_id) { return $this->stores_get($store_id); } //3.4 - renamed to stores_get()
	public function deleteStore($store_id,$force_delete=0) { return $this->stores_delete($store_id,$force_delete); } //3.4 - renamed to stores_delete()
	public function getStoreTags($store_id) { return $this->stores_get_tags($store_id) ; } //3.4 - renamed to stores_get_tags()

	public function stores_get_list($status=null) {
		/*	Retrieves a list of stores
		 *
		 *	PARAMETERS
		 *		@status						Enumeration: publish, trash, or null (all)
		 *
		 *	RETURN
		 *		The stores table as a multi-dimensional array
		 */
		$sql = <<<EOD
		SELECT
			{$this->wpdb->prefix}posts.ID,
			{$this->wpdb->prefix}posts.post_title,
			{$this->wpdb->prefix}posts.post_name,
			{$this->wpdb->prefix}topspin_stores.store_id,
			{$this->wpdb->prefix}topspin_stores.artist_id,
			{$this->wpdb->prefix}topspin_stores.status,
			{$this->wpdb->prefix}topspin_stores.created_date,
			{$this->wpdb->prefix}topspin_stores.items_per_page,
			{$this->wpdb->prefix}topspin_stores.show_all_items,
			{$this->wpdb->prefix}topspin_stores.grid_columns,
			{$this->wpdb->prefix}topspin_stores.default_sorting,
			{$this->wpdb->prefix}topspin_stores.default_sorting_by,
			{$this->wpdb->prefix}topspin_stores.items_order,
			{$this->wpdb->prefix}topspin_stores.internal_name,
			{$this->wpdb->prefix}topspin_stores.desc_length,
			{$this->wpdb->prefix}topspin_stores.sale_tag,
			{$this->wpdb->prefix}topspin_stores.featured_item
		FROM {$this->wpdb->prefix}topspin_stores
		LEFT JOIN
			{$this->wpdb->prefix}posts ON {$this->wpdb->prefix}topspin_stores.post_id = {$this->wpdb->prefix}posts.ID
EOD;
		if(!is_null($status)) {
			if(in_array($status,array('publish','trash'))) {
				$sql .= <<<EOD
		WHERE
			{$this->wpdb->prefix}topspin_stores.status = '%s'
EOD;
			}
		}
		$prepareArgs = array($status);
		$stores =  $this->wpdb->get_results($this->wpdb->prepare($sql,$prepareArgs));
		return $stores;
	}
	public function stores_get_nested_list($status='publish',$storeParent=0) {	//to be deprecated
		/*	Retrieves a list of Stores in a nested order
		 *
		 *	PARAMETERS
		 *		@status						Enumeration: publish, trash, all
		 *		@storeParent (int)			The store parent ID
		 *
		 *	RETURN
		 *		The stores table as a multi-dimensional array
		 */
		$prepareArgs = array($status);
		$sql = <<<EOD
		SELECT
			{$this->wpdb->prefix}posts.ID,
			{$this->wpdb->prefix}posts.post_title,
			{$this->wpdb->prefix}posts.post_name,
			{$this->wpdb->prefix}topspin_stores.store_id,
			{$this->wpdb->prefix}topspin_stores.artist_id,
			{$this->wpdb->prefix}topspin_stores.status,
			{$this->wpdb->prefix}topspin_stores.created_date,
			{$this->wpdb->prefix}topspin_stores.items_per_page,
			{$this->wpdb->prefix}topspin_stores.show_all_items,
			{$this->wpdb->prefix}topspin_stores.grid_columns,
			{$this->wpdb->prefix}topspin_stores.default_sorting,
			{$this->wpdb->prefix}topspin_stores.default_sorting_by,
			{$this->wpdb->prefix}topspin_stores.items_order,
			{$this->wpdb->prefix}topspin_stores.internal_name,
			{$this->wpdb->prefix}topspin_stores.desc_length,
			{$this->wpdb->prefix}topspin_stores.sale_tag,
			{$this->wpdb->prefix}topspin_stores.featured_item
		FROM {$this->wpdb->prefix}topspin_stores
		LEFT JOIN
			{$this->wpdb->prefix}posts ON {$this->wpdb->prefix}topspin_stores.post_id = {$this->wpdb->prefix}posts.ID
EOD;
		if(in_array($status,array('publish','trash'))) {
			//If the parent is set, get only stores under the specified parent
			if($storeParent) {
				$sql .= <<<EOD
		WHERE
			{$this->wpdb->prefix}topspin_stores.status = '%s'
			AND {$this->wpdb->prefix}posts.post_parent IN (
				SELECT
					{$this->wpdb->prefix}topspin_stores.post_id
				FROM {$this->wpdb->prefix}topspin_stores
				WHERE
					{$this->wpdb->prefix}topspin_stores.store_id = '%d'
				)
EOD;
				$prepareArgs[] = $storeParent;
			}
			//Else, get the parent stores
			else {
				$sql .= <<<EOD
		WHERE
			(
				{$this->wpdb->prefix}topspin_stores.status = '%s'
				AND {$this->wpdb->prefix}posts.post_parent = 0
			)
			OR
			(
				{$this->wpdb->prefix}posts.post_parent NOT IN (
				SELECT
					{$this->wpdb->prefix}topspin_stores.post_id
				FROM {$this->wpdb->prefix}topspin_stores
				)
			)
EOD;
			}
		}
		$sql .= <<<EOD
		ORDER BY
			{$this->wpdb->prefix}posts.menu_order ASC
EOD;
		if(in_array($status,array('publish','trash','all'))) {
			$stores = $this->wpdb->get_results($this->wpdb->prepare($sql,$prepareArgs));
			if(count($stores)) {
				//Get childs
				foreach($stores as $storeKey=>$storeRow) {
					$stores[$storeKey]->store_childs = $this->stores_get_nested_list($status,$storeRow->store_id);
				}
			}
			return $stores;
		}
	}
	public function stores_get($store_id) {
		/*	Retrieves the Store entry with the attached Page
		 *
		 *	PARAMETERS
		 *		@store_id (int)			The store ID
		 *
		 *	RETURN
		 *		The store object array
		 */
		$sql = <<<EOD
		SELECT
			{$this->wpdb->prefix}posts.ID AS post_id,
			{$this->wpdb->prefix}posts.post_title AS name,
			{$this->wpdb->prefix}posts.post_name AS slug,
			{$this->wpdb->prefix}topspin_stores.store_id AS id,
			{$this->wpdb->prefix}topspin_stores.artist_id,
			{$this->wpdb->prefix}topspin_stores.status,
			{$this->wpdb->prefix}topspin_stores.created_date,
			{$this->wpdb->prefix}topspin_stores.items_per_page,
			{$this->wpdb->prefix}topspin_stores.show_all_items,
			{$this->wpdb->prefix}topspin_stores.grid_columns,
			{$this->wpdb->prefix}topspin_stores.default_sorting,
			{$this->wpdb->prefix}topspin_stores.default_sorting_by,
			{$this->wpdb->prefix}topspin_stores.items_order,
			{$this->wpdb->prefix}topspin_stores.internal_name,
			{$this->wpdb->prefix}topspin_stores.desc_length,
			{$this->wpdb->prefix}topspin_stores.sale_tag
		FROM {$this->wpdb->prefix}topspin_stores
		LEFT JOIN
			{$this->wpdb->prefix}posts ON {$this->wpdb->prefix}topspin_stores.post_id = {$this->wpdb->prefix}posts.ID
		WHERE
			{$this->wpdb->prefix}topspin_stores.store_id = '%d'
EOD;
		$data = $this->wpdb->get_row($this->wpdb->prepare($sql,array($store_id)),ARRAY_A);
		if(is_array($data) && isset($data['id'])) {
			## Get Featured Items
			$data['featured_item'] = $this->getStoreFeaturedItems($data['id']);
			## Get Offer Types
			$data['offer_types'] = $this->getStoreOfferTypes($data['id']);
			## Get Tags
			$data['tags'] = $this->getStoreTags($data['id']);
			return $data;
		}
	}
	public function stores_create($post,$page_id) {
		/*	Creates the Store entry with the attached Page
		 *
		 *	PARAMETERS
		 *		@post				The post data array
		 *		@page_id			The newly created page ID to tie this store to
		 *
		 *	RETURN
		 *		The newly created store ID
		 */
		$data = array(
			'post_id' => $page_id,
			'artist_id' => $post['artist_id'],
			'status' => 'publish',
			'items_per_page' => $post['items_per_page'],
			'show_all_items' => $post['show_all_items'],
			'grid_columns' => $post['grid_columns'],
			'default_sorting' => $post['default_sorting'],
			'default_sorting_by' => $post['default_sorting_by'],
			'items_order' => $post['items_order'],
			'internal_name' => $post['internal_name'],
			'desc_length' => $post['desc_length'],
			'sale_tag' => $post['sale_tag']
		);
		//Add to the store table
		$this->wpdb->insert($this->wpdb->prefix.'topspin_stores',$data,array('%d','%d','%s','%d','%d','%d','%s','%s','%s','%s','%d','%s'));
		$store_id = $this->wpdb->insert_id;
		## Add Featured images
		$this->createStoreFeaturedItems($post['featured_item'],$store_id);
		## Add Offer Types
		$this->createStoreOfferTypes($post['offer_types'],$store_id);
		## Add Tags
		$this->createStoreTags($post['tags'],$store_id);
		return $store_id;
	}
	public function stores_update($post,$store_id) {
		/*	Updates the specified store entry
		 *
		 *	PARAMETERS
		 *		@post				The store data array
		 *		@store_id			The store ID to edit
		 *
		 *	RETURN
		 *		True on success
		 */
		$data = array(
			'artist_id' => $post['artist_id'],
			'items_per_page' => $post['items_per_page'],
			'show_all_items' => $post['show_all_items'],
			'grid_columns' => $post['grid_columns'],
			'default_sorting' => $post['default_sorting'],
			'default_sorting_by' => $post['default_sorting_by'],
			'items_order' => $post['items_order'],
			'internal_name' => $post['internal_name'],
			'desc_length' => $post['desc_length'],
			'sale_tag' => $post['sale_tag']
		);
		$this->wpdb->update($this->wpdb->prefix.'topspin_stores',$data,array('store_id'=>$store_id),array('%d','%d','%d','%d','%s','%s','%s','%s','%d','%s'),array('%d'));
		## Add Featured Items
		$this->updateStoreFeaturedItems($post['featured_item'],$store_id);
		## Add Offer Types
		$this->updateStoreOfferTypes($post['offer_types'],$store_id);
		## Add Tags
		$this->updateStoreTags($post['tags'],$store_id);
	}
	public function stores_delete($store_id,$force_delete=0) {
		/*	Deletes a trash (sends to trash)
		 *
		 *	PARAMETERS
		 *		@store_id			The store ID to set status as "trash"
		 *		@force_delete		Force delete
		 *
		 *	RETURN
		 *		True if successfull
		 */
		$theStore = $this->stores_get($store_id);
		if($theStore) {
			if(!$force_delete) { $this->wpdb->update($this->wpdb->prefix.'topspin_stores',array('status'=>'trash'),array('store_id'=>$store_id),array('%s'),array('%d')); }
			else {
				//Delete the store and all it's settings
				$this->wpdb->query($this->wpdb->prepare('DELETE FROM '.$this->wpdb->prefix.'topspin_stores WHERE store_id = %d LIMIT 1',array($store_id)));
				$this->wpdb->query($this->wpdb->prepare('DELETE FROM '.$this->wpdb->prefix.'topspin_stores_featured_items WHERE store_id = %d',array($store_id)));
				$this->wpdb->query($this->wpdb->prepare('DELETE FROM '.$this->wpdb->prefix.'topspin_stores_offer_type WHERE store_id = %d',array($store_id)));
				$this->wpdb->query($this->wpdb->prepare('DELETE FROM '.$this->wpdb->prefix.'topspin_stores_tag WHERE store_id = %d',array($store_id)));
			}
			wp_delete_post($theStore['post_id'],$force_delete);
			return true;
		}
	}
	public function stores_get_tags($store_id) {
		/*	Retrieves the list of tags for a store
		 *
		 *	PARAMETER
		 *		@store_id			The store ID
		 *
		 *	RETURN
		 *		The ordered tag list
		 */
		$sql = <<<EOD
		SELECT
			DISTINCT({$this->wpdb->prefix}topspin_tags.name),
			{$this->wpdb->prefix}topspin_stores_tag.order_num,
			{$this->wpdb->prefix}topspin_stores_tag.status
		FROM {$this->wpdb->prefix}topspin_tags
		LEFT JOIN
			{$this->wpdb->prefix}topspin_stores_tag ON {$this->wpdb->prefix}topspin_tags.name = {$this->wpdb->prefix}topspin_stores_tag.tag
		WHERE
			{$this->wpdb->prefix}topspin_stores_tag.store_id = '%d'
		ORDER BY
			{$this->wpdb->prefix}topspin_stores_tag.order_num ASC
EOD;
		$storeTags = $this->wpdb->get_results($this->wpdb->prepare($sql,array($store_id)),ARRAY_A);
		//Append new and updated tags
		$updatedSql = <<<EOD
		SELECT
			{$this->wpdb->prefix}topspin_tags.name
		FROM {$this->wpdb->prefix}topspin_tags
		WHERE
			{$this->wpdb->prefix}topspin_tags.name NOT IN (
				SELECT
					DISTINCT({$this->wpdb->prefix}topspin_tags.name)
				FROM {$this->wpdb->prefix}topspin_tags
				LEFT JOIN
					{$this->wpdb->prefix}topspin_stores_tag ON {$this->wpdb->prefix}topspin_tags.name = {$this->wpdb->prefix}topspin_stores_tag.tag
				WHERE
					{$this->wpdb->prefix}topspin_stores_tag.store_id = %d
			)
			AND artist_id IN (%s)
EOD;
		$aIds = $this->settings_get('topspin_artist_id');
		if(is_string($aIds)) { $artistIds = array($aIds); }
		else { $artistIds = $aIds; }
		$artistIds = implode(',',$artistIds);
		$newTags = $this->wpdb->get_results($this->wpdb->prepare($updatedSql,array($store_id,$artistIds)),ARRAY_A);
		foreach($newTags as $tag) {
			$tagArr = array(
				'name' => $tag['name'],
				'order_num' => 0,
				'status' => 0
			);
			array_push($storeTags,$tagArr);
		}
		return $storeTags;
	}
	public function createStoreFeaturedItems($featured_item,$store_id) {
		/*	Adds the featured images into the database
		 *
		 *	PARAMETERS
		 *		@featured_items (int | array)			An array containing the item ID's
		 *		@store_id (int)							The store ID
		 */
		if(is_array($featured_item)) {
			foreach($featured_item as $key=>$item_id) {
				if($item_id) {
					$data = array(
						'store_id' => $store_id,
						'item_id' => $item_id,
						'order_num' => $key
					);
					$this->wpdb->insert($this->wpdb->prefix.'topspin_stores_featured_items',$data,array('%d','%d','%d'));
				}
			}
		}
		else {
			$data = array(
				'store_id' => $store_id,
				'item_id' => $featured_item,
				'order_num' => 0
			);
			$this->wpdb->insert($this->wpdb->prefix.'topspin_stores_featured_items',$data,array('%d','%d','%d'));
		}
	}
	public function createStoreOfferTypes($offer_types,$store_id) {
		##	Adds the order offer types into the database
		##
		##	PARAMETERS
		##		@offer_types		The offer type array
		##		@store_id			The storeID
		$key = 0;
		$types_added = array();
		## Add active types
		foreach($offer_types as $type) {
			$data = array(
				'store_id' => $store_id,
				'type' => $type,
				'order_num' => $key,
				'status' => 1
			);
			$format = array(
				'%d',
				'%s',
				'%d',
				'%d'
			);
			$this->wpdb->insert($this->wpdb->prefix.'topspin_stores_offer_type',$data,$format);
			$key++;
			$types_added[] = $type;
		}
		## Add inactive types
		$types_list = $this->getOfferTypes();
		foreach($types_list as $type) {
			if(!in_array($type['type'],$types_added)) {
				$data = array(
					'store_id' => $store_id,
					'type' => $type['type'],
					'order_num' => $key,
					'status' => 0
				);
				$format = array(
					'%d',
					'%s',
					'%d',
					'%d'
				);
				$this->wpdb->insert($this->wpdb->prefix.'topspin_stores_offer_type',$data,$format);
				$key++;
				$types_added[] = $type;
			}
		}
	}
	public function createStoreTags($tags,$store_id) {
		##	Adds the order tags into the database
		##
		##	PARAMETERS
		##		@tags				The tags array
		##		@store_id			The storeID
		$key = 0;
		$tags_added = array();
		## Add active tags
		foreach($tags as $tag) {
			$data = array(
				'store_id' => $store_id,
				'tag' => $tag,
				'order_num' => $key,
				'status' => 1
			);
			$format = array(
				'%d',
				'%s',
				'%d',
				'%d'
			);
			$this->wpdb->insert($this->wpdb->prefix.'topspin_stores_tag',$data,$format);
			$key++;
			$tags_added[] = $tag;
		}
		## Add inactive tags
		$store = $this->getStore($store_id);
		$tags_list = $this->tags_get_list($store['artist_id']);
		foreach($tags_list as $tag) {
			if(!in_array($tag['name'],$tags_added)) {
				$data = array(
					'store_id' => $store_id,
					'tag' => $tag['name'],
					'order_num' => $key,
					'status' => 0
				);
				$format = array(
					'%d',
					'%s',
					'%d',
					'%d'
				);
				$this->wpdb->insert($this->wpdb->prefix.'topspin_stores_tag',$data,$format);
				$key++;
				$tags_list[] = $tag['name'];
			}
		}
	}
	public function updateStoreFeaturedItems($featured_item,$store_id) {
		/*	Updates the featured item for the specified store ID
		 *
		 *	PARAMETERS
		 *		@featured_items (int | array)			An array containing the item ID's
		 *		@store_id (int)							The store ID
		 */
	 	//Deletes old featured items
	 	$sql = 'DELETE FROM '.$this->wpdb->prefix.'topspin_stores_featured_items WHERE store_id = %d';
	 	$this->wpdb->query($this->wpdb->prepare($sql,array($store_id)));
	 	//Adds new featured items
		$this->createStoreFeaturedItems($featured_item,$store_id);
	}
	public function updateStoreOfferTypes($offer_types,$store_id) {
		/*
		 *	PARAMETERS
		 *		@offer_types		The offer type array
		 *		@store_Id			The store ID
		 */
		$sql = 'DELETE FROM '.$this->wpdb->prefix.'topspin_stores_offer_type WHERE store_id = %d';
		$this->wpdb->query($this->wpdb->prepare($sql,$store_id));
		$this->createStoreOfferTypes($offer_types,$store_id);
	}
	public function updateStoreTags($tags,$store_id) {
		/*
		 *	PARAMETERS
		 *		@tags				The tags array
		 *		@store_Id			The store ID
		 */
		$sql = 'DELETE FROM '.$this->wpdb->prefix.'topspin_stores_tag WHERE store_id = %d';
		$this->wpdb->query($this->wpdb->prepare($sql,$store_id));
		$this->createStoreTags($tags,$store_id);
	}
	public function getStoreId($post_id) {
		/*	Retrieves the Store ID by Post ID
		 *
		 *	PARAMETERS
		 *		@post_id
		 *
		 *	RETURN
		 *		The store ID
		 */
		$sql = <<<EOD
		SELECT
			{$this->wpdb->prefix}topspin_stores.store_id
		FROM {$this->wpdb->prefix}topspin_stores
		WHERE
			{$this->wpdb->prefix}topspin_stores.post_id = %d
EOD;
		$data = $this->wpdb->get_var($this->wpdb->prepare($sql,$post_id));
		return $data;
	}
	public function stores_get_items($store_id,$show_hidden=true,$artist_id=null) {
		/*	Retrieves the items list from the specified store
		 *
		 *	PARAMETERS
		 *		@store_id			The store ID
		 *		@show_hidden		(Optional) Boolean to show hidden manual items
		 *		@artist_id			(Optional) The artist's ID
		 *
		 *	RETURN
		 *		The items table as a multi-dimensional array
		 */
		if(!$artist_id) {
			$store = $this->getStore($store_id);
			$artist_id = $store['artist_id'];
		}
		$storeData = $this->getStore($store_id);
		$addedIDs = array();
		$sortedItems = array();
		// In Offer Types
		$in_offer_type = '';
		$total_offer_types = 0;
		if(count($storeData['offer_types'])) {
			foreach($storeData['offer_types'] as $key=>$offer_type) {
				if($offer_type['status']) {
					$total_offer_types++;
					if($key==0) { $in_offer_type .= '\''.$offer_type['type'].'\''; }
					else { $in_offer_type .= ', \''.$offer_type['type'].'\''; }
				}
			}
		$WHERE_IN_OFFER_TYPE  = ($total_offer_types) ? ' AND '.$this->wpdb->prefix.'topspin_items.offer_type IN ('.$in_offer_type.')' : '';
		}
		// In Tags
		$in_tags = '';
		$total_tags = 0;
		if(count($storeData['tags'])) {
			foreach($storeData['tags'] as $key=>$tag) {
				if($tag['status']) {
					$total_tags++;
					if($key==0) { $in_tags .= '\''.$tag['name'].'\''; }
					else { $in_tags .= ', \''.$tag['name'].'\''; }
				}
			}
		$WHERE_IN_TAGS  = ($total_tags) ? ' AND '.$this->wpdb->prefix.'topspin_items_tags.tag_name IN ('.$in_tags.')' : '';
		}
		// Order By
		$order_by = ($storeData['default_sorting']=='alphabetical') ? $this->wpdb->prefix.'topspin_items.name ASC' : $this->wpdb->prefix.'topspin_items.id ASC';
		// Switch Sorting By
		switch($storeData['default_sorting_by']) {
			case "offertype":
				## Fetch By Offer Type
				## If an offer type is checked, filter by offer type
				if($total_offer_types) {
					foreach($storeData['offer_types'] as $offer_type) {
						$sql = <<<EOD
						SELECT
							{$this->wpdb->prefix}topspin_items.id,
							{$this->wpdb->prefix}topspin_items.artist_id,
							{$this->wpdb->prefix}topspin_items.reporting_name,
							{$this->wpdb->prefix}topspin_items.embed_code,
							{$this->wpdb->prefix}topspin_items.width,
							{$this->wpdb->prefix}topspin_items.height,
							{$this->wpdb->prefix}topspin_items.url,
							{$this->wpdb->prefix}topspin_items.poster_image,
							{$this->wpdb->prefix}topspin_items.poster_image_source,
							{$this->wpdb->prefix}topspin_items.product_type,
							{$this->wpdb->prefix}topspin_items.offer_type,
							{$this->wpdb->prefix}topspin_offer_types.name AS offer_type_name,
							{$this->wpdb->prefix}topspin_items.description,
							{$this->wpdb->prefix}topspin_items.price,
							{$this->wpdb->prefix}topspin_items.name,
							{$this->wpdb->prefix}topspin_items.campaign,
							{$this->wpdb->prefix}topspin_items.offer_url,
							{$this->wpdb->prefix}topspin_items.mobile_url,
							{$this->wpdb->prefix}topspin_items.last_modified,
							{$this->wpdb->prefix}topspin_items.created_date,
							{$this->wpdb->prefix}topspin_items.sku_data,
							{$this->wpdb->prefix}topspin_items.in_stock_quantity,
							{$this->wpdb->prefix}topspin_items_tags.tag_name,
							{$this->wpdb->prefix}topspin_currency.currency,
							{$this->wpdb->prefix}topspin_currency.symbol
						FROM
							{$this->wpdb->prefix}topspin_items
						LEFT JOIN
							{$this->wpdb->prefix}topspin_items_tags ON {$this->wpdb->prefix}topspin_items.id = {$this->wpdb->prefix}topspin_items_tags.item_id
						LEFT JOIN
							{$this->wpdb->prefix}topspin_currency ON {$this->wpdb->prefix}topspin_items.currency = {$this->wpdb->prefix}topspin_currency.currency
						LEFT JOIN
							{$this->wpdb->prefix}topspin_offer_types ON {$this->wpdb->prefix}topspin_items.offer_type = {$this->wpdb->prefix}topspin_offer_types.type
						WHERE
							{$this->wpdb->prefix}topspin_items.artist_id = {$artist_id}
							AND {$this->wpdb->prefix}topspin_items.offer_type = '{$offer_type['type']}'
							{$WHERE_IN_TAGS}
						ORDER BY
							{$order_by}
EOD;
						$result = $this->wpdb->get_results($sql,ARRAY_A);
						foreach($result as $row) {
							## If not yet in the sorted items list
							if(!in_array($row['id'],$addedIDs)) {
								array_push($sortedItems,$row);
								array_push($addedIDs,$row['id']);
							}
						}
					}
				}
				else {
					$sql = <<<EOD
					SELECT
						{$this->wpdb->prefix}topspin_items.id,
						{$this->wpdb->prefix}topspin_items.artist_id,
						{$this->wpdb->prefix}topspin_items.reporting_name,
						{$this->wpdb->prefix}topspin_items.embed_code,
						{$this->wpdb->prefix}topspin_items.width,
						{$this->wpdb->prefix}topspin_items.height,
						{$this->wpdb->prefix}topspin_items.url,
						{$this->wpdb->prefix}topspin_items.poster_image,
						{$this->wpdb->prefix}topspin_items.poster_image_source,
						{$this->wpdb->prefix}topspin_items.product_type,
						{$this->wpdb->prefix}topspin_items.offer_type,
						{$this->wpdb->prefix}topspin_offer_types.name AS offer_type_name,
						{$this->wpdb->prefix}topspin_items.description,
						{$this->wpdb->prefix}topspin_items.price,
						{$this->wpdb->prefix}topspin_items.name,
						{$this->wpdb->prefix}topspin_items.campaign,
						{$this->wpdb->prefix}topspin_items.offer_url,
						{$this->wpdb->prefix}topspin_items.mobile_url,
						{$this->wpdb->prefix}topspin_items.last_modified,
						{$this->wpdb->prefix}topspin_items.created_date,
						{$this->wpdb->prefix}topspin_items.sku_data,
						{$this->wpdb->prefix}topspin_items.in_stock_quantity,
						{$this->wpdb->prefix}topspin_items_tags.tag_name,
						{$this->wpdb->prefix}topspin_currency.currency,
						{$this->wpdb->prefix}topspin_currency.symbol
					FROM
						{$this->wpdb->prefix}topspin_items
					LEFT JOIN
						{$this->wpdb->prefix}topspin_items_tags ON {$this->wpdb->prefix}topspin_items.id = {$this->wpdb->prefix}topspin_items_tags.item_id
					LEFT JOIN
						{$this->wpdb->prefix}topspin_currency ON {$this->wpdb->prefix}topspin_items.currency = {$this->wpdb->prefix}topspin_currency.currency
					LEFT JOIN
						{$this->wpdb->prefix}topspin_offer_types ON {$this->wpdb->prefix}topspin_items.offer_type = {$this->wpdb->prefix}topspin_offer_types.type
					WHERE
						{$this->wpdb->prefix}topspin_items.artist_id = {$artist_id}
						{$WHERE_IN_TAGS}
					ORDER BY
						{$order_by}
EOD;
					$result = $this->wpdb->get_results($sql,ARRAY_A);
					foreach($result as $row) {
						## If not yet in the sorted items list
						if(!in_array($row['id'],$addedIDs)) {
							array_push($sortedItems,$row);
							array_push($addedIDs,$row['id']);
						}
					}
				}
				break;
			case "tag":
				## Fetch By Tags
				## If a Tag is checked, filter by tags
				if($total_tags) {
					foreach($storeData['tags'] as $key=>$tag) {
						if($tag['status']) {
							$sql = <<<EOD
							SELECT
								{$this->wpdb->prefix}topspin_items.id,
								{$this->wpdb->prefix}topspin_items.artist_id,
								{$this->wpdb->prefix}topspin_items.reporting_name,
								{$this->wpdb->prefix}topspin_items.embed_code,
								{$this->wpdb->prefix}topspin_items.width,
								{$this->wpdb->prefix}topspin_items.height,
								{$this->wpdb->prefix}topspin_items.url,
								{$this->wpdb->prefix}topspin_items.poster_image,
								{$this->wpdb->prefix}topspin_items.poster_image_source,
								{$this->wpdb->prefix}topspin_items.product_type,
								{$this->wpdb->prefix}topspin_items.offer_type,
								{$this->wpdb->prefix}topspin_offer_types.name AS offer_type_name,
								{$this->wpdb->prefix}topspin_items.description,
								{$this->wpdb->prefix}topspin_items.price,
								{$this->wpdb->prefix}topspin_items.name,
								{$this->wpdb->prefix}topspin_items.campaign,
								{$this->wpdb->prefix}topspin_items.offer_url,
								{$this->wpdb->prefix}topspin_items.mobile_url,
								{$this->wpdb->prefix}topspin_items.last_modified,
								{$this->wpdb->prefix}topspin_items.created_date,
								{$this->wpdb->prefix}topspin_items.sku_data,
								{$this->wpdb->prefix}topspin_items.in_stock_quantity,
								{$this->wpdb->prefix}topspin_items_tags.tag_name,
								{$this->wpdb->prefix}topspin_currency.currency,
								{$this->wpdb->prefix}topspin_currency.symbol
							FROM
								{$this->wpdb->prefix}topspin_items
							LEFT JOIN
								{$this->wpdb->prefix}topspin_items_tags ON {$this->wpdb->prefix}topspin_items.id = {$this->wpdb->prefix}topspin_items_tags.item_id
							LEFT JOIN
								{$this->wpdb->prefix}topspin_currency ON {$this->wpdb->prefix}topspin_items.currency = {$this->wpdb->prefix}topspin_currency.currency
							LEFT JOIN
								{$this->wpdb->prefix}topspin_offer_types ON {$this->wpdb->prefix}topspin_items.offer_type = {$this->wpdb->prefix}topspin_offer_types.type
							WHERE
								{$this->wpdb->prefix}topspin_items.artist_id = {$artist_id}
								AND {$this->wpdb->prefix}topspin_items_tags.tag_name = '{$tag['name']}'
								{$WHERE_IN_OFFER_TYPE}
							ORDER BY
								{$order_by}
EOD;
							$result = $this->wpdb->get_results($sql,ARRAY_A);
							foreach($result as $row) {
								## If not yet in the sorted items list
								if(!in_array($row['id'],$addedIDs)) {
									array_push($sortedItems,$row);
									array_push($addedIDs,$row['id']);
								}
							}
						}
					}
				}
				## Else, do not filter tags and show all
				else {
					$sql = <<<EOD
					SELECT
						{$this->wpdb->prefix}topspin_items.id,
						{$this->wpdb->prefix}topspin_items.artist_id,
						{$this->wpdb->prefix}topspin_items.reporting_name,
						{$this->wpdb->prefix}topspin_items.embed_code,
						{$this->wpdb->prefix}topspin_items.width,
						{$this->wpdb->prefix}topspin_items.height,
						{$this->wpdb->prefix}topspin_items.url,
						{$this->wpdb->prefix}topspin_items.poster_image,
						{$this->wpdb->prefix}topspin_items.poster_image_source,
						{$this->wpdb->prefix}topspin_items.product_type,
						{$this->wpdb->prefix}topspin_items.offer_type,
						{$this->wpdb->prefix}topspin_offer_types.name AS offer_type_name,
						{$this->wpdb->prefix}topspin_items.description,
						{$this->wpdb->prefix}topspin_items.price,
						{$this->wpdb->prefix}topspin_items.name,
						{$this->wpdb->prefix}topspin_items.campaign,
						{$this->wpdb->prefix}topspin_items.offer_url,
						{$this->wpdb->prefix}topspin_items.mobile_url,						
						{$this->wpdb->prefix}topspin_items.last_modified,
						{$this->wpdb->prefix}topspin_items.created_date,
						{$this->wpdb->prefix}topspin_items.sku_data,
						{$this->wpdb->prefix}topspin_items.in_stock_quantity,
						{$this->wpdb->prefix}topspin_items_tags.tag_name,
						{$this->wpdb->prefix}topspin_currency.currency,
						{$this->wpdb->prefix}topspin_currency.symbol
					FROM
						{$this->wpdb->prefix}topspin_items
					LEFT JOIN
						{$this->wpdb->prefix}topspin_items_tags ON {$this->wpdb->prefix}topspin_items.id = {$this->wpdb->prefix}topspin_items_tags.item_id
					LEFT JOIN
						{$this->wpdb->prefix}topspin_currency ON {$this->wpdb->prefix}topspin_items.currency = {$this->wpdb->prefix}topspin_currency.currency
					LEFT JOIN
						{$this->wpdb->prefix}topspin_offer_types ON {$this->wpdb->prefix}topspin_items.offer_type = {$this->wpdb->prefix}topspin_offer_types.type
					WHERE
						{$this->wpdb->prefix}topspin_items.artist_id = {$artist_id}
						{$WHERE_IN_OFFER_TYPE}
					ORDER BY
						{$order_by}
EOD;
					$result = $this->wpdb->get_results($sql,ARRAY_A);
					foreach($result as $row) {
						## If not yet in the sorted items list
						if(!in_array($row['id'],$addedIDs)) {
							array_push($sortedItems,$row);
							array_push($addedIDs,$row['id']);
						}
					}
				}
				break;
			case "manual":
				$sql = <<<EOD
				SELECT
					{$this->wpdb->prefix}topspin_items.id,
					{$this->wpdb->prefix}topspin_items.artist_id,
					{$this->wpdb->prefix}topspin_items.reporting_name,
					{$this->wpdb->prefix}topspin_items.embed_code,
					{$this->wpdb->prefix}topspin_items.width,
					{$this->wpdb->prefix}topspin_items.height,
					{$this->wpdb->prefix}topspin_items.url,
					{$this->wpdb->prefix}topspin_items.poster_image,
					{$this->wpdb->prefix}topspin_items.poster_image_source,
					{$this->wpdb->prefix}topspin_items.product_type,
					{$this->wpdb->prefix}topspin_items.offer_type,
					{$this->wpdb->prefix}topspin_offer_types.name AS offer_type_name,
					{$this->wpdb->prefix}topspin_items.description,
					{$this->wpdb->prefix}topspin_items.price,
					{$this->wpdb->prefix}topspin_items.name,
					{$this->wpdb->prefix}topspin_items.campaign,
					{$this->wpdb->prefix}topspin_items.offer_url,
					{$this->wpdb->prefix}topspin_items.mobile_url,					
					{$this->wpdb->prefix}topspin_items.last_modified,
					{$this->wpdb->prefix}topspin_items.created_date,
					{$this->wpdb->prefix}topspin_items.sku_data,
					{$this->wpdb->prefix}topspin_items.in_stock_quantity,
					{$this->wpdb->prefix}topspin_items_tags.tag_name,
					{$this->wpdb->prefix}topspin_currency.currency,
					{$this->wpdb->prefix}topspin_currency.symbol
				FROM
					{$this->wpdb->prefix}topspin_items
				LEFT JOIN
					{$this->wpdb->prefix}topspin_items_tags ON {$this->wpdb->prefix}topspin_items.id = {$this->wpdb->prefix}topspin_items_tags.item_id
				LEFT JOIN
					{$this->wpdb->prefix}topspin_currency ON {$this->wpdb->prefix}topspin_items.currency = {$this->wpdb->prefix}topspin_currency.currency
				LEFT JOIN
					{$this->wpdb->prefix}topspin_offer_types ON {$this->wpdb->prefix}topspin_items.offer_type = {$this->wpdb->prefix}topspin_offer_types.type
				WHERE
					{$this->wpdb->prefix}topspin_items.artist_id = {$artist_id}
					{$WHERE_IN_TAGS}
					{$WHERE_IN_OFFER_TYPE}
				GROUP BY {$this->wpdb->prefix}topspin_items.id
EOD;
				$result = $this->wpdb->get_results($sql,ARRAY_A);
				## If items order exists (editing)
				if($storeData['items_order']) {
					## Global Items ID array
					$itemsIDs = array();
					foreach($result as $row) { array_push($itemsIDs,$row['id']); }
					## Adds the ordered items first
					$items_order = explode(',',$storeData['items_order']);
					foreach($items_order as $item) {
						$item = explode(':',$item);
						## Skip if don't show hidden, and item is not public
						if(!$show_hidden && !$item[1]) { continue; }
						## Only add items not in the added items list and in the global list
						if(!in_array($item[0],$addedIDs) && in_array($item[0],$itemsIDs)) {
							$the_item = $this->getItem($item[0]);
							$the_item['is_public'] = $item[1];
							array_push($sortedItems,$the_item); //Add to sortedItems array
							array_push($addedIDs,$the_item['id']); //Add to added items
						}
					}
					## Add other items only if show hidden is true
					if($show_hidden) {
						## Then add the new/unsorted items to the end of the items list
						foreach($result as $item) {
							## Only add items not in the added items list
							if(!in_array($item['id'],$addedIDs)) {
								$the_item = $item;
								$the_item['is_public'] = 0;
								array_push($sortedItems,$item); //Add to sortedItems array
								array_push($addedIDs,$item['id']); //Add to added items
							}
						}
					}
				}
				## Else doesn't exist (new store)
				else { $sortedItems = $result; }
		}
		// Retrieve the default images of the final items array
		foreach($sortedItems as $key=>$item) {
			$sortedItems[$key]['campaign'] = unserialize($sortedItems[$key]['campaign']);
			##	Add Images
			$sortedItems[$key]['images'] = $this->getItemImages($item['id']);
			##	Get Default Image
			$sortedItems[$key]['default_image'] = (strlen($item['poster_image_source'])) ? $this->getItemDefaultImage($item['id'],$item['poster_image_source']) : $item['poster_image'];
			$sortedItems[$key]['default_image_large'] = (strlen($item['poster_image_source'])) ? $this->getItemDefaultImage($item['id'],$item['poster_image_source'],'large') : $item['poster_image'];
		}
		return $sortedItems;
	}
	public function stores_get_items_page($items,$per_page,$page=1) {
		/*	Takes the items list and returns only the specific page
		 *
		 *	PARAMETERS
		 *		@items				The items list
		 *		@per_page			A number specifying how many items per page to display
		 *		@page				The page number to display
		 */
		$offset = ($page>1) ? (($page-1)*$per_page) : 0;
		return array_slice($items,$offset,$per_page);
	}
	public function getStoreFeaturedItems($store_id) { return $this->getStoreFeaturedItem($store_id); }	//alias
	public function getStoreFeaturedItem($store_id) {	//to be deprecated and replaced with getStoreFeaturedItems()
		##	Retrieves the featured item of the specified store
		##
		##	PARAMETERS
		##		@store_id			The store ID
		##
		##	RETURN
		##		The item's array
		$sql = <<<EOD
		SELECT
			{$this->wpdb->prefix}topspin_items.id,
			{$this->wpdb->prefix}topspin_items.artist_id,
			{$this->wpdb->prefix}topspin_items.reporting_name,
			{$this->wpdb->prefix}topspin_items.embed_code,
			{$this->wpdb->prefix}topspin_items.width,
			{$this->wpdb->prefix}topspin_items.height,
			{$this->wpdb->prefix}topspin_items.url,
			{$this->wpdb->prefix}topspin_items.poster_image,
			{$this->wpdb->prefix}topspin_items.poster_image_source,
			{$this->wpdb->prefix}topspin_items.product_type,
			{$this->wpdb->prefix}topspin_items.offer_type,
			{$this->wpdb->prefix}topspin_offer_types.name AS offer_type_name,
			{$this->wpdb->prefix}topspin_items.description,
			{$this->wpdb->prefix}topspin_items.price,
			{$this->wpdb->prefix}topspin_items.name,
			{$this->wpdb->prefix}topspin_items.campaign,
			{$this->wpdb->prefix}topspin_items.offer_url,
			{$this->wpdb->prefix}topspin_items.mobile_url,			
			{$this->wpdb->prefix}topspin_items.last_modified,
			{$this->wpdb->prefix}topspin_items.created_date,
			{$this->wpdb->prefix}topspin_items.sku_data,
			{$this->wpdb->prefix}topspin_items.in_stock_quantity,
			{$this->wpdb->prefix}topspin_items_tags.tag_name,
			{$this->wpdb->prefix}topspin_currency.currency,
			{$this->wpdb->prefix}topspin_currency.symbol
		FROM {$this->wpdb->prefix}topspin_stores_featured_items
		LEFT JOIN
			{$this->wpdb->prefix}topspin_items ON {$this->wpdb->prefix}topspin_stores_featured_items.item_id = {$this->wpdb->prefix}topspin_items.id
		LEFT JOIN
			{$this->wpdb->prefix}topspin_items_tags ON {$this->wpdb->prefix}topspin_items.id = {$this->wpdb->prefix}topspin_items_tags.item_id
		LEFT JOIN
			{$this->wpdb->prefix}topspin_currency ON {$this->wpdb->prefix}topspin_items.currency = {$this->wpdb->prefix}topspin_currency.currency
		LEFT JOIN
			{$this->wpdb->prefix}topspin_offer_types ON {$this->wpdb->prefix}topspin_items.offer_type = {$this->wpdb->prefix}topspin_offer_types.type
		WHERE
			{$this->wpdb->prefix}topspin_stores_featured_items.store_id = %d AND
			{$this->wpdb->prefix}topspin_stores_featured_items.item_id > 0
		GROUP BY
			{$this->wpdb->prefix}topspin_stores_featured_items.order_num
		ORDER BY
			{$this->wpdb->prefix}topspin_stores_featured_items.order_num ASC
EOD;
		$featuredItems = $this->wpdb->get_results($this->wpdb->prepare($sql,$store_id),ARRAY_A);
		if(count($featuredItems)) {
			foreach($featuredItems as $key=>$featuredItem) {
				##	Add Images
				$featuredItems[$key]['images'] = $this->getItemImages($featuredItems[$key]['id']);
				##	Get Default Image
				$featuredItems[$key]['default_image'] = (strlen($featuredItems[$key]['poster_image_source'])) ? $this->getItemDefaultImage($featuredItems[$key]['id'],$featuredItems[$key]['poster_image_source']) : $featuredItems[$key]['poster_image'];
				$featuredItems[$key]['default_image_large'] = (strlen($featuredItems[$key]['poster_image_source'])) ? $this->getItemDefaultImage($featuredItems[$key]['id'],$featuredItems[$key]['poster_image_source'],'large') : $featuredItems[$key]['poster_image'];
			}
			return $featuredItems;
		}
	}
	public function items_get_filtered_list($offer_types,$tags,$artist_id=null,$order='chronological') {
		/*	Retrieves the list of items with the set filters
		 *
		 *	PARAMETERS
		 *		@offer_types		An array containing the list of offer types
		 *		@tags				An array containing the list of tags
		 *		@artist_id			(Optional) The artist's ID
		 *		@order				(Optional) alphabetical, chronological (default)
		 */
		$addedIDs = array();
		$addedItems = array();
		## In Offer Types
		$in_offer_type = '';
		$total_offer_types = 0;
		foreach($offer_types as $key=>$offer_type) {
			if(strlen($offer_type)) {
				$total_offer_types++;
				if($key==0) { $in_offer_type .= '\''.$offer_type.'\''; }
				else { $in_offer_type .= ', \''.$offer_type.'\''; }
			}
		}
		$WHERE_ARTIST_ID = ($artist_id) ? sprintf(' AND %stopspin_items.artist_id = %d',$this->wpdb->prefix,$artist_id) : '';
		$WHERE_IN_OFFER_TYPE  = ($total_offer_types) ? ' AND '.$this->wpdb->prefix.'topspin_items.offer_type IN ('.$in_offer_type.')' : '';
		## In Tags
		$in_tags = '';
		$total_tags = 0;
		foreach($tags as $key=>$tag) {
			if(strlen($tag)) {
				$total_tags++;
				if($key==0) { $in_tags .= '\''.$tag.'\''; }
				else { $in_tags .= ', \''.$tag.'\''; }
			}
		}
		$WHERE_IN_TAGS  = ($total_tags) ? ' AND '.$this->wpdb->prefix.'topspin_items_tags.tag_name IN ('.$in_tags.')' : '';
		## Order By
		$order_by = ($order=='alphabetical') ? $this->wpdb->prefix.'topspin_items.name ASC' : $this->wpdb->prefix.'topspin_items.id ASC';
		$sql = <<<EOD
		SELECT
			{$this->wpdb->prefix}topspin_items.id,
			{$this->wpdb->prefix}topspin_items.artist_id,
			{$this->wpdb->prefix}topspin_items.reporting_name,
			{$this->wpdb->prefix}topspin_items.embed_code,
			{$this->wpdb->prefix}topspin_items.width,
			{$this->wpdb->prefix}topspin_items.height,
			{$this->wpdb->prefix}topspin_items.url,
			{$this->wpdb->prefix}topspin_items.poster_image,
			{$this->wpdb->prefix}topspin_items.poster_image_source,
			{$this->wpdb->prefix}topspin_items.product_type,
			{$this->wpdb->prefix}topspin_items.offer_type,
			{$this->wpdb->prefix}topspin_offer_types.name AS offer_type_name,
			{$this->wpdb->prefix}topspin_items.description,
			{$this->wpdb->prefix}topspin_items.price,
			{$this->wpdb->prefix}topspin_items.name,
			{$this->wpdb->prefix}topspin_items.campaign,
			{$this->wpdb->prefix}topspin_items.offer_url,
			{$this->wpdb->prefix}topspin_items.mobile_url,			
			{$this->wpdb->prefix}topspin_items.last_modified,
			{$this->wpdb->prefix}topspin_items.created_date,
			{$this->wpdb->prefix}topspin_items.sku_data,
			{$this->wpdb->prefix}topspin_items.in_stock_quantity,
			{$this->wpdb->prefix}topspin_items_tags.tag_name,
			{$this->wpdb->prefix}topspin_currency.currency,
			{$this->wpdb->prefix}topspin_currency.symbol
		FROM {$this->wpdb->prefix}topspin_items
		LEFT JOIN
			{$this->wpdb->prefix}topspin_items_tags ON {$this->wpdb->prefix}topspin_items.id = {$this->wpdb->prefix}topspin_items_tags.item_id
		LEFT JOIN
			{$this->wpdb->prefix}topspin_currency ON {$this->wpdb->prefix}topspin_items.currency = {$this->wpdb->prefix}topspin_currency.currency
		LEFT JOIN
			{$this->wpdb->prefix}topspin_offer_types ON {$this->wpdb->prefix}topspin_items.offer_type = {$this->wpdb->prefix}topspin_offer_types.type
		WHERE
			1 = 1
			{$WHERE_ARTIST_ID}
			{$WHERE_IN_TAGS}
			{$WHERE_IN_OFFER_TYPE}
		ORDER BY
			{$order_by}
EOD;
		$data = $this->wpdb->get_results($this->wpdb->prepare($sql),ARRAY_A);
		foreach($data as $key=>$row) {
			$row['campaign'] = unserialize($row['campaign']);
			##	Add Images
			$row['images'] = $this->getItemImages($row['id']);
			##	Get Default Image
			$row['default_image'] = (strlen($row['poster_image_source'])) ? $this->getItemDefaultImage($row['id'],$row['poster_image_source']) : $row['poster_image'];
			$row['default_image_large'] = (strlen($row['poster_image_source'])) ? $this->getItemDefaultImage($row['id'],$row['poster_image_source'],'large') : $row['poster_image'];
			if(!in_array($row['id'],$addedIDs)) {
				array_push($addedIDs,$row['id']);
				array_push($addedItems,$row);
			}
		}
		return $addedItems;
	}
	
	public function items_get_list($page=-1,$perPage=-1) {
		$sql = <<<EOD
		SELECT
			SQL_CALC_FOUND_ROWS {$this->wpdb->prefix}topspin_items.id,
			{$this->wpdb->prefix}topspin_items.artist_id,
			{$this->wpdb->prefix}topspin_artists.name AS `artist_name`,
			{$this->wpdb->prefix}topspin_items.reporting_name,
			{$this->wpdb->prefix}topspin_items.embed_code,
			{$this->wpdb->prefix}topspin_items.width,
			{$this->wpdb->prefix}topspin_items.height,
			{$this->wpdb->prefix}topspin_items.url,
			{$this->wpdb->prefix}topspin_items.poster_image,
			{$this->wpdb->prefix}topspin_items.poster_image_source,
			{$this->wpdb->prefix}topspin_items.product_type,
			{$this->wpdb->prefix}topspin_items.offer_type,
			{$this->wpdb->prefix}topspin_offer_types.name AS offer_type_name,
			{$this->wpdb->prefix}topspin_items.description,
			{$this->wpdb->prefix}topspin_items.price,
			{$this->wpdb->prefix}topspin_items.name,
			{$this->wpdb->prefix}topspin_items.campaign,
			{$this->wpdb->prefix}topspin_items.offer_url,
			{$this->wpdb->prefix}topspin_items.mobile_url,			
			{$this->wpdb->prefix}topspin_items.last_modified,
			{$this->wpdb->prefix}topspin_items.created_date,
			{$this->wpdb->prefix}topspin_items.sku_data,
			{$this->wpdb->prefix}topspin_items.in_stock_quantity,
			GROUP_CONCAT(DISTINCT {$this->wpdb->prefix}topspin_items_tags.tag_name SEPARATOR ',') AS `tags`,
			{$this->wpdb->prefix}topspin_currency.currency,
			{$this->wpdb->prefix}topspin_currency.symbol
		FROM {$this->wpdb->prefix}topspin_items
		LEFT JOIN
			{$this->wpdb->prefix}topspin_artists ON {$this->wpdb->prefix}topspin_items.artist_id = {$this->wpdb->prefix}topspin_artists.id
		LEFT JOIN
			{$this->wpdb->prefix}topspin_items_tags ON {$this->wpdb->prefix}topspin_items.id = {$this->wpdb->prefix}topspin_items_tags.item_id
		LEFT JOIN
			{$this->wpdb->prefix}topspin_currency ON {$this->wpdb->prefix}topspin_items.currency = {$this->wpdb->prefix}topspin_currency.currency
		LEFT JOIN
			{$this->wpdb->prefix}topspin_offer_types ON {$this->wpdb->prefix}topspin_items.offer_type = {$this->wpdb->prefix}topspin_offer_types.type
		GROUP BY
			{$this->wpdb->prefix}topspin_items.id
		ORDER BY
			{$this->wpdb->prefix}topspin_items.id ASC
EOD;
		$items = $this->wpdb->get_results($this->wpdb->prepare($sql,array($artist_id)));
		foreach($items as $item) {
			//Get default images
			$item->default_image = (strlen($item->poster_image_source)) ? $this->getItemDefaultImage($item->id,$item->poster_image_source) : $item->poster_image;
			$item->default_image_large = (strlen($item->poster_image_source)) ? $this->getItemDefaultImage($item->id,$item->poster_image_source,'large') : $item->poster_image;
		}
		return $items;
	}
	public function items_get_campaign_ids() {
		/*
			RETURN
				An array of all skus in the database or FALSE on failure
		 */
		$sql = <<<EOD
		SELECT
			{$this->wpdb->prefix}topspin_items.campaign_id
		FROM {$this->wpdb->prefix}topspin_items
		WHERE campaign_id > 0
EOD;
		$campaignIds = $this->wpdb->get_col($sql);
		return (is_array($campaignIds)) ? $campaignIds : false;
	}
	public function items_get($item_id) {
		/*	Retrieves the specified item
		 
		 	PARAMETERS
		 		@item_id			The item ID
		 
		 	RETURN
		 		The item's array
		 */
		$sql = <<<EOD
		SELECT
			{$this->wpdb->prefix}topspin_items.id,
			{$this->wpdb->prefix}topspin_items.artist_id,
			{$this->wpdb->prefix}topspin_items.reporting_name,
			{$this->wpdb->prefix}topspin_items.embed_code,
			{$this->wpdb->prefix}topspin_items.width,
			{$this->wpdb->prefix}topspin_items.height,
			{$this->wpdb->prefix}topspin_items.url,
			{$this->wpdb->prefix}topspin_items.poster_image,
			{$this->wpdb->prefix}topspin_items.poster_image_source,
			{$this->wpdb->prefix}topspin_items.product_type,
			{$this->wpdb->prefix}topspin_items.offer_type,
			{$this->wpdb->prefix}topspin_offer_types.name AS offer_type_name,
			{$this->wpdb->prefix}topspin_items.description,
			{$this->wpdb->prefix}topspin_items.price,
			{$this->wpdb->prefix}topspin_items.name,
			{$this->wpdb->prefix}topspin_items.campaign,
			{$this->wpdb->prefix}topspin_items.offer_url,
			{$this->wpdb->prefix}topspin_items.mobile_url,
			{$this->wpdb->prefix}topspin_items.last_modified,
			{$this->wpdb->prefix}topspin_items.created_date,
			{$this->wpdb->prefix}topspin_items.sku_data,
			{$this->wpdb->prefix}topspin_items.in_stock_quantity,
			{$this->wpdb->prefix}topspin_items_tags.tag_name,
			{$this->wpdb->prefix}topspin_currency.currency,
			{$this->wpdb->prefix}topspin_currency.symbol
		FROM {$this->wpdb->prefix}topspin_items
		LEFT JOIN
			{$this->wpdb->prefix}topspin_items_tags ON {$this->wpdb->prefix}topspin_items.id = {$this->wpdb->prefix}topspin_items_tags.item_id
		LEFT JOIN
			{$this->wpdb->prefix}topspin_currency ON {$this->wpdb->prefix}topspin_items.currency = {$this->wpdb->prefix}topspin_currency.currency
		LEFT JOIN
			{$this->wpdb->prefix}topspin_offer_types ON {$this->wpdb->prefix}topspin_items.offer_type = {$this->wpdb->prefix}topspin_offer_types.type
		WHERE
			{$this->wpdb->prefix}topspin_items.id = '{$item_id}'
		GROUP BY {$this->wpdb->prefix}topspin_items.id
EOD;
		$item = $this->wpdb->get_row($sql,ARRAY_A);
		//Get default images
		$item['images'] = $this->getItemImages($item['id']);
		$item['default_image'] = (strlen($item['poster_image_source'])) ? $this->getItemDefaultImage($item['id'],$item['poster_image_source']) : $item['poster_image'];
		$item['default_image_large'] = (strlen($item['poster_image_source'])) ? $this->getItemDefaultImage($item['id'],$item['poster_image_source'],'large') : $item['poster_image'];
		return $item;
	}

	public function getItemImages($item_id) {
		##	Retrieves the item's images
		##
		##	RETURN
		##		An array containing all the item's images and their sizes
		##
		$sql = <<<EOD
		SELECT
			{$this->wpdb->prefix}topspin_items_images.source_url,
			{$this->wpdb->prefix}topspin_items_images.small_url,
			{$this->wpdb->prefix}topspin_items_images.medium_url,
			{$this->wpdb->prefix}topspin_items_images.large_url
		FROM {$this->wpdb->prefix}topspin_items_images
		WHERE
			{$this->wpdb->prefix}topspin_items_images.item_id = '%d'
EOD;
		return $this->wpdb->get_results($this->wpdb->prepare($sql,array($item_id)),ARRAY_A);
	}
	
	public function getItemDefaultImage($item_id,$poster_image_source,$image_size='large') {
		##	Retrieves the item's default image
		##
		##	PARAMETERS
		##		@item_id
		##		@poster_image_source
		##		@image_size
		##
		##	RETURN
		##		The image url string if it exists (and the poster_image_source if it doesn't)
		##
		$sql = <<<EOD
		SELECT
			{$this->wpdb->prefix}topspin_items_images.{$image_size}_url
		FROM {$this->wpdb->prefix}topspin_items_images
		WHERE
			{$this->wpdb->prefix}topspin_items_images.item_id = '%d'
			AND {$this->wpdb->prefix}topspin_items_images.source_url = '%s'
EOD;
		$image = $this->wpdb->get_var($this->wpdb->prepare($sql,array($item_id,$poster_image_source)));
		return ($image) ? $image : $poster_image_source;
	}
	
	public function getSortByTypes() {
		##	Retrieves the sort by types
		##
		##	RETURN
		##		An array containing all the sort by type keys and names
		##
		return 	array(
			'offertype' => 'Offer Types',
			'tag' => 'Tags',
			'manual' => 'Manual'
		);
	}
	
	public function getOfferTypes() {
		##	Retrieves the offer types used by the Store API
		##
		##	RETURN
		##		An array containing all the offer type keys and names
		##
		$sql = <<<EOD
		SELECT
			{$this->wpdb->prefix}topspin_offer_types.type,
			{$this->wpdb->prefix}topspin_offer_types.name,
			{$this->wpdb->prefix}topspin_offer_types.status
		FROM {$this->wpdb->prefix}topspin_offer_types
EOD;
		$data = $this->wpdb->get_results($this->wpdb->prepare($sql),ARRAY_A);
		## Store as Class Property
		$this->offer_types['data'] = $data; ## Raw Data
		foreach($data as $row) { $this->offer_types['key'][$row['type']] = $row['name']; } ## Key Data
		return $data;
	}
	
	public function getStoreOfferTypes($store_id) {
		##	Retrieves the list of types for a store
		##
		##	PARAMETER
		##		@store_id			The store ID
		##
		##	RETURN
		##		The ordered offer types list
		$sql = <<<EOD
		SELECT
			{$this->wpdb->prefix}topspin_offer_types.name,
			{$this->wpdb->prefix}topspin_stores_offer_type.type,
			{$this->wpdb->prefix}topspin_stores_offer_type.order_num,
			{$this->wpdb->prefix}topspin_stores_offer_type.status
		FROM {$this->wpdb->prefix}topspin_stores_offer_type
		LEFT JOIN
			{$this->wpdb->prefix}topspin_offer_types ON {$this->wpdb->prefix}topspin_offer_types.type = {$this->wpdb->prefix}topspin_stores_offer_type.type
		WHERE
			{$this->wpdb->prefix}topspin_stores_offer_type.store_id = '%d'
		ORDER BY
			{$this->wpdb->prefix}topspin_stores_offer_type.order_num ASC
EOD;
		$data = $this->wpdb->get_results($this->wpdb->prepare($sql,array($store_id)),ARRAY_A);
		return $data;
	}
	
	/* !----- TAGS ------ */
	public function getTagList($artist_id) { return $this->tags_get_list($artist_id); }

	public function tags_get_list($artist_id) {
		/*	Retrieves the entire list of tags from the database
		 *
		 *	PARAMETERS
		 *		@artist_id (int)			The artist ID
		 *
		 *	RETURN
		 *		The tags table as a multi-dimensional array
		 */
		$sql = <<<EOD
		SELECT
			{$this->wpdb->prefix}topspin_tags.id,
			{$this->wpdb->prefix}topspin_tags.artist_id,
			{$this->wpdb->prefix}topspin_tags.name
		FROM {$this->wpdb->prefix}topspin_tags
		WHERE
			{$this->wpdb->prefix}topspin_tags.artist_id = %d
EOD;
		$data = $this->wpdb->get_results($this->wpdb->prepare($sql,$artist_id),ARRAY_A);
		//Set Default Status
		foreach($data as $key=>$row) { $data[$key]['status'] = 0; }
		return $data;
	}
	/* !----- ARTISTS ----- */
	public function artists_get_list($opts=array()) {
		/*
		 *	Retrieves the list of artists from the database
		 *
		 *	PARAMETERS
		 *		@opts (array)				An array of options
		 *
		 *			@artist_ids (array)		An array of artist ID to retrieve
		 */
		//Parse WHERE
		$WHERE = '';
		if(isset($opts['artist_ids']) && is_array($opts['artist_ids']) && count($opts['artist_ids'])) {
			$WHERE .= sprintf(' AND `'.$this->wpdb->prefix.'topspin_artists`.`id` IN (%s)',implode(',',$opts['artist_ids']));
		}
		$sql = <<<EOD
		SELECT
			{$this->wpdb->prefix}topspin_artists.id,
			{$this->wpdb->prefix}topspin_artists.name,
			{$this->wpdb->prefix}topspin_artists.avatar_image,
			{$this->wpdb->prefix}topspin_artists.url,
			{$this->wpdb->prefix}topspin_artists.description,
			{$this->wpdb->prefix}topspin_artists.website
		FROM {$this->wpdb->prefix}topspin_artists
		WHERE 1 = 1
		{$WHERE}
		ORDER BY
			{$this->wpdb->prefix}topspin_artists.name ASC
EOD;
		return $this->wpdb->get_results($this->wpdb->prepare($sql),ARRAY_A);
	}
	
	/* !----- ORDERS ----- */
	public function orders_get_list() {
		/*	Retrieves the list of orders from the database
		 *
		 *	RETURN
		 *		The orders list ordered by the order's created date from newest to oldest
		 */
		$aIds = $this->settings_get('topspin_artist_id');
		if(is_string($aIds)) { $artistIds = array($aIds); }
		else { $artistIds = $aIds; }
		$artistIds = (count($artistIds)) ? implode(',',$artistIds) : '0';
		$sql = <<<EOD
		SELECT
			{$this->wpdb->prefix}topspin_orders.id,
		 	{$this->wpdb->prefix}topspin_orders.artist_id,
		 	{$this->wpdb->prefix}topspin_orders.created_at,
		 	{$this->wpdb->prefix}topspin_orders.subtotal,
			{$this->wpdb->prefix}topspin_currency.currency,
			{$this->wpdb->prefix}topspin_currency.symbol,
		 	{$this->wpdb->prefix}topspin_orders.tax,
		 	{$this->wpdb->prefix}topspin_orders.phone,
		 	{$this->wpdb->prefix}topspin_orders.shipping_method_calculator,
		 	{$this->wpdb->prefix}topspin_orders.reshipment,
		 	{$this->wpdb->prefix}topspin_orders.fan,
		 	{$this->wpdb->prefix}topspin_orders.details_url,
		 	{$this->wpdb->prefix}topspin_orders.exchange_rate,
		 	{$this->wpdb->prefix}topspin_orders.shipping_method_code,
		 	{$this->wpdb->prefix}topspin_orders.shipping_address_firstname,
		 	{$this->wpdb->prefix}topspin_orders.shipping_address_lastname,
		 	{$this->wpdb->prefix}topspin_orders.shipping_address_lastname,
		 	{$this->wpdb->prefix}topspin_orders.shipping_address_address1,
		 	{$this->wpdb->prefix}topspin_orders.shipping_address_address2,
		 	{$this->wpdb->prefix}topspin_orders.shipping_address_city,
		 	{$this->wpdb->prefix}topspin_orders.shipping_address_state,
		 	{$this->wpdb->prefix}topspin_orders.shipping_address_postal_code,
		 	{$this->wpdb->prefix}topspin_orders.shipping_address_country,
		 	{$this->wpdb->prefix}topspin_orders.shipping_address_phone
		FROM {$this->wpdb->prefix}topspin_orders
		LEFT JOIN
		 	{$this->wpdb->prefix}topspin_currency ON {$this->wpdb->prefix}topspin_orders.currency = {$this->wpdb->prefix}topspin_currency.currency
		WHERE
		 	{$this->wpdb->prefix}topspin_orders.artist_id IN ({$artistIds})
		GROUP BY
		 	{$this->wpdb->prefix}topspin_orders.id
		ORDER BY
		 	{$this->wpdb->prefix}topspin_orders.created_at DESC
EOD;
		$ordersList = $this->wpdb->get_results($this->wpdb->prepare($sql));
		if(count($ordersList)) {
			foreach($ordersList as $key=>$order) {
				$ordersList[$key]->items_list = $this->orders_get_items_list($order->id);
			}
		}
		return $ordersList;
	}
	
	public function orders_get_items_list($orderID) {
		/*	Retrieves the list of items in the given order from the database
		 *
		 *	RETURN
		 *		The items list in the given order
		 */
		$sql = <<<EOD
		SELECT
		 	{$this->wpdb->prefix}topspin_orders_items.id,
		 	{$this->wpdb->prefix}topspin_orders_items.order_id,
		 	{$this->wpdb->prefix}topspin_orders_items.campaign_id,
		 	{$this->wpdb->prefix}topspin_orders_items.line_item_id,
		 	{$this->wpdb->prefix}topspin_orders_items.spin_name,
		 	{$this->wpdb->prefix}topspin_orders_items.product_id,
		 	{$this->wpdb->prefix}topspin_orders_items.product_name,
		 	{$this->wpdb->prefix}topspin_orders_items.product_type,
		 	{$this->wpdb->prefix}topspin_orders_items.merchandise_type,
		 	{$this->wpdb->prefix}topspin_orders_items.quantity,
		 	{$this->wpdb->prefix}topspin_orders_items.sku_id,
		 	{$this->wpdb->prefix}topspin_orders_items.sku_upc,
		 	{$this->wpdb->prefix}topspin_orders_items.sku_attributes,
		 	{$this->wpdb->prefix}topspin_orders_items.factory_sku,
		 	{$this->wpdb->prefix}topspin_orders_items.shipped,
		 	{$this->wpdb->prefix}topspin_orders_items.status,
		 	{$this->wpdb->prefix}topspin_orders_items.weight,
		 	{$this->wpdb->prefix}topspin_orders_items.bundle_sku_id,
		 	{$this->wpdb->prefix}topspin_orders_items.shipping_date
		FROM {$this->wpdb->prefix}topspin_orders_items
		WHERE
		 	{$this->wpdb->prefix}topspin_orders_items.order_id = '%d'
EOD;
		$itemsList = $this->wpdb->get_results($this->wpdb->prepare($sql,array($orderID)));
		$unserialize = array('sku_attributes','weight');
		foreach($unserialize as $key) {
			foreach($itemsList as $itemKey=>$item) {
				$itemsList[$itemKey]->$key = unserialize($itemsList[$itemKey]->$key);
			}
		}
		return $itemsList;
	}
	/* !----- PRODUCTS ----- */
	public function product_get_most_popular_list($count=null) {
		/*	Retrieves the list of the most popular ordered items
		 *
		 *	PARAMETERS
		 *		@count (int)			How many items to return
		 *
		 *	RETURN
		 *		The items list ordered by the most ordered to least
		 */
		$aIds = $this->settings_get('topspin_artist_id');
		if(is_string($aIds)) { $artistIds = array($aIds); }
		else { $artistIds = $aIds; }
		$artistIds = (count($artistIds)) ? implode(',',$artistIds) : '0';
		$LIMIT = ($count) ? "LIMIT %d" : "";
		$sql = <<<EOD
		SELECT
			{$this->wpdb->prefix}topspin_items.id,
		 	{$this->wpdb->prefix}topspin_items.campaign_id,
		 	SUM({$this->wpdb->prefix}topspin_orders_items.quantity) AS `total_count`,
			{$this->wpdb->prefix}topspin_items.artist_id,
			{$this->wpdb->prefix}topspin_items.reporting_name,
			{$this->wpdb->prefix}topspin_items.embed_code,
			{$this->wpdb->prefix}topspin_items.width,
			{$this->wpdb->prefix}topspin_items.height,
			{$this->wpdb->prefix}topspin_items.url,
			{$this->wpdb->prefix}topspin_items.poster_image,
			{$this->wpdb->prefix}topspin_items.poster_image_source,
			{$this->wpdb->prefix}topspin_items.product_type,
			{$this->wpdb->prefix}topspin_items.offer_type,
			{$this->wpdb->prefix}topspin_offer_types.name AS offer_type_name,
			{$this->wpdb->prefix}topspin_items.description,
			{$this->wpdb->prefix}topspin_items.price,
			{$this->wpdb->prefix}topspin_items.name,
			{$this->wpdb->prefix}topspin_items.campaign,
			{$this->wpdb->prefix}topspin_items.offer_url,
			{$this->wpdb->prefix}topspin_items.mobile_url,
			{$this->wpdb->prefix}topspin_items.last_modified,
			{$this->wpdb->prefix}topspin_items.created_date,
			GROUP_CONCAT(DISTINCT {$this->wpdb->prefix}topspin_items_tags.tag_name SEPARATOR ',') AS `tags`,
			{$this->wpdb->prefix}topspin_currency.currency,
			{$this->wpdb->prefix}topspin_currency.symbol,
		 	{$this->wpdb->prefix}topspin_items.last_modified,
		 	{$this->wpdb->prefix}topspin_items.created_date
		FROM
		 	{$this->wpdb->prefix}topspin_orders_items
		LEFT JOIN
		 	{$this->wpdb->prefix}topspin_items ON {$this->wpdb->prefix}topspin_orders_items.campaign_id = {$this->wpdb->prefix}topspin_items.campaign_id
		LEFT JOIN
		 	{$this->wpdb->prefix}topspin_items_tags ON {$this->wpdb->prefix}topspin_items.id = {$this->wpdb->prefix}topspin_items_tags.item_id
		LEFT JOIN
		 	{$this->wpdb->prefix}topspin_currency ON {$this->wpdb->prefix}topspin_items.currency = {$this->wpdb->prefix}topspin_currency.currency
		LEFT JOIN
		 	{$this->wpdb->prefix}topspin_offer_types ON {$this->wpdb->prefix}topspin_items.offer_type = {$this->wpdb->prefix}topspin_offer_types.type
		WHERE
		 	{$this->wpdb->prefix}topspin_items.offer_type = 'buy_button' AND
		 	{$this->wpdb->prefix}topspin_items.artist_id IN ({$artistIds})
		GROUP BY
		 	{$this->wpdb->prefix}topspin_orders_items.campaign_id
		ORDER BY
		 	`total_count` DESC
		{$LIMIT}
EOD;
		$items = $this->wpdb->get_results($this->wpdb->prepare($sql,$count));
		foreach($items as $item) {		
			//Get default images
			$item->default_image = (strlen($item->poster_image_source)) ? $this->getItemDefaultImage($item->id,$item->poster_image_source) : $item->poster_image;
			$item->default_image_large = (strlen($item->poster_image_source)) ? $this->getItemDefaultImage($item->id,$item->poster_image_source,'large') : $item->poster_image;
		}
		return $items;
	}

}

?>