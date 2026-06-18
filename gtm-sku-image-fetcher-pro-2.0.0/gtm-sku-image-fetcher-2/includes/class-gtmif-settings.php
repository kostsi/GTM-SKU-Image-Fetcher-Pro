<?php
namespace GTMIF;

if ( ! defined( 'ABSPATH' ) ) exit;

class Settings {

	const OPTION = 'gtmif_settings';

	public static function init() : void {
		add_action( 'admin_init', [ __CLASS__, 'register' ] );
	}

	public static function defaults() : array {
		return [
			// Provider
			'google_api_key'        => '',
			'google_cx'             => '',

			// Search behaviour
			'query_template'        => '"{sku}" {title}',
			'include_title'         => 1,
			'only_instock'          => 0,

			// What to fill
			'fill_featured'         => 1,  // set featured image if missing
			'fill_gallery'          => 1,  // fill gallery up to gallery_target
			'gallery_target'        => 3,  // how many gallery images to aim for (min 3)
			'skip_if_has_featured'  => 0,  // skip product entirely if featured already set
			'min_gallery_count'     => 3,  // minimum gallery images required before skip

			// Image quality
			'allowed_domains'       => '',
			'min_width'             => 200,
			'min_height'            => 200,
			'max_results'           => 10,
			'max_download_tries'    => 8,

			// Processing
			'skip_if_attempted'     => 1,
			'max_attempts'          => 3,
			'request_timeout'       => 25,
			'user_agent'            => 'Mozilla/5.0 (compatible; GTMobiles-ImageBot/2.0; +https://gtmobiles.gr)',

			// Logging
			'logging_enabled'       => 1,
			'log_retention_days'    => 30,
			'log_level'             => 'info',
		];
	}

	public static function get() : array {
		$opt = get_option( self::OPTION, [] );
		return wp_parse_args( is_array( $opt ) ? $opt : [], self::defaults() );
	}

	public static function register() : void {
		register_setting(
			'gtmif_settings_group',
			self::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ __CLASS__, 'sanitize' ],
				'default'           => self::defaults(),
			]
		);
	}

	public static function sanitize( $input ) : array {
		$d   = self::defaults();
		$out = [];

		$out['google_api_key']       = sanitize_text_field( $input['google_api_key'] ?? '' );
		$out['google_cx']            = sanitize_text_field( $input['google_cx'] ?? '' );
		$out['query_template']       = sanitize_text_field( $input['query_template'] ?? $d['query_template'] );
		$out['include_title']        = ! empty( $input['include_title'] ) ? 1 : 0;
		$out['only_instock']         = ! empty( $input['only_instock'] ) ? 1 : 0;
		$out['fill_featured']        = ! empty( $input['fill_featured'] ) ? 1 : 0;
		$out['fill_gallery']         = ! empty( $input['fill_gallery'] ) ? 1 : 0;
		$out['gallery_target']       = max( 3, min( 10, absint( $input['gallery_target'] ?? $d['gallery_target'] ) ) );
		$out['skip_if_has_featured'] = ! empty( $input['skip_if_has_featured'] ) ? 1 : 0;
		$out['min_gallery_count']    = max( 1, min( 10, absint( $input['min_gallery_count'] ?? $d['min_gallery_count'] ) ) );
		$out['allowed_domains']      = sanitize_text_field( $input['allowed_domains'] ?? '' );
		$out['min_width']            = max( 0, absint( $input['min_width'] ?? $d['min_width'] ) );
		$out['min_height']           = max( 0, absint( $input['min_height'] ?? $d['min_height'] ) );
		$out['max_results']          = min( 10, max( 1, absint( $input['max_results'] ?? $d['max_results'] ) ) );
		$out['max_download_tries']   = min( 10, max( 1, absint( $input['max_download_tries'] ?? $d['max_download_tries'] ) ) );
		$out['skip_if_attempted']    = ! empty( $input['skip_if_attempted'] ) ? 1 : 0;
		$out['max_attempts']         = min( 10, max( 1, absint( $input['max_attempts'] ?? $d['max_attempts'] ) ) );
		$out['request_timeout']      = min( 60, max( 5, absint( $input['request_timeout'] ?? $d['request_timeout'] ) ) );
		$out['user_agent']           = sanitize_text_field( $input['user_agent'] ?? $d['user_agent'] );
		$out['logging_enabled']      = ! empty( $input['logging_enabled'] ) ? 1 : 0;
		$out['log_retention_days']   = min( 365, max( 1, absint( $input['log_retention_days'] ?? $d['log_retention_days'] ) ) );
		$out['log_level']            = in_array( $input['log_level'] ?? '', [ 'debug', 'info', 'error' ], true ) ? $input['log_level'] : $d['log_level'];

		return wp_parse_args( $out, $d );
	}
}
