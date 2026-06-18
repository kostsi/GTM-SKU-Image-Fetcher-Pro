<?php
namespace GTMIF;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Runner — κεντρικός engine επεξεργασίας.
 *
 * Λογική:
 *  1. Βρίσκει προϊόντα που χρειάζονται εικόνες (δεν έχουν featured ή gallery < 3).
 *  2. Τα βάζει σε queue (Action Scheduler ή WP-Cron fallback).
 *  3. Κάθε job: αναζητά εικόνες, κατεβάζει, ορίζει featured image + gallery.
 *  4. Ενημερώνει Stats σε πραγματικό χρόνο.
 */
class Runner {

	const ACTION_PROCESS = 'gtmif_process_product';
	const META_ATTEMPTS  = '_gtmif_attempts';
	const META_LAST_TRY  = '_gtmif_last_attempt';
	const META_LAST_ERR  = '_gtmif_last_error';
	const META_DONE      = '_gtmif_done_v2';
	const META_JOB_ID    = '_gtmif_job_id';

	public static function init() : void {
		add_action( self::ACTION_PROCESS, [ __CLASS__, 'process_product' ], 10, 2 );
		// AJAX για progress polling
		add_action( 'wp_ajax_gtmif_progress', [ __CLASS__, 'ajax_progress' ] );
		add_action( 'wp_ajax_gtmif_pause',    [ __CLASS__, 'ajax_pause' ] );
		add_action( 'wp_ajax_gtmif_resume',   [ __CLASS__, 'ajax_resume' ] );
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Queue management
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Βάζει στο queue προϊόντα που χρειάζονται δουλειά.
	 *
	 * @param int  $limit    Μέγιστος αριθμός προϊόντων
	 * @param bool $reset    Αν true, καθαρίζει META_DONE (re-process)
	 * @return int  Πόσα βάλαμε στο queue
	 */
	public static function enqueue( int $limit = 200, bool $reset = false ) : int {
		$job_id = uniqid( 'gtmif_', true );

		$ids = self::find_products_needing_work( $limit, $reset );
		if ( empty( $ids ) ) {
			Logger::info([ 'action' => 'queue', 'message' => 'Δεν βρέθηκαν προϊόντα που χρειάζονται εικόνες.' ]);
			return 0;
		}

		Stats::update([
			'last_run_started'       => current_time( 'mysql' ),
			'last_run_finished'      => null,
			'current_job_id'         => $job_id,
			'current_job_total'      => count( $ids ),
			'current_job_processed'  => 0,
			'current_job_status'     => 'running',
		]);

		foreach ( $ids as $product_id ) {
			update_post_meta( $product_id, self::META_JOB_ID, $job_id );
			self::enqueue_single( $product_id, $job_id );
		}

		Stats::increment( 'total_queued', count( $ids ) );
		Logger::info([ 'action' => 'queue', 'message' => "Job {$job_id}: " . count($ids) . " προϊόντα προστέθηκαν στο queue." ]);

		return count( $ids );
	}

	private static function enqueue_single( int $product_id, string $job_id ) : void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				self::ACTION_PROCESS,
				[ 'product_id' => $product_id, 'job_id' => $job_id ],
				'gtmif'
			);
		} else {
			wp_schedule_single_event(
				time() + wp_rand( 1, 5 ),
				self::ACTION_PROCESS,
				[ $product_id, $job_id ]
			);
		}
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Find products
	// ──────────────────────────────────────────────────────────────────────────

	public static function find_products_needing_work( int $limit = 200, bool $reset = false ) : array {
		global $wpdb;
		$settings = Settings::get();

		// Βρίσκουμε προϊόντα που:
		// α) δεν έχουν featured image ΚΑΙ/Η
		// β) έχουν gallery με λιγότερες από min_gallery_count εικόνες
		$min_gallery = (int) ( $settings['min_gallery_count'] ?? 3 );
		$fill_featured = ! empty( $settings['fill_featured'] );
		$fill_gallery  = ! empty( $settings['fill_gallery'] );

		$status_clause = '';
		if ( ! empty( $settings['only_instock'] ) ) {
			$status_clause = "AND EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm_stock
				WHERE pm_stock.post_id = p.ID
				AND pm_stock.meta_key = '_stock_status'
				AND pm_stock.meta_value = 'instock'
			)";
		}

		$done_clause = '';
		if ( ! $reset ) {
			$done_clause = "AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm_done
				WHERE pm_done.post_id = p.ID
				AND pm_done.meta_key = '" . self::META_DONE . "'
				AND pm_done.meta_value = 'complete'
			)";
		}

		// Conditions for needing featured image
		$needs_featured_sql = $fill_featured
			? "NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_f WHERE pm_f.post_id = p.ID AND pm_f.meta_key = '_thumbnail_id')"
			: '0';

		// Conditions for needing gallery
		$needs_gallery_sql = $fill_gallery
			? "(
				NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm_g
					WHERE pm_g.post_id = p.ID AND pm_g.meta_key = '_product_image_gallery'
					AND pm_g.meta_value != ''
				)
				OR (
					SELECT CHAR_LENGTH(COALESCE(pm_gc.meta_value,'')) - CHAR_LENGTH(REPLACE(COALESCE(pm_gc.meta_value,''), ',', '')) + 1
					FROM {$wpdb->postmeta} pm_gc
					WHERE pm_gc.post_id = p.ID AND pm_gc.meta_key = '_product_image_gallery'
					LIMIT 1
				) < {$min_gallery}
			)"
			: '0';

		$limit_int = (int) $limit;

		$sql = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
			WHERE p.post_type = 'product'
			AND p.post_status = 'publish'
			AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_sku WHERE pm_sku.post_id = p.ID AND pm_sku.meta_key = '_sku' AND pm_sku.meta_value != '')
			{$status_clause}
			{$done_clause}
			AND ( {$needs_featured_sql} OR {$needs_gallery_sql} )
			LIMIT {$limit_int}";

		$ids = $wpdb->get_col( $sql );
		return array_map( 'intval', $ids ?: [] );
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Process single product (called by Action Scheduler)
	// ──────────────────────────────────────────────────────────────────────────

	public static function process_product( $product_id, $job_id = '' ) : void {
		// Normalize args (AS passes array on some versions)
		if ( is_array( $product_id ) ) {
			$job_id     = $product_id['job_id'] ?? '';
			$product_id = $product_id['product_id'] ?? 0;
		}
		$product_id = absint( $product_id );
		if ( ! $product_id ) return;

		$settings     = Settings::get();
		$fill_featured = ! empty( $settings['fill_featured'] );
		$fill_gallery  = ! empty( $settings['fill_gallery'] );
		$gallery_target = max( 3, (int) ( $settings['gallery_target'] ?? 3 ) );
		$min_gallery    = max( 1, (int) ( $settings['min_gallery_count'] ?? 3 ) );

		$sku   = trim( (string) get_post_meta( $product_id, '_sku', true ) );
		$title = trim( (string) get_the_title( $product_id ) );

		if ( empty( $sku ) ) {
			self::finish_product( $product_id, $job_id, 'error', 'Δεν υπάρχει SKU.' );
			return;
		}

		// Έλεγχος attempts
		$attempts = (int) get_post_meta( $product_id, self::META_ATTEMPTS, true );
		if ( ! empty( $settings['skip_if_attempted'] ) && $attempts >= (int) $settings['max_attempts'] ) {
			Logger::debug([ 'action' => 'skip', 'product_id' => $product_id, 'sku' => $sku, 'message' => 'Max attempts reached.' ]);
			self::finish_product( $product_id, $job_id, 'skipped' );
			return;
		}

		// Ανάλυση τρέχουσας κατάστασης εικόνων
		$has_featured    = has_post_thumbnail( $product_id );
		$current_gallery = self::get_gallery_ids( $product_id );
		$gallery_count   = count( $current_gallery );

		$needs_featured = $fill_featured && ! $has_featured;
		$needs_gallery  = $fill_gallery  && ( $gallery_count < $min_gallery );

		if ( ! $needs_featured && ! $needs_gallery ) {
			update_post_meta( $product_id, self::META_DONE, 'complete' );
			Stats::increment( 'skipped_already_has' );
			self::finish_product( $product_id, $job_id, 'skipped' );
			return;
		}

		update_post_meta( $product_id, self::META_ATTEMPTS, $attempts + 1 );
		update_post_meta( $product_id, self::META_LAST_TRY, time() );

		// Υπολογισμός πόσες εικόνες χρειαζόμαστε
		$images_needed = 0;
		if ( $needs_featured ) $images_needed++;
		if ( $needs_gallery  ) $images_needed += max( 0, $gallery_target - $gallery_count );
		$images_needed = max( 1, $images_needed );

		// Αναζήτηση
		$query = self::build_query( $sku, $title, $settings );
		Logger::info([ 'action' => 'process', 'product_id' => $product_id, 'sku' => $sku,
			'message' => "Attempt " . ($attempts+1) . ": '{$query}' — χρειάζομαι {$images_needed} εικόνες." ]);

		$candidates = Providers::search( $query, $settings, $images_needed );
		$candidates = Providers::filter_candidates( $candidates, $settings );

		// Fallback: μόνο SKU
		if ( count( $candidates ) < $images_needed ) {
			$fallback_q    = '"' . $sku . '"';
			$more          = Providers::search( $fallback_q, $settings, $images_needed );
			$more          = Providers::filter_candidates( $more, $settings );
			// Merge, αποφύγαμε διπλά URLs
			$existing_urls = array_column( $candidates, 'url' );
			foreach ( $more as $m ) {
				if ( ! in_array( $m['url'], $existing_urls, true ) ) {
					$candidates[]    = $m;
					$existing_urls[] = $m['url'];
				}
			}
		}

		if ( empty( $candidates ) ) {
			self::finish_product( $product_id, $job_id, 'error', 'Δεν βρέθηκαν κατάλληλες εικόνες.' );
			return;
		}

		Logger::debug([ 'action' => 'candidates', 'product_id' => $product_id, 'sku' => $sku,
			'message' => count($candidates) . ' candidates έτοιμα για download.' ]);

		// Download εικόνων
		$downloaded_ids  = [];
		$max_tries       = (int) ( $settings['max_download_tries'] ?? 8 );

		foreach ( $candidates as $idx => $c ) {
			if ( count( $downloaded_ids ) >= $images_needed ) break;
			if ( $idx >= $max_tries ) break;

			$url = (string) ( $c['url'] ?? '' );
			if ( empty( $url ) ) continue;

			$attach_id = self::sideload( $url, $product_id, $sku );
			if ( $attach_id ) {
				$downloaded_ids[] = $attach_id;
				Logger::debug([ 'action' => 'sideload_ok', 'product_id' => $product_id, 'sku' => $sku,
					'image_url' => $url, 'message' => "Attachment #{$attach_id} αποθηκεύτηκε." ]);
			} else {
				Logger::debug([ 'action' => 'sideload_fail', 'product_id' => $product_id, 'sku' => $sku,
					'image_url' => $url, 'message' => 'Sideload απέτυχε, δοκιμάζω επόμενο.' ]);
			}
		}

		if ( empty( $downloaded_ids ) ) {
			self::finish_product( $product_id, $job_id, 'error', 'Αποτυχία download/sideload.' );
			return;
		}

		// Ανάθεση εικόνων
		$pool           = $downloaded_ids; // array of attachment IDs
		$featured_set   = false;
		$gallery_added  = 0;

		// 1. Featured image
		if ( $needs_featured && ! empty( $pool ) ) {
			$fid = array_shift( $pool );
			set_post_thumbnail( $product_id, $fid );
			$featured_set = true;
			Logger::info([ 'action' => 'featured_set', 'product_id' => $product_id, 'sku' => $sku,
				'message' => "Featured image ορίστηκε (#{$fid})." ]);
		}

		// 2. Gallery
		if ( $needs_gallery && ! empty( $pool ) ) {
			$new_gallery = array_merge( $current_gallery, $pool );
			// Αφαίρεση διπλών και cap στο gallery_target
			$new_gallery = array_unique( $new_gallery );
			$new_gallery = array_slice( $new_gallery, 0, $gallery_target );

			update_post_meta( $product_id, '_product_image_gallery', implode( ',', $new_gallery ) );
			$gallery_added = count( $pool );
			Logger::info([ 'action' => 'gallery_set', 'product_id' => $product_id, 'sku' => $sku,
				'message' => "{$gallery_added} εικόνες προστέθηκαν στο gallery (σύνολο: " . count($new_gallery) . ")." ]);
		}

		// Update stats
		if ( $featured_set )   Stats::increment( 'featured_set' );
		if ( $gallery_added )  Stats::increment( 'gallery_set' );
		if ( $featured_set && $gallery_added ) Stats::increment( 'both_set' );

		delete_post_meta( $product_id, self::META_LAST_ERR );
		update_post_meta( $product_id, self::META_DONE, 'complete' );

		self::finish_product( $product_id, $job_id, 'success' );
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Helpers
	// ──────────────────────────────────────────────────────────────────────────

	private static function build_query( string $sku, string $title, array $settings ) : string {
		$tpl = $settings['query_template'] ?? '"{sku}" {title}';
		$q   = str_replace( '{sku}', $sku, $tpl );
		$q   = str_replace( '{title}', ! empty( $settings['include_title'] ) ? $title : '', $q );
		return trim( preg_replace( '/\s+/', ' ', $q ) );
	}

	private static function get_gallery_ids( int $product_id ) : array {
		$meta = get_post_meta( $product_id, '_product_image_gallery', true );
		if ( empty( $meta ) ) return [];
		$ids = array_filter( array_map( 'absint', explode( ',', $meta ) ) );
		return array_values( $ids );
	}

	private static function sideload( string $url, int $product_id, string $sku ) : int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			Logger::debug([ 'action' => 'download_url', 'product_id' => $product_id, 'sku' => $sku,
				'image_url' => $url, 'message' => 'download_url: ' . $tmp->get_error_message() ]);
			return 0;
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		$ext  = $path ? strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) : 'jpg';
		if ( ! in_array( $ext, [ 'jpg', 'jpeg', 'png', 'webp', 'gif', 'avif' ], true ) ) {
			$ext = 'jpg';
		}

		$file_array = [
			'name'     => 'gtm-' . sanitize_file_name( $sku ) . '-' . wp_rand( 100, 999 ) . '.' . $ext,
			'tmp_name' => $tmp,
		];

		$attach_id = media_handle_sideload( $file_array, $product_id, 'GTM image for SKU: ' . $sku );

		if ( file_exists( $tmp ) ) @unlink( $tmp );

		if ( is_wp_error( $attach_id ) ) {
			Logger::debug([ 'action' => 'media_handle_sideload', 'product_id' => $product_id, 'sku' => $sku,
				'image_url' => $url, 'message' => $attach_id->get_error_message() ]);
			return 0;
		}

		return (int) $attach_id;
	}

	private static function finish_product( int $product_id, string $job_id, string $outcome, string $error_msg = '' ) : void {
		Stats::increment( 'total_processed' );

		if ( 'error' === $outcome ) {
			Stats::increment( 'errors' );
			update_post_meta( $product_id, self::META_LAST_ERR, wp_strip_all_tags( $error_msg ) );
			Logger::error([ 'action' => 'finish', 'product_id' => $product_id, 'message' => $error_msg ]);
		}

		// Ανανέωση current_job_processed
		$stats = Stats::get();
		if ( $stats['current_job_id'] === $job_id ) {
			$processed = (int) $stats['current_job_processed'] + 1;
			$total     = (int) $stats['current_job_total'];
			$is_done   = $processed >= $total;

			Stats::update([
				'current_job_processed' => $processed,
				'current_job_status'    => $is_done ? 'finished' : 'running',
				'last_run_finished'     => $is_done ? current_time( 'mysql' ) : null,
			]);
		}
	}

	// ──────────────────────────────────────────────────────────────────────────
	// AJAX endpoints
	// ──────────────────────────────────────────────────────────────────────────

	public static function ajax_progress() : void {
		check_ajax_referer( 'gtmif_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '', 403 );

		$stats = Stats::get();
		$live  = Stats::live_counts();

		// Pending στο Action Scheduler
		$pending = 0;
		$running = 0;
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$pending = count( as_get_scheduled_actions([
				'group'    => 'gtmif',
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			]) );
			$running = count( as_get_scheduled_actions([
				'group'    => 'gtmif',
				'status'   => \ActionScheduler_Store::STATUS_RUNNING,
				'per_page' => -1,
			]) );
		}

		wp_send_json_success([
			'stats'   => $stats,
			'live'    => $live,
			'pending' => $pending,
			'running' => $running,
		]);
	}

	public static function ajax_pause() : void {
		check_ajax_referer( 'gtmif_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '', 403 );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION_PROCESS, [], 'gtmif' );
		}
		Stats::update([ 'current_job_status' => 'paused' ]);
		wp_send_json_success([ 'message' => 'Job παυσαρισμένο.' ]);
	}

	public static function ajax_resume() : void {
		check_ajax_referer( 'gtmif_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '', 403 );

		// Re-enqueue unprocessed products of current job
		$stats  = Stats::get();
		$job_id = $stats['current_job_id'];

		if ( ! $job_id ) {
			wp_send_json_error([ 'message' => 'Δεν υπάρχει ενεργό job.' ]);
			return;
		}

		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key = %s AND meta_value = %s",
			self::META_JOB_ID,
			$job_id
		) );

		$requeued = 0;
		foreach ( $ids as $pid ) {
			$pid = absint( $pid );
			if ( ! $pid ) continue;
			$done = get_post_meta( $pid, self::META_DONE, true );
			if ( 'complete' === $done ) continue;
			self::enqueue_single( $pid, $job_id );
			$requeued++;
		}

		Stats::update([ 'current_job_status' => 'running' ]);
		wp_send_json_success([ 'message' => "{$requeued} προϊόντα επαναπρογραμματίστηκαν." ]);
	}
}
