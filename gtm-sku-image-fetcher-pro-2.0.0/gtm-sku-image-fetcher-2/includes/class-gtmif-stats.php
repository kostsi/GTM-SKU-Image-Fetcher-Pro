<?php
namespace GTMIF;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Persistent statistics: μετράει αποτελέσματα ανεξάρτητα από logs.
 * Αποθηκεύεται σε wp_options για απλότητα και ταχύτητα.
 */
class Stats {

	const OPTION = 'gtmif_stats';

	public static function install() : void {
		$defaults = self::defaults();
		if ( false === get_option( self::OPTION ) ) {
			update_option( self::OPTION, $defaults, false );
		}
	}

	public static function uninstall() : void {
		delete_option( self::OPTION );
	}

	private static function defaults() : array {
		return [
			'total_queued'           => 0,
			'total_processed'        => 0,
			'featured_set'           => 0,
			'gallery_set'            => 0,
			'both_set'               => 0,
			'errors'                 => 0,
			'skipped_already_has'    => 0,
			'last_run_started'       => null,
			'last_run_finished'      => null,
			'current_job_id'         => null,
			'current_job_total'      => 0,
			'current_job_processed'  => 0,
			'current_job_status'     => 'idle', // idle | running | finished | paused
		];
	}

	public static function get() : array {
		$data = get_option( self::OPTION, [] );
		return wp_parse_args( is_array( $data ) ? $data : [], self::defaults() );
	}

	public static function update( array $fields ) : void {
		$current = self::get();
		$updated = array_merge( $current, $fields );
		update_option( self::OPTION, $updated, false );
	}

	public static function increment( string $key, int $by = 1 ) : void {
		$current = self::get();
		$current[ $key ] = ( (int) ( $current[ $key ] ?? 0 ) ) + $by;
		update_option( self::OPTION, $current, false );
	}

	public static function reset_job() : void {
		self::update([
			'current_job_id'        => null,
			'current_job_total'     => 0,
			'current_job_processed' => 0,
			'current_job_status'    => 'idle',
		]);
	}

	/**
	 * Live counts straight from DB — για το dashboard.
	 */
	public static function live_counts() : array {
		global $wpdb;

		// Σύνολο published products
		$total_products = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish'"
		);

		// Χωρίς featured image
		$no_featured = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 WHERE p.post_type='product' AND p.post_status='publish'
			 AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm
				WHERE pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id'
			 )"
		);

		// Χωρίς gallery ή gallery < 3 φωτογραφίες
		$no_gallery = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 WHERE p.post_type='product' AND p.post_status='publish'
			 AND (
				NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm
					WHERE pm.post_id = p.ID AND pm.meta_key = '_product_image_gallery'
					AND pm.meta_value != ''
				)
				OR (
					SELECT CHAR_LENGTH(pm2.meta_value) - CHAR_LENGTH(REPLACE(pm2.meta_value, ',', ''))
					FROM {$wpdb->postmeta} pm2
					WHERE pm2.post_id = p.ID AND pm2.meta_key = '_product_image_gallery'
					LIMIT 1
				) < 2
			 )"
		);

		// Χρειάζονται δουλειά (δεν έχουν ΕΙΤΕ featured ΕΙΤΕ gallery 3+)
		$needs_work = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 WHERE p.post_type='product' AND p.post_status='publish'
			 AND (
				NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm
					WHERE pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id'
				)
				OR NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm2
					WHERE pm2.post_id = p.ID AND pm2.meta_key = '_product_image_gallery'
					AND pm2.meta_value != ''
				)
				OR (
					SELECT CHAR_LENGTH(pm3.meta_value) - CHAR_LENGTH(REPLACE(pm3.meta_value, ',', ''))
					FROM {$wpdb->postmeta} pm3
					WHERE pm3.post_id = p.ID AND pm3.meta_key = '_product_image_gallery'
					LIMIT 1
				) < 2
			 )"
		);

		// Pending στο Action Scheduler
		$pending_as = 0;
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$pending_as = count( as_get_scheduled_actions([
				'group'  => 'gtmif',
				'status' => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			]) );
			$running_as = count( as_get_scheduled_actions([
				'group'  => 'gtmif',
				'status' => \ActionScheduler_Store::STATUS_RUNNING,
				'per_page' => -1,
			]) );
		} else {
			$running_as = 0;
		}

		return compact( 'total_products', 'no_featured', 'no_gallery', 'needs_work', 'pending_as', 'running_as' );
	}
}
