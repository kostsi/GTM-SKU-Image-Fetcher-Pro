<?php
namespace GTMIF;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Image search provider — Google Custom Search Image API.
 * Επιστρέφει array από candidates: [ url, width, height, source, context_url ]
 */
class Providers {

	public static function init() : void {}

	/**
	 * Κάνει αναζήτηση εικόνων και επιστρέφει candidates.
	 * Αυτόματα κάνει έως 2 pages (start=1, start=11) για να πάρει 20 αποτελέσματα.
	 *
	 * @param string $query
	 * @param array  $settings
	 * @param int    $need     Πόσες εικόνες χρειαζόμαστε συνολικά
	 * @return array
	 */
	public static function search( string $query, array $settings, int $need = 1 ) : array {
		$key = $settings['google_api_key'] ?? '';
		$cx  = $settings['google_cx']      ?? '';

		if ( empty( $key ) || empty( $cx ) ) {
			Logger::error([ 'action' => 'search', 'message' => 'Google API Key ή CX λείπει από τις ρυθμίσεις.' ]);
			return [];
		}

		$all_candidates = [];

		// Google Custom Search επιτρέπει max 10 ανά αίτημα, start=1 ή start=11
		$pages_needed = (int) ceil( $need / 10 );
		$pages_needed = min( $pages_needed, 2 ); // max 2 pages = 20 results

		for ( $page = 0; $page < $pages_needed; $page++ ) {
			$start = ( $page * 10 ) + 1;

			$params = [
				'key'        => $key,
				'cx'         => $cx,
				'q'          => $query,
				'searchType' => 'image',
				'num'        => 10,
				'start'      => $start,
				'safe'       => 'active',
				'imgSize'    => 'large', // προτιμάμε μεγάλες εικόνες
			];

			$url  = add_query_arg( $params, 'https://www.googleapis.com/customsearch/v1' );
			$args = [
				'timeout' => (int) ( $settings['request_timeout'] ?? 25 ),
				'headers' => [
					'Accept'     => 'application/json',
					'User-Agent' => $settings['user_agent'] ?? 'GTMobiles-ImageBot/2.0',
				],
			];

			$response = wp_remote_get( $url, $args );

			if ( is_wp_error( $response ) ) {
				Logger::error([ 'action' => 'search_google', 'message' => 'HTTP error: ' . $response->get_error_message() ]);
				break;
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				$raw  = wp_remote_retrieve_body( $response );
				$data = json_decode( $raw, true );
				$msg  = is_array( $data ) ? ( $data['error']['message'] ?? substr( $raw, 0, 300 ) ) : substr( $raw, 0, 300 );
				Logger::error([ 'action' => 'search_google', 'message' => "Non-200 ({$code}): {$msg}" ]);
				break;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( ! is_array( $data ) || empty( $data['items'] ) ) {
				Logger::debug([ 'action' => 'search_google', 'message' => "Page {$page}: no items." ]);
				break;
			}

			foreach ( $data['items'] as $item ) {
				$link = $item['link'] ?? '';
				if ( empty( $link ) ) continue;

				$w       = (int) ( $item['image']['width']  ?? 0 );
				$h       = (int) ( $item['image']['height'] ?? 0 );
				$context = isset( $item['image']['contextLink'] ) ? (string) $item['image']['contextLink'] : '';

				$all_candidates[] = [
					'url'         => esc_url_raw( $link ),
					'width'       => $w,
					'height'      => $h,
					'source'      => 'google_cse',
					'context_url' => esc_url_raw( $context ),
				];

				// thumbnail ως fallback
				$thumb = $item['image']['thumbnailLink'] ?? '';
				if ( ! empty( $thumb ) ) {
					$all_candidates[] = [
						'url'         => esc_url_raw( (string) $thumb ),
						'width'       => (int) ( $item['image']['thumbnailWidth']  ?? 0 ),
						'height'      => (int) ( $item['image']['thumbnailHeight'] ?? 0 ),
						'source'      => 'google_cse_thumb',
						'context_url' => esc_url_raw( $context ),
					];
				}
			}

			// Αν υπάρχουν αρκετά candidates, σταμάτα
			if ( count( $all_candidates ) >= $need * 3 ) break;
		}

		return $all_candidates;
	}

	/**
	 * Φιλτράρει candidates βάσει min size και allowed domains.
	 */
	public static function filter_candidates( array $candidates, array $settings ) : array {
		$minw = (int) ( $settings['min_width']  ?? 0 );
		$minh = (int) ( $settings['min_height'] ?? 0 );

		$allowed_domains = array_filter(
			array_map( 'trim', explode( ',', (string) ( $settings['allowed_domains'] ?? '' ) ) )
		);
		$allowed_domains = array_map( 'strtolower', $allowed_domains );

		// Μην κάνεις download thumbnails αν υπάρχουν full-size candidates
		$has_full = false;
		foreach ( $candidates as $c ) {
			if ( $c['source'] === 'google_cse' ) { $has_full = true; break; }
		}

		$out = [];
		foreach ( $candidates as $c ) {
			$url = $c['url'] ?? '';
			if ( empty( $url ) ) continue;

			// Προτεραιότητα: πρώτα full-size, μετά thumbs
			if ( $has_full && $c['source'] === 'google_cse_thumb' ) continue;

			$w = (int) ( $c['width']  ?? 0 );
			$h = (int) ( $c['height'] ?? 0 );
			if ( $w && $h ) {
				if ( $w < $minw || $h < $minh ) continue;
			}

			if ( ! empty( $allowed_domains ) ) {
				$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
				$ok   = false;
				foreach ( $allowed_domains as $d ) {
					if ( $host === $d || str_ends_with( $host, '.' . $d ) ) { $ok = true; break; }
				}
				if ( ! $ok ) continue;
			}

			$out[] = $c;
		}

		// Ταξινόμηση κατά μέγεθος (μεγαλύτερη πρώτη)
		usort( $out, function( $a, $b ) {
			return ( (int)($b['width']??0) * (int)($b['height']??0) )
				 - ( (int)($a['width']??0) * (int)($a['height']??0) );
		});

		return $out;
	}
}
