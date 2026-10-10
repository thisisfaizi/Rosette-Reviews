<?php
/**
 * Importer: generic CSV reviews.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Importers;

use NdvReviews\Reviews\CriteriaRepository;
use NdvReviews\Reviews\Pool;
use NdvReviews\Reviews\RatingCache;
use NdvReviews\Reviews\ReviewRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Imports reviews from a CSV with columns:
 * product_id, author, email, rating, title, content, date, recommend, verified
 * (plus optional status = approved|pending, as written by the exporter).
 *
 * Re-importing the same file does not duplicate reviews: each imported review
 * stores a hash of product + email + content in `_ndvr_import_hash`, and rows
 * matching an existing hash (or, for reviews imported before the hash existed,
 * the same product, email and text) are skipped.
 */
class Csv {

	const HASH_META = '_ndvr_import_hash';

	/**
	 * Review repository.
	 *
	 * @var ReviewRepository
	 */
	private $reviews;

	/**
	 * Criteria repository (optional for backward compatibility).
	 *
	 * @var CriteriaRepository|null
	 */
	private $criteria;

	/**
	 * Constructor.
	 *
	 * @param ReviewRepository        $reviews  Review repository.
	 * @param RatingCache             $ratings  Unused; kept so existing callers keep working.
	 * @param CriteriaRepository|null $criteria Criteria repository.
	 */
	public function __construct( ReviewRepository $reviews, RatingCache $ratings, ?CriteriaRepository $criteria = null ) {
		unset( $ratings );
		$this->reviews  = $reviews;
		$this->criteria = $criteria ? $criteria : new CriteriaRepository();
	}

	/**
	 * Import from an uploaded CSV file path.
	 *
	 * @param string $file Absolute path to the CSV.
	 * @return array{imported:int,skipped:int,errors:string[],reasons:array<string,int>}
	 */
	public function import( $file ) {
		$result = array(
			'imported' => 0,
			'skipped'  => 0,
			'errors'   => array(),
			'reasons'  => array(),
		);

		if ( ! is_readable( $file ) ) {
			$result['errors'][] = __( 'CSV file could not be read.', 'rosette-reviews' );
			return $result;
		}

		$handle = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			$result['errors'][] = __( 'CSV file could not be opened.', 'rosette-reviews' );
			return $result;
		}

		$header = fgetcsv( $handle, 0, ',', '"', '\\' );
		if ( ! $header ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$result['errors'][] = __( 'CSV has no header row.', 'rosette-reviews' );
			return $result;
		}
		// Spreadsheet apps often save a UTF-8 byte-order mark before the first column name.
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
		$map       = array_flip( array_map( 'strtolower', array_map( 'trim', $header ) ) );

		// Review questions (RR-11): a `q_<slug>` column maps to that question;
		// an unknown slug creates an inactive Short text question first, so no
		// answer is lost and nothing new appears on the forms.
		$question_columns = array();
		$review_fields    = \NdvReviews\Plugin::instance()->container()->get( 'review_fields' );
		if ( $review_fields->ready() ) {
			foreach ( $map as $name => $index ) {
				if ( 0 !== strpos( (string) $name, 'q_' ) ) {
					continue;
				}
				$field_id = $review_fields->ensure_imported( substr( (string) $name, 2 ) );
				if ( $field_id ) {
					$question_columns[ $field_id ] = (string) $name;
				} else {
					/* translators: %s: CSV column name. */
					$result['errors'][] = sprintf( __( 'Column %s was skipped.', 'rosette-reviews' ), (string) $name );
				}
			}
		}

		// Rows need at least one active criterion. Each row's single rating is
		// applied to every criterion of its own product (RR-20, below), so the
		// review's overall score equals the CSV rating.
		$any_active = (bool) $this->criteria->get_active();

		while ( ( $row = fgetcsv( $handle, 0, ',', '"', '\\' ) ) !== false ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( array( null ) === $row ) {
				continue; // Blank line.
			}

			$get = static function ( $key ) use ( $row, $map ) {
				$value = isset( $map[ $key ], $row[ $map[ $key ] ] ) ? trim( (string) $row[ $map[ $key ] ] ) : '';
				return self::unguard_cell( $value );
			};

			$product_id = absint( $get( 'product_id' ) );
			$content    = $get( 'content' );
			$email      = sanitize_email( $get( 'email' ) );
			$email      = is_email( $email ) ? $email : 'import@example.com';
			$rating_raw = $get( 'rating' );

			if ( ! $product_id || '' === $content ) {
				$this->skip( $result, __( 'missing product_id or content', 'rosette-reviews' ) );
				continue;
			}
			if ( ! is_numeric( $rating_raw ) || (float) $rating_raw < 1 || (float) $rating_raw > 5 ) {
				$this->skip( $result, __( 'rating not between 1 and 5', 'rosette-reviews' ) );
				continue;
			}
			if ( ! $any_active ) {
				$this->skip( $result, __( 'no active rating criteria', 'rosette-reviews' ) );
				continue;
			}

			$pool_id = Pool::resolve_id( $product_id );
			$hash    = $this->hash( $pool_id, $email, $content );
			if ( $this->exists( $pool_id, $email, $content, $hash ) ) {
				$this->skip( $result, __( 'already imported', 'rosette-reviews' ) );
				continue;
			}

			$answers = array();
			foreach ( $question_columns as $field_id => $column ) {
				$answers[ $field_id ] = $get( $column );
			}

			$rating = round( (float) $rating_raw, 2 );
			$ids    = array();
			foreach ( $this->criteria->get_active( $pool_id ) as $criterion ) {
				$ids[] = (int) $criterion->id;
			}
			$criteria = array_fill_keys( $ids, $rating );
			$status   = strtolower( $get( 'status' ) );

			$created = $this->reviews->create(
				array(
					'product_id'      => $product_id,
					'author'          => $get( 'author' ) ? $get( 'author' ) : __( 'Anonymous', 'rosette-reviews' ),
					'email'           => $email,
					'content'         => $content,
					'title'           => $get( 'title' ),
					'recommend'       => in_array( $get( 'recommend' ), array( 'yes', 'no', 'neutral' ), true ) ? $get( 'recommend' ) : 'neutral',
					'criteria'        => $criteria,
					'source'          => 'import',
					'answers'         => $answers,
					'answers_present' => false,
					'approved'        => in_array( $status, array( 'pending', 'hold' ), true ) ? 0 : 1,
				)
			);

			if ( is_wp_error( $created ) ) {
				$this->skip( $result, $created->get_error_message() );
				continue;
			}

			update_comment_meta( $created, self::HASH_META, $hash );

			$verified = strtolower( $get( 'verified' ) );
			if ( in_array( $verified, array( '1', 'yes', 'true' ), true ) ) {
				update_comment_meta( $created, '_ndvr_verified', 1 );
				update_comment_meta( $created, 'verified', 1 );
			} elseif ( in_array( $verified, array( '0', 'no', 'false' ), true ) ) {
				update_comment_meta( $created, '_ndvr_verified', 0 );
				delete_comment_meta( $created, 'verified' );
			}

			$this->set_date( $created, $get( 'date' ) );

			++$result['imported'];
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		/**
		 * Fires at the end of a third-party import run (RR-03), so the
		 * transparency notice's "imported reviews" sentence updates at once.
		 * Other importers that write `_ndvr_import_hash` fire it with their slug.
		 *
		 * @param string $importer Importer slug.
		 */
		do_action( 'ndv-reviews/third_party_import_done', 'csv' );

		return $result;
	}

	/**
	 * Undo Exporter::csv_cell()'s spreadsheet-formula guard ('=..., '+..., ...),
	 * public so add-on importers read exported cells the same way (RR-00b E11).
	 *
	 * @param string $value Cell value.
	 * @return string
	 */
	public static function unguard_cell( $value ) {
		$value = (string) $value;

		return preg_match( "/^'[=+\-@\t\r]/", $value ) ? substr( $value, 1 ) : $value;
	}

	/**
	 * Count a skipped row under its reason.
	 *
	 * @param array<string,mixed> $result Result accumulator.
	 * @param string              $reason Reason.
	 * @return void
	 */
	private function skip( array &$result, $reason ) {
		++$result['skipped'];
		$result['reasons'][ $reason ] = isset( $result['reasons'][ $reason ] ) ? $result['reasons'][ $reason ] + 1 : 1;
	}

	/**
	 * Dedupe hash for an imported review.
	 *
	 * @param int    $pool_id Post the review attaches to.
	 * @param string $email   Reviewer email.
	 * @param string $content Review text.
	 * @return string
	 */
	private function hash( $pool_id, $email, $content ) {
		$text = strtolower( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $content ) ) ) );

		return sha1( (int) $pool_id . '|' . strtolower( $email ) . '|' . $text );
	}

	/**
	 * Whether this review is already on the post.
	 *
	 * @param int    $pool_id Post the review attaches to.
	 * @param string $email   Reviewer email.
	 * @param string $content Review text.
	 * @param string $hash    Dedupe hash.
	 * @return bool
	 */
	private function exists( $pool_id, $email, $content, $hash ) {
		$by_hash = get_comments(
			array(
				'post_id'    => $pool_id,
				'status'     => 'all',
				'count'      => true,
				'meta_key'   => self::HASH_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
				'meta_value' => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
			)
		);
		if ( (int) $by_hash > 0 ) {
			return true;
		}

		global $wpdb;

		// Reviews already on the post without the hash (imported before it
		// existed, or exported from this site). Rows without an email match
		// reviews with no address or the importer's placeholder.
		$emails = 'import@example.com' === $email ? array( '', $email ) : array( $email );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID = %d AND comment_author_email IN (%s, %s) AND TRIM(comment_content) = %s AND comment_type IN ('review', 'comment')",
				$pool_id,
				$emails[0],
				end( $emails ),
				trim( wp_kses_post( $content ) )
			)
		);

		return (int) $found > 0;
	}

	/**
	 * Set the review date from the CSV (site-local time). Written directly:
	 * wp_update_comment() re-filters the whole comment just to change a date.
	 *
	 * @param int    $comment_id Review id.
	 * @param string $date       Date string from the CSV.
	 * @return void
	 */
	private function set_date( $comment_id, $date ) {
		global $wpdb;

		if ( '' === $date ) {
			return;
		}

		$timestamp = strtotime( $date );
		if ( false === $timestamp ) {
			return;
		}

		$local = gmdate( 'Y-m-d H:i:s', $timestamp );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->comments,
			array(
				'comment_date'     => $local,
				'comment_date_gmt' => get_gmt_from_date( $local ),
			),
			array( 'comment_ID' => (int) $comment_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		clean_comment_cache( (int) $comment_id );
	}
}
