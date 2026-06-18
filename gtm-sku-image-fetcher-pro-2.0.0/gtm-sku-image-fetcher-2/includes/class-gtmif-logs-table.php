<?php
namespace GTMIF;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Logs_Table extends \WP_List_Table {

	public function __construct() {
		parent::__construct([
			'singular' => 'log',
			'plural'   => 'logs',
			'ajax'     => false,
		]);
	}

	public function get_columns() : array {
		return [
			'created_at' => 'Χρόνος',
			'level'      => 'Level',
			'product_id' => 'Προϊόν',
			'sku'        => 'SKU',
			'action'     => 'Action',
			'message'    => 'Μήνυμα',
		];
	}

	public function prepare_items() : void {
		global $wpdb;
		$table = Logger::table_name();

		$per_page  = 50;
		$page      = $this->get_pagenum();
		$offset    = ( $page - 1 ) * $per_page;

		$level      = sanitize_key( $_GET['level']      ?? '' );
		$product_id = absint( $_GET['product_id']       ?? 0 );
		$sku        = sanitize_text_field( $_GET['sku'] ?? '' );

		$where = '1=1';
		$args  = [];
		if ( $level )      { $where .= ' AND level = %s';      $args[] = $level; }
		if ( $product_id ) { $where .= ' AND product_id = %d'; $args[] = $product_id; }
		if ( $sku )        { $where .= ' AND sku LIKE %s';      $args[] = '%' . $wpdb->esc_like( $sku ) . '%'; }

		$base_sql = "FROM {$table} WHERE {$where}";
		$total    = (int) ( $args
			? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$base_sql}", ...$args ) )
			: $wpdb->get_var( "SELECT COUNT(*) {$base_sql}" ) );

		$data_sql = "SELECT * {$base_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$data_args = array_merge( $args, [ $per_page, $offset ] );
		$this->items = $wpdb->get_results( $wpdb->prepare( $data_sql, ...$data_args ), ARRAY_A );

		$this->set_pagination_args([
			'total_items' => $total,
			'per_page'    => $per_page,
			'total_pages' => ceil( $total / $per_page ),
		]);

		$this->_column_headers = [ $this->get_columns(), [], [] ];
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'created_at':
				return esc_html( $item['created_at'] );
			case 'level':
				$colors = [ 'debug' => '#888', 'info' => '#0073aa', 'error' => '#d63638' ];
				$c = $colors[ $item['level'] ] ?? '#000';
				return '<span style="color:' . $c . ';font-weight:bold">' . esc_html( strtoupper( $item['level'] ) ) . '</span>';
			case 'product_id':
				if ( $item['product_id'] ) {
					return '<a href="' . esc_url( get_edit_post_link( $item['product_id'] ) ) . '">#' . esc_html( $item['product_id'] ) . '</a>';
				}
				return '—';
			case 'sku':
				return esc_html( $item['sku'] ?? '—' );
			case 'action':
				return '<code>' . esc_html( $item['action'] ?? '' ) . '</code>';
			case 'message':
				$msg = esc_html( $item['message'] ?? '' );
				return '<span title="' . $msg . '">' . wp_html_excerpt( $msg, 120 ) . '</span>';
			default:
				return esc_html( $item[ $column_name ] ?? '' );
		}
	}

	public function no_items() : void {
		echo 'Δεν υπάρχουν logs.';
	}
}
