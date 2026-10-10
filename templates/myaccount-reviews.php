<?php
/**
 * My Account → Reviews (RR-15).
 *
 * Override: copy to yourtheme/ndv-reviews/myaccount-reviews.php
 *
 * @var array<int,array{product:\WC_Product,ordered:int,url:string}> $waiting Products to review.
 * @var array<int,array<string,mixed>>                             $mine    The customer's reviews:
 *      view (view-model), comment (WP_Comment), published (bool), edit_url ('' when closed),
 *      closed_on (timestamp the window closed, 0 when not shown).
 * @var bool                                                       $more    The list shows the newest 50 only.
 *
 * @package NdvReviews
 */

use NdvReviews\Display\Html;

defined( 'ABSPATH' ) || exit;

$ndvr_date = (string) get_option( 'date_format' );
?>
<div class="ndvr-account-reviews">
	<section class="ndvr-account-section" aria-labelledby="ndvr-account-waiting">
		<h2 id="ndvr-account-waiting"><?php esc_html_e( 'Waiting for your review', 'rosette-reviews' ); ?></h2>
		<?php if ( empty( $waiting ) ) : ?>
			<p class="ndvr-account-empty"><?php esc_html_e( 'Nothing waiting. Thank you for your reviews.', 'rosette-reviews' ); ?></p>
		<?php else : ?>
			<ul class="ndvr-account-list">
				<?php foreach ( $waiting as $ndvr_item ) : ?>
					<?php $ndvr_product = $ndvr_item['product']; ?>
					<li class="ndvr-account-item">
						<span class="ndvr-account-thumb" aria-hidden="true"><?php echo wp_kses_post( $ndvr_product->get_image( 'woocommerce_gallery_thumbnail' ) ); ?></span>
						<span class="ndvr-account-main">
							<a class="ndvr-account-product" href="<?php echo esc_url( get_permalink( $ndvr_product->get_id() ) ); ?>"><?php echo esc_html( $ndvr_product->get_name() ); ?></a>
							<?php if ( $ndvr_item['ordered'] ) : ?>
								<span class="ndvr-account-meta">
									<?php
									/* translators: %s: order date. */
									echo esc_html( sprintf( __( 'Ordered %s', 'rosette-reviews' ), wp_date( $ndvr_date, (int) $ndvr_item['ordered'] ) ) );
									?>
								</span>
							<?php endif; ?>
						</span>
						<a class="button ndvr-account-action" href="<?php echo esc_url( $ndvr_item['url'] ); ?>">
							<?php esc_html_e( 'Write a review', 'rosette-reviews' ); ?><span class="screen-reader-text"> <?php echo esc_html( $ndvr_product->get_name() ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>

	<section class="ndvr-account-section" aria-labelledby="ndvr-account-mine">
		<h2 id="ndvr-account-mine"><?php esc_html_e( 'Your reviews', 'rosette-reviews' ); ?></h2>
		<?php if ( empty( $mine ) ) : ?>
			<p class="ndvr-account-empty"><?php esc_html_e( 'You haven\'t written a review yet.', 'rosette-reviews' ); ?></p>
		<?php else : ?>
			<ul class="ndvr-account-list">
				<?php foreach ( $mine as $ndvr_item ) : ?>
					<?php
					$ndvr_view    = $ndvr_item['view'];
					$ndvr_comment = $ndvr_item['comment'];
					$ndvr_rating  = $ndvr_view['overall'] > 0 ? $ndvr_view['overall'] : $ndvr_view['rating'];
					if ( ! $ndvr_item['published'] ) {
						$ndvr_status = __( 'Waiting for approval', 'rosette-reviews' );
					} elseif ( ! empty( $ndvr_view['has_pending_edit'] ) ) {
						$ndvr_status = __( 'Published. Your changes are waiting for approval.', 'rosette-reviews' );
					} else {
						$ndvr_status = __( 'Published', 'rosette-reviews' );
					}
					$ndvr_edited = '' !== (string) $ndvr_view['edited_at'] ? strtotime( $ndvr_view['edited_at'] . ' UTC' ) : false;
					?>
					<li class="ndvr-account-item ndvr-account-review">
						<span class="ndvr-account-main">
							<a class="ndvr-account-product" href="<?php echo esc_url( get_permalink( (int) $ndvr_comment->comment_post_ID ) ); ?>"><?php echo esc_html( get_the_title( (int) $ndvr_comment->comment_post_ID ) ); ?></a>
							<?php echo Html::stars( $ndvr_rating ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the helper. ?>
							<?php if ( '' !== (string) $ndvr_view['title'] ) : ?>
								<strong class="ndvr-account-title"><?php echo esc_html( $ndvr_view['title'] ); ?></strong>
							<?php endif; ?>
							<span class="ndvr-account-meta">
								<time datetime="<?php echo esc_attr( gmdate( 'c', (int) strtotime( $ndvr_comment->comment_date_gmt . ' UTC' ) ) ); ?>"><?php echo esc_html( wp_date( $ndvr_date, (int) strtotime( $ndvr_comment->comment_date_gmt . ' UTC' ) ) ); ?></time>
								· <span class="ndvr-account-status"><?php echo esc_html( $ndvr_status ); ?></span>
								<?php if ( $ndvr_edited ) : ?>
									·
									<?php
									/* translators: %s: date the edit was applied. */
									echo esc_html( sprintf( __( 'Edited %s', 'rosette-reviews' ), wp_date( $ndvr_date, $ndvr_edited ) ) );
									?>
								<?php endif; ?>
							</span>
							<?php if ( $ndvr_item['closed_on'] ) : ?>
								<span class="ndvr-account-meta">
									<?php
									/* translators: %s: date editing closed. */
									echo esc_html( sprintf( __( 'Editing closed on %s.', 'rosette-reviews' ), wp_date( $ndvr_date, (int) $ndvr_item['closed_on'] ) ) );
									?>
								</span>
							<?php endif; ?>
						</span>
						<?php if ( '' !== $ndvr_item['edit_url'] ) : ?>
							<a class="button ndvr-account-action" href="<?php echo esc_url( $ndvr_item['edit_url'] ); ?>">
								<?php esc_html_e( 'Edit', 'rosette-reviews' ); ?><span class="screen-reader-text"> <?php echo esc_html( get_the_title( (int) $ndvr_comment->comment_post_ID ) ); ?></span>
							</a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( ! empty( $more ) ) : ?>
				<p class="ndvr-account-meta"><?php esc_html_e( 'Your 50 newest reviews are shown.', 'rosette-reviews' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
	</section>
</div>
