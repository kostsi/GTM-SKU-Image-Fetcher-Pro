<?php
namespace GTMIF;

if ( ! defined( 'ABSPATH' ) ) exit;

class Logger {

	public static function init() : void {
		add_action( 'gtmif_cleanup_logs', [ __CLASS__, 'cleanup' ] );
		if ( ! wp_next_scheduled( 'gtmif_cleanup_logs' ) ) {
			wp_schedule_event( time() + 3600, 'daily', 'gtmif_cleanup_logs' );
		}
		if ( ! self::ensure_table() ) {
			add_action( 'admin_notices', [ __CLASS__, 'admin_notice_missing_table' ] );
		}
	}

	public static function table_name() : string {
		global $wpdb;
		return $wpdb->prefix . 'gtmif_logs';
	}

	public static function install() : void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table_name();
		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			level VARCHAR(10) NOT NULL DEFAULT 'info',
			product_id BIGINT(20) UNSIGNED NULL,
			sku VARCHAR(191) NULL,
			action VARCHAR(50) NULL,
			image_url TEXT NULL,
			message TEXT NULL,
			PRIMARY KEY  (id),
			KEY level (level),
			KEY product_id (product_id),
			KEY created_at (created_at)
		) {$charset};";
		dbDelta( $sql );
	}

	public static function uninstall() : void {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS " . self::table_name() );
		$ts = wp_next_scheduled( 'gtmif_cleanup_logs' );
		if ( $ts ) wp_unschedule_event( $ts, 'gtmif_cleanup_logs' );
	}

	public static function ensure_table() : bool {
		global $wpdb;
		$table  = self::table_name();
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
		if ( $exists === $table ) return true;
		self::install();
		return ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) === $table );
	}

	public static function admin_notice_missing_table() : void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) return;
		echo '<div class="notice notice-error"><p><strong>GTM SKU Image Fetcher:</strong> Ο πίνακας logs δεν υπάρχει. Ο χρήστης DB ίσως δεν έχει δικαίωμα CREATE.</p></div>';
	}

	private static function allowed_level( string $level ) : bool {
		$settings = Settings::get();
		if ( empty( $settings['logging_enabled'] ) ) return false;
		$order = [ 'debug' => 0, 'info' => 1, 'error' => 2 ];
		$min   = $settings['log_level'] ?? 'info';
		$level = $order[ $level ] ?? 1;
		$min   = $order[ $min ]   ?? 1;
		return $level >= $min;
	}

	public static function log( string $level, array $ctx = [] ) : void {
		if ( ! self::allowed_level( $level ) ) return;
		global $wpdb;
		$wpdb->insert(
			self::table_name(),
			[
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'level'      => $level,
				'product_id' => isset( $ctx['product_id'] ) ? absint( $ctx['product_id'] ) : null,
				'sku'        => isset( $ctx['sku'] ) ? sanitize_text_field( (string) $ctx['sku'] ) : null,
				'action'     => isset( $ctx['action'] ) ? sanitize_text_field( (string) $ctx['action'] ) : null,
				'image_url'  => isset( $ctx['image_url'] ) ? esc_url_raw( (string) $ctx['image_url'] ) : null,
				'message'    => isset( $ctx['message'] ) ? wp_strip_all_tags( (string) $ctx['message'] ) : null,
			],
			[ '%s','%s','%d','%s','%s','%s','%s' ]
		);
	}

	public static function debug( array $ctx = [] ) : void { self::log( 'debug', $ctx ); }
	public static function info( array $ctx = [] ) : void  { self::log( 'info',  $ctx ); }
	public static function error( array $ctx = [] ) : void { self::log( 'error', $ctx ); }

	public static function cleanup() : void {
		global $wpdb;
		$settings = Settings::get();
		$days     = absint( $settings['log_retention_days'] ?? 30 );
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM " . self::table_name() . " WHERE created_at < (UTC_TIMESTAMP() - INTERVAL %d DAY)",
			$days
		) );
	}

	public static function clear_all() : void {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE " . self::table_name() );
	}

	public static function recent( int $limit = 20, string $level = '' ) : array {
		global $wpdb;
		$table = self::table_name();
		if ( $level ) {
			return $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE level = %s ORDER BY id DESC LIMIT %d",
				$level, $limit
			), ARRAY_A );
		}
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit
		), ARRAY_A );
	}
}
