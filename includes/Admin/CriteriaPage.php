<?php
/**
 * Admin screen: manage rating criteria.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Admin;

use NdvReviews\Support\Caps;
use NdvReviews\Support\Registerable;
use NdvReviews\Reviews\CriteriaRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Rating Criteria screen (under the Rosette Reviews menu owned by DashboardPage).
 */
class CriteriaPage implements Registerable {

	const MENU_SLUG  = 'ndv-reviews';
	const PAGE_SLUG  = 'ndv-reviews-criteria';
	const NONCE      = 'ndvr_criteria_action';

	/**
	 * Criteria repository.
	 *
	 * @var CriteriaRepository
	 */
	private $criteria;

	/**
	 * Notices to render after handling an action.
	 *
	 * @var array<int,array{type:string,message:string}>
	 */
	private $notices = array();

	/**
	 * Constructor.
	 *
	 * @param CriteriaRepository $criteria Criteria repository.
	 */
	public function __construct( CriteriaRepository $criteria ) {
		$this->criteria = $criteria;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	/**
	 * Add the Criteria subpage.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Rating Criteria', 'rosette-reviews' ),
			__( 'Rating Criteria', 'rosette-reviews' ),
			Caps::manage( 'criteria' ),
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Handle add/update/delete POST actions (nonce + capability guarded).
	 *
	 * @return void
	 */
	public function handle_actions() {
		if ( ! isset( $_POST['ndvr_criteria_do'] ) ) {
			return;
		}

		if ( ! current_user_can( Caps::manage( 'criteria' ) ) ) {
			wp_die( esc_html__( 'You are not allowed to manage review criteria.', 'rosette-reviews' ) );
		}

		check_admin_referer( self::NONCE );

		$do = sanitize_key( wp_unslash( $_POST['ndvr_criteria_do'] ) );

		if ( 'add' === $do ) {
			$name   = isset( $_POST['ndvr_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ndvr_name'] ) ) : '';
			$result = $this->criteria->insert( array( 'name' => $name ) );
			$this->push_result( $result, __( 'Criterion added.', 'rosette-reviews' ) );
		} elseif ( 'delete' === $do ) {
			$id = isset( $_POST['ndvr_id'] ) ? absint( $_POST['ndvr_id'] ) : 0;
			$result = $this->criteria->delete( $id );
			if ( false === $result ) {
				$result = new \WP_Error( 'ndvr_criteria_missing', __( 'That criterion no longer exists.', 'rosette-reviews' ) );
			}
			$this->push_result( $result, __( 'Criterion deleted.', 'rosette-reviews' ) );
		} elseif ( 'toggle' === $do ) {
			$id      = isset( $_POST['ndvr_id'] ) ? absint( $_POST['ndvr_id'] ) : 0;
			$status  = isset( $_POST['ndvr_status'] ) && 'active' === $_POST['ndvr_status'] ? 'active' : 'inactive';
			$result  = $this->criteria->update( $id, array( 'status' => $status ) );
			$this->push_result( $result, __( 'Criterion updated.', 'rosette-reviews' ) );
		} else {
			$id = isset( $_POST['ndvr_id'] ) ? absint( $_POST['ndvr_id'] ) : 0;

			/**
			 * Handle a Rating Criteria action this page doesn't know (RR-20; Pro's
			 * "Show for"). Runs only after the capability and nonce checks.
			 *
			 * @param true|\WP_Error|null $result null when not handled.
			 * @param string              $do     The posted ndvr_criteria_do value.
			 * @param int                 $id     The posted criterion id (0 when none).
			 */
			$result = apply_filters( 'ndv-reviews/criteria_handle_action', null, $do, $id );
			if ( true === $result || is_wp_error( $result ) ) {
				$this->push_result( $result, __( 'Criterion updated.', 'rosette-reviews' ) );
			} elseif ( null !== $result ) {
				// Only true or a WP_Error mean anything; never report another value as success.
				$this->push_result( new \WP_Error( 'ndvr_criteria_action', __( 'That change could not be saved.', 'rosette-reviews' ) ), '' );
			}
		}
	}

	/**
	 * Record a notice based on a repository result.
	 *
	 * @param mixed  $result  Result from a repository call.
	 * @param string $success Success message.
	 * @return void
	 */
	private function push_result( $result, $success ) {
		if ( is_wp_error( $result ) ) {
			$this->notices[] = array(
				'type'    => 'error',
				'message' => $result->get_error_message(),
			);
		} else {
			$this->notices[] = array(
				'type'    => 'success',
				'message' => $success,
			);
		}
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( Caps::manage( 'criteria' ) ) ) {
			return;
		}

		$all      = $this->criteria->get_all();
		$active   = $this->criteria->count_active();
		$max      = $this->criteria->max_active();
		$at_cap   = $active >= $max;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Rating Criteria', 'rosette-reviews' ); ?></h1>

			<?php foreach ( $this->notices as $notice ) : ?>
				<div class="notice notice-<?php echo 'error' === $notice['type'] ? 'error' : 'success'; ?> is-dismissible">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
				</div>
			<?php endforeach; ?>

			<div class="ndvr-card">
				<div class="ndvr-card-header"><h2><?php esc_html_e( 'Active criteria', 'rosette-reviews' ); ?></h2></div>
				<p class="description">
					<?php
					printf(
						/* translators: 1: active count, 2: max allowed. */
						esc_html__( 'Customers rate each active criterion when leaving a review. Active: %1$d of %2$d.', 'rosette-reviews' ),
						(int) $active,
						(int) $max
					);
					?>
				</p>

				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'rosette-reviews' ); ?></th>
							<th><?php esc_html_e( 'Status', 'rosette-reviews' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'rosette-reviews' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $all ) ) : ?>
							<tr><td colspan="3"><?php esc_html_e( 'No criteria yet.', 'rosette-reviews' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $all as $criterion ) : ?>
							<tr>
								<td>
									<?php echo esc_html( $criterion->name ); ?>
									<?php if ( CriteriaRepository::scoping_ready() ) : ?>
										<br /><span class="description"><?php echo esc_html( CriteriaRepository::scope_label( $criterion ) ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( 'active' === $criterion->status ? __( 'Active', 'rosette-reviews' ) : __( 'Inactive', 'rosette-reviews' ) ); ?></td>
								<td>
									<form method="post" style="display:inline;">
										<?php wp_nonce_field( self::NONCE ); ?>
										<input type="hidden" name="ndvr_id" value="<?php echo esc_attr( $criterion->id ); ?>" />
										<input type="hidden" name="ndvr_status" value="<?php echo 'active' === $criterion->status ? 'inactive' : 'active'; ?>" />
										<button type="submit" name="ndvr_criteria_do" value="toggle" class="button button-small">
											<?php echo esc_html( 'active' === $criterion->status ? __( 'Deactivate', 'rosette-reviews' ) : __( 'Activate', 'rosette-reviews' ) ); ?>
										</button>
									</form>
									<form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this criterion and its scores?', 'rosette-reviews' ) ); ?>');">
										<?php wp_nonce_field( self::NONCE ); ?>
										<input type="hidden" name="ndvr_id" value="<?php echo esc_attr( $criterion->id ); ?>" />
										<button type="submit" name="ndvr_criteria_do" value="delete" class="button button-small button-link-delete">
											<?php esc_html_e( 'Delete', 'rosette-reviews' ); ?>
										</button>
									</form>
									<?php
									/**
									 * Print more controls in a criterion's actions cell (RR-20; Pro's "Show for").
									 *
									 * @param Criteria $criterion Criterion.
									 */
									do_action( 'ndv-reviews/criteria_row_after', $criterion );
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="ndvr-card">
				<div class="ndvr-card-header"><h2><?php esc_html_e( 'Add a criterion', 'rosette-reviews' ); ?></h2></div>

				<?php if ( $at_cap ) : ?>
					<div class="notice notice-info inline">
						<p>
							<?php
							printf(
								/* translators: %d: max criteria. */
								esc_html__( 'The free version supports up to %d active criteria. Rosette Reviews Pro removes this limit.', 'rosette-reviews' ),
								(int) $max
							);
							?>
						</p>
					</div>
				<?php else : ?>
					<form method="post">
						<?php wp_nonce_field( self::NONCE ); ?>
						<input type="text" name="ndvr_name" required maxlength="191" placeholder="<?php esc_attr_e( 'e.g. Comfort', 'rosette-reviews' ); ?>" />
						<button type="submit" name="ndvr_criteria_do" value="add" class="button button-primary">
							<?php esc_html_e( 'Add criterion', 'rosette-reviews' ); ?>
						</button>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
