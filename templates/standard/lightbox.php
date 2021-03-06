<?php if($tsQuery->have_offers()) : ?>
	<?php while($tsQuery->have_offers()) : $tsQuery->the_offer(); ?>
		<div class="topspin-item topspin-item-featured topspin-item-id-<?php ts_the_id(); ?>">
			<div class="topspin-item-inner">
				<div class="topspin-item-thumb"><a href="<?php ts_the_permalink(); ?>"><?php ts_the_thumbnail('topspin-default-single-thumb'); ?></a></div>
				<div class="topspin-item-body">
					<div class="topspin-item-desc"><?php ts_the_content(); ?></div>
					<div class="topspin-item-footer">
						<?php if(ts_is_new()) : ?><div class="topspin-item-new">NEW!</div><?php endif; ?>
						<?php if(ts_is_on_sale()) : ?><div class="topspin-item-onsale">ON SALE!</div><?php endif; ?>
						<div class="topspin-item-price">Price: <?php ts_the_price(); ?></div>
						<div class="topspin-item-purchase">
							<a class="topspin-item-purchase-anchor" href="<?php ts_the_purchaselink(); ?>">PURCHASE</a>
						</div>
					</div>
				</div>
			</div>
		</div>
	<?php endwhile; ?>
<?php endif; ?>