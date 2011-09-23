CREATE TABLE IF NOT EXISTS `<?php echo $wpdb->prefix;?>topspin_items` (
  `id` int(11) NOT NULL,
  `campaign_id` int(11) NOT NULL,
  `artist_id` int(11) NOT NULL,
  `reporting_name` varchar(255) NOT NULL,
  `embed_code` text NOT NULL,
  `width` int(11) NOT NULL,
  `height` int(11) NOT NULL,
  `url` varchar(255) NOT NULL,
  `poster_image` text NOT NULL,
  `poster_image_source` text NOT NULL,
  `product_type` varchar(255) NOT NULL,
  `offer_type` varchar(255) NOT NULL,
  `description` longtext NOT NULL,
  `currency` varchar(255) NOT NULL,
  `price` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `campaign` longtext NOT NULL,
  `offer_url` text NOT NULL,
  `mobile_url` text NOT NULL,
  `last_modified` timestamp NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
  PRIMARY KEY  (`id`)
  KEY `campaign_id` (`campaign_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;