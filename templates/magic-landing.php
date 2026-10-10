<?php
/**
 * Tokenized multi-product review-collection landing content.
 *
 * Override: copy to yourtheme/ndv-reviews/magic-landing.php
 *
 * Overrides should keep the action ndv-reviews/landing_form_fields (add-on
 * fields such as a video upload render there).
 *
 * @var bool                  $valid       Whether the token resolved.
 * @var string                $token       Raw token (re-submitted with each review).
 * @var int[]                 $products    Pending product ids.
 * @var array                 $criteria    Active criteria objects (all products).
 * @var array                 $criteria_by_product Product id => the criteria for its categories (RR-20).
 * @var string                $nonce       AJAX nonce.
 * @var string                $ajax_url    admin-ajax URL.
 * @var string                $ajax_action AJAX action name.
 * @var string                $default_author Name shown with the review unless changed.
 * @var bool                  $is_test     Admin test link (submissions are refused).
 * @var array                 $review_fields Active review questions (RR-11).
 * @var string                $review_source magic_link|list_link (RR-12 minimum length).
 *
 * @package NdvReviews
 */

use NdvReviews\Forms\AntiSpam;

defined( 'ABSPATH' ) || exit;

if ( empty( $valid ) ) :
	?>
	<div class="ndvr-collect-card ndvr-collect-invalid">
		<h1><?php esc_html_e( 'This link has expired', 'rosette-reviews' ); ?></h1>
		<p><?php esc_html_e( 'Your review link is no longer valid. If you still have items to review, please request a fresh link or contact the store.', 'rosette-reviews' ); ?></p>
		<p><a class="ndvr-collect-home" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Return to the store', 'rosette-reviews' ); ?></a></p>
	</div>
	<?php
	return;
endif;

if ( empty( $products ) ) :
	?>
	<div class="ndvr-collect-card">
		<h1><?php esc_html_e( 'All done — thank you', 'rosette-reviews' ); ?></h1>
		<p><?php esc_html_e( 'You have already reviewed everything from this order. We appreciate your feedback.', 'rosette-reviews' ); ?></p>
		<p><a class="ndvr-collect-home" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Return to the store', 'rosette-reviews' ); ?></a></p>
	</div>
	<?php
	return;
endif;
?>
<div class="ndvr-collect" data-ajax-url="<?php echo esc_url( $ajax_url ); ?>" data-action="<?php echo esc_attr( $ajax_action ); ?>">
	<header class="ndvr-collect-header">
		<h1><?php esc_html_e( 'Share your feedback', 'rosette-reviews' ); ?></h1>
		<p><?php esc_html_e( 'Tell other shoppers what you think. Each item has its own short form.', 'rosette-reviews' ); ?></p>
		<?php if ( ! empty( $is_test ) ) : ?>
			<p class="ndvr-collect-test" role="note"><?php esc_html_e( 'This page was opened from a test email. It shows what the customer sees; reviews cannot be submitted from it.', 'rosette-reviews' ); ?></p>
		<?php endif; ?>
	</header>

	<?php foreach ( $products as $ndvr_pid ) : ?>
		<?php
		$ndvr_product = wc_get_product( $ndvr_pid );
		if ( ! $ndvr_product ) {
			continue;
		}
		$ndvr_img = wp_get_attachment_image_url( $ndvr_product->get_image_id(), 'thumbnail' );
		?>
		<form class="ndvr-collect-card ndvr-collect-form" data-product="<?php echo esc_attr( $ndvr_pid ); ?>">
			<div class="ndvr-collect-product">
				<?php if ( $ndvr_img ) : ?>
					<img src="<?php echo esc_url( $ndvr_img ); ?>" alt="" class="ndvr-collect-thumb" />
				<?php endif; ?>
				<span class="ndvr-collect-name"><?php echo esc_html( $ndvr_product->get_name() ); ?></span>
			</div>

			<div class="ndvr-fields">
				<?php
				// RR-20: each product rates on the criteria for its own categories.
				$ndvr_criteria = isset( $criteria_by_product[ $ndvr_pid ] ) ? $criteria_by_product[ $ndvr_pid ] : $criteria;
				?>
				<?php if ( ! empty( $ndvr_criteria ) ) : ?>
					<div class="ndvr-criteria-group">
						<?php foreach ( $ndvr_criteria as $ndvr_c ) : ?>
							<fieldset class="ndvr-criterion">
								<legend class="ndvr-criterion-label"><?php echo esc_html( $ndvr_c->name ); ?></legend>
								<div class="ndvr-stars" role="radiogroup" aria-label="<?php echo esc_attr( $ndvr_c->name ); ?>">
									<?php for ( $ndvr_s = 5; $ndvr_s >= 1; $ndvr_s-- ) : ?>
										<?php $ndvr_fid = 'p' . (int) $ndvr_pid . '-c' . (int) $ndvr_c->id . '-s' . $ndvr_s; ?>
										<input class="ndvr-star-input" type="radio" id="<?php echo esc_attr( $ndvr_fid ); ?>" name="ndvr_criteria[<?php echo esc_attr( $ndvr_c->id ); ?>]" value="<?php echo esc_attr( $ndvr_s ); ?>" />
										<label class="ndvr-star-label" for="<?php echo esc_attr( $ndvr_fid ); ?>"><span class="screen-reader-text"><?php echo esc_html( $ndvr_s ); ?></span></label>
									<?php endfor; ?>
								</div>
							</fieldset>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<p class="ndvr-field">
					<label><?php esc_html_e( 'Name shown with your review', 'rosette-reviews' ); ?>
						<input type="text" name="author" maxlength="60" autocomplete="name" value="<?php echo esc_attr( isset( $default_author ) ? $default_author : '' ); ?>" />
					</label>
				</p>

				<p class="ndvr-field">
					<label><?php esc_html_e( 'Review title (optional)', 'rosette-reviews' ); ?>
						<input type="text" name="ndvr_title" maxlength="150" />
					</label>
				</p>

				<p class="ndvr-field">
					<label for="<?php echo esc_attr( 'p' . (int) $ndvr_pid . '-comment' ); ?>"><?php esc_html_e( 'Your review', 'rosette-reviews' ); ?> <span class="required">*</span></label>
						<?php
						$ndvr_min = \NdvReviews\Reviews\ReviewLength::min(
							array(
								'source'     => isset( $review_source ) ? (string) $review_source : 'magic_link',
								'product_id' => (int) $ndvr_pid,
							)
						);
						?>
						<textarea id="<?php echo esc_attr( 'p' . (int) $ndvr_pid . '-comment' ); ?>" name="comment" rows="5" required<?php echo \NdvReviews\Reviews\ReviewLength::textarea_attrs( 'p' . (int) $ndvr_pid . '-length', $ndvr_min ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the helper. ?>></textarea>
						<?php echo \NdvReviews\Reviews\ReviewLength::after_textarea( 'p' . (int) $ndvr_pid . '-length', $ndvr_min ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the helper. ?>
				</p>

				<?php
				// One <form> per product: ids p{product}-f{field}-o{n} stay unique.
				if ( ! empty( $review_fields ) ) {
					echo \NdvReviews\Forms\FieldRenderer::render( (array) $review_fields, 'p' . (int) $ndvr_pid . '-' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer.
				}
				?>

				<fieldset class="ndvr-field ndvr-field-recommend">
					<legend><?php esc_html_e( 'Would you recommend this product?', 'rosette-reviews' ); ?></legend>
					<label><input type="radio" name="ndvr_recommend" value="yes" /> <?php esc_html_e( 'Yes', 'rosette-reviews' ); ?></label>
					<label><input type="radio" name="ndvr_recommend" value="neutral" checked="checked" /> <?php esc_html_e( 'Neutral', 'rosette-reviews' ); ?></label>
					<label><input type="radio" name="ndvr_recommend" value="no" /> <?php esc_html_e( 'No', 'rosette-reviews' ); ?></label>
				</fieldset>

				<?php if ( $settings->get( 'photo_uploads' ) ) : ?>
					<p class="ndvr-field">
						<label><?php esc_html_e( 'Add photos (optional)', 'rosette-reviews' ); ?>
							<input type="file" name="ndvr_photos[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple="multiple" />
						</label>
					</p>
				<?php endif; ?>

				<?php
				/**
				 * Fires in each product's landing form, after the photo field and
				 * before consent (RR-00b E7). Each product has its own <form>, so
				 * field names need no prefix, but element ids must include the
				 * product id. File inputs are sent (the form posts FormData).
				 *
				 * @param int   $product_id Product id.
				 * @param array $criteria   This product's criteria (RR-20).
				 */
				do_action( 'ndv-reviews/landing_form_fields', (int) $ndvr_pid, $ndvr_criteria );
				?>

				<p class="ndvr-field ndvr-field-consent">
					<label><input type="checkbox" name="ndvr_consent" value="1" required /> <?php esc_html_e( 'I consent to my review being stored and published.', 'rosette-reviews' ); ?></label>
				</p>

				<p class="ndvr-hp" aria-hidden="true" style="position:absolute;left:-9999px;">
					<input type="text" name="<?php echo esc_attr( AntiSpam::HONEYPOT ); ?>" tabindex="-1" autocomplete="off" />
				</p>

				<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>" />
				<input type="hidden" name="product_id" value="<?php echo esc_attr( $ndvr_pid ); ?>" />
				<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>" />

				<div class="ndvr-collect-actions">
					<button type="submit" class="ndvr-collect-submit"><?php esc_html_e( 'Submit review', 'rosette-reviews' ); ?></button>
					<span class="ndvr-form-message" role="status" aria-live="polite"></span>
				</div>
			</div>
		</form>
	<?php endforeach; ?>
</div>
