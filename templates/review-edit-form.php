<?php
/**
 * Edit form for a customer's own review (RR-15; RR-22 reuses it).
 *
 * Override: copy to yourtheme/ndv-reviews/review-edit-form.php. Keep the
 * field names, the nonce and the action `ndv-reviews/review_edit_form_fields`.
 *
 * @var int                              $review_id  Review.
 * @var int                              $product_id Product the review is on.
 * @var array<string,mixed>              $values     Prefill (pending revision, else live): content, title,
 *                                                   criteria, rating, answers, remove_media, pending.
 * @var array<int,object>                $criteria   Criteria it can be scored on ([] for a plain rating).
 * @var bool                             $plain      One overall rating instead of criteria.
 * @var array<int,array<string,mixed>>   $media      Current photos/videos (id, url, thumb, type).
 * @var array<int,array<string,mixed>>   $questions  Active review questions.
 * @var string                           $action     Form action URL.
 * @var string                           $cancel_url Cancel link.
 * @var string                           $nonce      Nonce action.
 * @var string                           $save_field Name of the submit field.
 *
 * @package NdvReviews
 */

use NdvReviews\Forms\AntiSpam;
use NdvReviews\Forms\FieldRenderer;
use NdvReviews\Reviews\ReviewLength;

defined( 'ABSPATH' ) || exit;

$ndvr_p        = 'ndvr-edit-' . (int) $review_id;
$ndvr_min      = ReviewLength::min(
	array(
		'source'     => (string) get_comment_meta( (int) $review_id, '_ndvr_source', true ),
		'product_id' => (int) $product_id,
	)
);
$ndvr_star_set = static function ( $name, $legend, $current, $id_base ) {
	?>
	<fieldset class="ndvr-criterion">
		<legend class="ndvr-criterion-label"><?php echo esc_html( $legend ); ?></legend>
		<div class="ndvr-stars">
			<?php for ( $ndvr_star = 5; $ndvr_star >= 1; $ndvr_star-- ) : ?>
				<?php $ndvr_fid = $id_base . '-s' . $ndvr_star; ?>
				<input class="ndvr-star-input" type="radio" id="<?php echo esc_attr( $ndvr_fid ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $ndvr_star ); ?>" <?php checked( (int) round( (float) $current ), $ndvr_star ); ?> />
				<label class="ndvr-star-label" for="<?php echo esc_attr( $ndvr_fid ); ?>">
					<span class="screen-reader-text">
						<?php
						/* translators: %d: number of stars. */
						echo esc_html( sprintf( _n( '%d star', '%d stars', $ndvr_star, 'rosette-reviews' ), $ndvr_star ) );
						?>
					</span>
				</label>
			<?php endfor; ?>
		</div>
	</fieldset>
	<?php
};
?>
<form class="ndvr-edit-form ndvr-fields" method="post" action="<?php echo esc_url( $action ); ?>">
	<?php wp_nonce_field( $nonce ); ?>
	<input type="hidden" name="review_id" value="<?php echo esc_attr( (string) (int) $review_id ); ?>" />
	<?php if ( ! empty( $values['pending'] ) ) : ?>
		<p class="ndvr-edit-note"><?php esc_html_e( 'You\'re changing the edit that is waiting for approval.', 'rosette-reviews' ); ?></p>
	<?php endif; ?>

	<div class="ndvr-criteria-group">
		<?php if ( $plain ) : ?>
			<?php $ndvr_star_set( 'ndvr_rating', __( 'Your rating', 'rosette-reviews' ), (int) $values['rating'], $ndvr_p . '-rating' ); ?>
		<?php else : ?>
			<?php foreach ( $criteria as $ndvr_cid => $ndvr_criterion ) : ?>
				<?php $ndvr_star_set( 'ndvr_criteria[' . (int) $ndvr_cid . ']', (string) $ndvr_criterion->name, isset( $values['criteria'][ $ndvr_cid ] ) ? (float) $values['criteria'][ $ndvr_cid ] : 0, $ndvr_p . '-c' . (int) $ndvr_cid ); ?>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>

	<p class="ndvr-field">
		<label for="<?php echo esc_attr( $ndvr_p . '-title' ); ?>"><?php esc_html_e( 'Review title (optional)', 'rosette-reviews' ); ?></label>
		<input id="<?php echo esc_attr( $ndvr_p . '-title' ); ?>" name="ndvr_title" type="text" maxlength="150" value="<?php echo esc_attr( (string) $values['title'] ); ?>" />
	</p>

	<p class="ndvr-field">
		<label for="<?php echo esc_attr( $ndvr_p . '-content' ); ?>"><?php esc_html_e( 'Your review', 'rosette-reviews' ); ?> <span class="required">*</span></label>
		<textarea id="<?php echo esc_attr( $ndvr_p . '-content' ); ?>" name="ndvr_content" rows="6" required<?php echo ReviewLength::textarea_attrs( $ndvr_p . '-length', $ndvr_min ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the helper. ?>><?php echo esc_textarea( (string) $values['content'] ); ?></textarea>
		<?php echo ReviewLength::after_textarea( $ndvr_p . '-length', $ndvr_min ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the helper. ?>
	</p>

	<?php echo FieldRenderer::render( $questions, $ndvr_p . '-', (array) $values['answers'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer. ?>

	<?php if ( ! empty( $media ) ) : ?>
		<fieldset class="ndvr-field ndvr-edit-media">
			<legend><?php esc_html_e( 'Your photos', 'rosette-reviews' ); ?></legend>
			<?php foreach ( $media as $ndvr_i => $ndvr_m ) : ?>
				<?php $ndvr_mid = $ndvr_p . '-m' . (int) $ndvr_m['id']; ?>
				<span class="ndvr-edit-photo">
					<?php if ( 'image' === $ndvr_m['type'] && '' !== (string) $ndvr_m['thumb'] ) : ?>
						<img src="<?php echo esc_url( $ndvr_m['thumb'] ); ?>" alt="" width="72" height="72" />
					<?php endif; ?>
					<input type="checkbox" id="<?php echo esc_attr( $ndvr_mid ); ?>" name="ndvr_remove_media[]" value="<?php echo esc_attr( (string) (int) $ndvr_m['id'] ); ?>" <?php checked( in_array( (int) $ndvr_m['id'], array_map( 'intval', (array) $values['remove_media'] ), true ) ); ?> />
					<label for="<?php echo esc_attr( $ndvr_mid ); ?>">
						<?php
						echo esc_html(
							'image' === $ndvr_m['type']
								/* translators: %d: photo number. */
								? sprintf( __( 'Remove photo %d', 'rosette-reviews' ), (int) $ndvr_i + 1 )
								/* translators: %d: video number. */
								: sprintf( __( 'Remove video %d', 'rosette-reviews' ), (int) $ndvr_i + 1 )
						);
						?>
					</label>
				</span>
			<?php endforeach; ?>
		</fieldset>
	<?php endif; ?>

	<?php
	/**
	 * Fires inside the customer edit form, before the buttons.
	 *
	 * @param array<string,mixed> $review review_id, product_id and the prefill values.
	 */
	do_action(
		'ndv-reviews/review_edit_form_fields',
		array(
			'review_id'  => (int) $review_id,
			'product_id' => (int) $product_id,
			'values'     => $values,
		)
	);
	?>

	<p class="ndvr-hp" aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;">
		<label for="<?php echo esc_attr( $ndvr_p . '-hp' ); ?>"><?php esc_html_e( 'Leave this field empty', 'rosette-reviews' ); ?></label>
		<input type="text" id="<?php echo esc_attr( $ndvr_p . '-hp' ); ?>" name="<?php echo esc_attr( AntiSpam::HONEYPOT ); ?>" tabindex="-1" autocomplete="off" />
	</p>

	<p class="ndvr-edit-actions">
		<button type="submit" class="button" name="<?php echo esc_attr( $save_field ); ?>" value="1"><?php esc_html_e( 'Save changes', 'rosette-reviews' ); ?></button>
		<a class="ndvr-edit-cancel" href="<?php echo esc_url( $cancel_url ); ?>"><?php esc_html_e( 'Cancel', 'rosette-reviews' ); ?></a>
	</p>
</form>
