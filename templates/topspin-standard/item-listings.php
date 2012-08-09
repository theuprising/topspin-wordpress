<?php
/*
 *	Topspin Items Listing
 *
 *	Usage: [topspin_buy_buttons]
 *
 *	Available template variables
 *		storedata	(array)
 *		storeitems	(array)
 *
 *
 *	WARNING: DO NOT EDIT THIS FILE!
 *
 *	To edit the PHP, copy this file to your
 *	active theme's directory and edit from that
 *	new file.
 *
 *	Example: /wp-content/themes/<current-theme>/topspin-standard/item-listings.php
 *
 */
?>

<?php if(count($storeitems)) : ?>
<div id="topspin-store-<?php echo $storedata['id'];?>" class="topspin-store">
	<ul class="topspin-item-listings">
	<?php foreach($storeitems as $key=>$item) : ?>
    	<?php $item_classes = array(); ?>
		<?php if($key && $key%$storedata['grid_columns']==0) : ?>
        <li class="topspin-clear"></li>
        <?php endif; ?>
        <?php if($key==0) { $item_classes[] = 'first'; } ?>
        <?php if($key%$storedata['grid_columns']==0) { $item_classes[] = 'row-start'; } ?>
		<?php if(($key+1)%$storedata['grid_columns']==0) { $item_classes[] = 'row-end'; } ?>
		<li class="topspin-item <?php echo $item['offer_type'];?> <?php echo implode(' ',$item_classes);?>" style="width:<?php echo $storedata['grid_item_width'];?>%">
        	<div class="topspin-item-canvas">
        	<?php ## BEGIN SWITCH OFFER TYPE
            switch($item['offer_type']) {
				case 'buy_button': ?>
					<h2 class="topspin-item-title"><a class="topspin-view-item" href="#!/<?php echo $item['id']; ?>"><?php echo $item['name'];?></a></h2>
                    <div class="topspin-item-image"><a class="topspin-view-item" href="#!/<?php echo $item['id']; ?>"><img src="<?php echo $item['default_image'];?>" /></a></div>
					<div class="topspin-item-price">Price: <?php echo $item['symbol'];?><?php echo $item['price'];?></div>
					<div class="topspin-item-buy"><a class="topspin-buy" href="<?php echo $item['offer_url'];?>">Buy</a></div>
					<?php break;
				case 'email_for_media':
				case 'bundle_widget':
				case 'single_track_player_widget': ?>
                	<div class="topspin-item-embed"><?php echo $item['embed_code'];?></div>
					<?php break;
			} ## END SWITCH OFFER TYPE
			?>
        	</div>
	    </li>
	<?php endforeach; ?>
	</ul>

	<?php ## BEGIN PAGINATION
	if(!$storedata['show_all_items'] && $storedata['curr_page']<=$storedata['total_pages'] && $storedata['total_pages']>1) { ?>
    	<div class="topspin-pagination">
    	Page <?php echo $storedata['curr_page'];?> of <?php echo $storedata['total_pages'];?>
		<?php if($storedata['prev_page']) : ?><a class="topspin-prev" href="<?php echo $storedata['prev_page'];?>">Previous</a><?php endif; ?>
		<?php if($storedata['next_page']) : ?><a class="topspin-next" href="<?php echo $storedata['next_page'];?>">Next</a><?php endif; ?>
        </div>
	<?php } ## END PAGINATION ?>

</div>
<?php endif; ?>
