<?php
namespace GTMIF;

if ( ! defined( 'ABSPATH' ) ) exit;

class Admin {

	public static function init() : void {
		add_action( 'admin_menu',   [ __CLASS__, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
		add_filter( 'bulk_actions-edit-product',        [ __CLASS__, 'register_bulk_action' ] );
		add_filter( 'handle_bulk_actions-edit-product', [ __CLASS__, 'handle_bulk_action' ], 10, 3 );
		add_action( 'admin_notices', [ __CLASS__, 'notices' ] );

		// AJAX: queue από admin page
		add_action( 'wp_ajax_gtmif_queue', [ __CLASS__, 'ajax_queue' ] );
	}

	public static function menu() : void {
		$parent_slug = 'aikostsi-main';
		// Fallback αν δεν υπάρχει το parent menu
		add_menu_page(
			'GTM Image Fetcher',
			'GTM Image Fetcher',
			'manage_woocommerce',
			'gtmif',
			[ __CLASS__, 'page' ],
			'dashicons-format-image',
			58
		);
		// Αν υπάρχει το parent, προσθέτουμε και εκεί
		add_action( 'admin_menu', function() use ( $parent_slug ) {
			if ( menu_page_url( $parent_slug, false ) ) {
				add_submenu_page(
					$parent_slug,
					'SKU Image Fetcher Pro',
					'SKU Image Fetcher',
					'manage_woocommerce',
					'gtmif',
					[ __CLASS__, 'page' ]
				);
			}
		}, 20 );
	}

	public static function enqueue_scripts( string $hook ) : void {
		if ( strpos( $hook, 'gtmif' ) === false ) return;

		wp_enqueue_style(
			'gtmif-admin',
			GTMIF_PLUGIN_URL . 'assets/admin.css',
			[],
			GTMIF_VERSION
		);

		wp_enqueue_script(
			'gtmif-admin',
			GTMIF_PLUGIN_URL . 'assets/admin.js',
			[ 'jquery' ],
			GTMIF_VERSION,
			true
		);

		wp_localize_script( 'gtmif-admin', 'GTMIF', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'gtmif_ajax' ),
			'i18n'     => [
				'running'  => 'Τρέχει...',
				'paused'   => 'Παυσαρισμένο',
				'finished' => 'Ολοκληρώθηκε',
				'idle'     => 'Αδρανές',
				'confirm_reset' => 'Να γίνει επαναφορά όλων των στατιστικών;',
			],
		]);
	}

	private static function tab() : string {
		$tab = sanitize_key( $_GET['tab'] ?? 'dashboard' );
		return in_array( $tab, [ 'dashboard', 'settings', 'logs' ], true ) ? $tab : 'dashboard';
	}

	public static function page() : void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) return;

		// Handle form posts
		if ( isset( $_POST['gtmif_clear_logs'] ) && check_admin_referer( 'gtmif_clear_logs' ) ) {
			Logger::clear_all();
			wp_safe_redirect( add_query_arg( [ 'page' => 'gtmif', 'tab' => 'logs', 'cleared' => 1 ], admin_url( 'admin.php' ) ) );
			exit;
		}
		if ( isset( $_POST['gtmif_reset_stats'] ) && check_admin_referer( 'gtmif_reset_stats' ) ) {
			Stats::install(); // re-installs defaults
			delete_option( Stats::OPTION );
			Stats::install();
			wp_safe_redirect( add_query_arg( [ 'page' => 'gtmif', 'tab' => 'dashboard', 'stats_reset' => 1 ], admin_url( 'admin.php' ) ) );
			exit;
		}

		$tab      = self::tab();
		$settings = Settings::get();
		$stats    = Stats::get();
		$live     = Stats::live_counts();

		?>
		<div class="wrap gtmif-wrap">
			<h1>
				<span class="dashicons dashicons-format-image" style="font-size:28px;vertical-align:middle;margin-right:8px;color:#0073aa"></span>
				GTM SKU Image Fetcher Pro
				<span class="gtmif-version">v<?php echo esc_html( GTMIF_VERSION ); ?></span>
			</h1>

			<nav class="nav-tab-wrapper gtmif-tabs">
				<?php
				$tabs = [ 'dashboard' => '📊 Dashboard', 'settings' => '⚙️ Ρυθμίσεις', 'logs' => '📋 Logs' ];
				foreach ( $tabs as $slug => $label ) {
					$active = $tab === $slug ? ' nav-tab-active' : '';
					$url    = admin_url( "admin.php?page=gtmif&tab={$slug}" );
					echo "<a class=\"nav-tab{$active}\" href=\"" . esc_url($url) . "\">{$label}</a>";
				}
				?>
			</nav>

			<?php if ( isset( $_GET['stats_reset'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>✅ Στατιστικά μηδενίστηκαν.</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['cleared'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>✅ Logs διαγράφηκαν.</p></div>
			<?php endif; ?>

			<div class="gtmif-content">
			<?php
			switch ( $tab ) {
				case 'dashboard': self::tab_dashboard( $stats, $live, $settings ); break;
				case 'settings':  self::tab_settings( $settings ); break;
				case 'logs':      self::tab_logs(); break;
			}
			?>
			</div>
		</div>
		<?php
	}

	// ──────────────────────────────────────────────────────────────────────────
	// DASHBOARD TAB
	// ──────────────────────────────────────────────────────────────────────────

	private static function tab_dashboard( array $stats, array $live, array $settings ) : void {
		$job_status    = $stats['current_job_status'] ?? 'idle';
		$job_processed = (int) ( $stats['current_job_processed'] ?? 0 );
		$job_total     = (int) ( $stats['current_job_total'] ?? 0 );
		$progress_pct  = $job_total > 0 ? min( 100, round( $job_processed / $job_total * 100 ) ) : 0;
		?>

		<!-- ───── Live Status Banner ───── -->
		<div class="gtmif-status-banner gtmif-status-<?php echo esc_attr( $job_status ); ?>" id="gtmif-status-banner">
			<div class="gtmif-status-icon" id="gtmif-status-icon">
				<?php
				$icons = [ 'running' => '⚡', 'paused' => '⏸', 'finished' => '✅', 'idle' => '💤' ];
				echo $icons[ $job_status ] ?? '💤';
				?>
			</div>
			<div class="gtmif-status-text">
				<strong id="gtmif-status-label"><?php echo esc_html( self::status_label( $job_status ) ); ?></strong>
				<span id="gtmif-status-detail">
					<?php if ( in_array( $job_status, [ 'running', 'paused', 'finished' ], true ) ) : ?>
						<?php echo esc_html( $job_processed ); ?> / <?php echo esc_html( $job_total ); ?> προϊόντα
					<?php endif; ?>
				</span>
			</div>
			<?php if ( 'running' === $job_status || 'paused' === $job_status ) : ?>
			<div class="gtmif-status-actions">
				<?php if ( 'running' === $job_status ) : ?>
					<button class="button" id="gtmif-btn-pause">⏸ Παύση</button>
				<?php else : ?>
					<button class="button button-primary" id="gtmif-btn-resume">▶ Συνέχεια</button>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>

		<!-- Progress bar -->
		<?php if ( in_array( $job_status, [ 'running', 'paused', 'finished' ], true ) ) : ?>
		<div class="gtmif-progress-wrap">
			<div class="gtmif-progress-bar" id="gtmif-progress-bar">
				<div class="gtmif-progress-fill" id="gtmif-progress-fill" style="width:<?php echo esc_attr( $progress_pct ); ?>%"></div>
			</div>
			<span class="gtmif-progress-pct" id="gtmif-progress-pct"><?php echo esc_html( $progress_pct ); ?>%</span>
		</div>
		<?php endif; ?>

		<!-- ───── KPI Cards ───── -->
		<div class="gtmif-cards" id="gtmif-cards">

			<div class="gtmif-card gtmif-card-blue">
				<div class="gtmif-card-value" id="stat-total-products"><?php echo esc_html( number_format( $live['total_products'] ) ); ?></div>
				<div class="gtmif-card-label">Σύνολο Προϊόντων</div>
			</div>

			<div class="gtmif-card gtmif-card-orange">
				<div class="gtmif-card-value" id="stat-no-featured"><?php echo esc_html( number_format( $live['no_featured'] ) ); ?></div>
				<div class="gtmif-card-label">Χωρίς Featured Image</div>
			</div>

			<div class="gtmif-card gtmif-card-orange">
				<div class="gtmif-card-value" id="stat-no-gallery"><?php echo esc_html( number_format( $live['no_gallery'] ) ); ?></div>
				<div class="gtmif-card-label">Gallery &lt; <?php echo esc_html( $settings['min_gallery_count'] ); ?> εικόνες</div>
			</div>

			<div class="gtmif-card gtmif-card-red">
				<div class="gtmif-card-value" id="stat-needs-work"><?php echo esc_html( number_format( $live['needs_work'] ) ); ?></div>
				<div class="gtmif-card-label">Χρειάζονται Εικόνες</div>
			</div>

			<div class="gtmif-card gtmif-card-green">
				<div class="gtmif-card-value" id="stat-featured-set"><?php echo esc_html( number_format( (int)($stats['featured_set']??0) ) ); ?></div>
				<div class="gtmif-card-label">Featured Images Προστέθηκαν</div>
			</div>

			<div class="gtmif-card gtmif-card-green">
				<div class="gtmif-card-value" id="stat-gallery-set"><?php echo esc_html( number_format( (int)($stats['gallery_set']??0) ) ); ?></div>
				<div class="gtmif-card-label">Gallery Sets Ολοκλήρωσαν</div>
			</div>

			<div class="gtmif-card gtmif-card-purple">
				<div class="gtmif-card-value" id="stat-total-queued"><?php echo esc_html( number_format( (int)($stats['total_queued']??0) ) ); ?></div>
				<div class="gtmif-card-label">Σύνολο στο Queue</div>
			</div>

			<div class="gtmif-card gtmif-card-red">
				<div class="gtmif-card-value" id="stat-errors"><?php echo esc_html( number_format( (int)($stats['errors']??0) ) ); ?></div>
				<div class="gtmif-card-label">Σφάλματα</div>
			</div>

			<div class="gtmif-card gtmif-card-gray">
				<div class="gtmif-card-value" id="stat-pending-as"><?php echo esc_html( number_format( (int)($live['pending_as']??0) ) ); ?></div>
				<div class="gtmif-card-label">Pending στο Action Scheduler</div>
			</div>

		</div>

		<!-- Τελευταία τρέξιμο -->
		<?php if ( $stats['last_run_started'] ) : ?>
		<p class="gtmif-meta" id="gtmif-last-run">
			Τελευταία εκκίνηση: <strong><?php echo esc_html( $stats['last_run_started'] ); ?></strong>
			<?php if ( $stats['last_run_finished'] ) : ?>
			— Τέλος: <strong><?php echo esc_html( $stats['last_run_finished'] ); ?></strong>
			<?php endif; ?>
		</p>
		<?php endif; ?>

		<!-- ───── Queue Form ───── -->
		<div class="gtmif-queue-box">
			<h2>🚀 Εκκίνηση Queue</h2>
			<p>Βάλε προϊόντα στο queue για εύρεση εικόνων. Τρέχει στο background — μπορείς να φύγεις από τη σελίδα.</p>

			<?php if ( empty( $settings['google_api_key'] ) || empty( $settings['google_cx'] ) ) : ?>
				<div class="notice notice-error inline"><p>⚠️ <strong>Δεν έχεις ορίσει Google API Key ή CX.</strong> Πήγαινε στις <a href="<?php echo esc_url( admin_url('admin.php?page=gtmif&tab=settings') ); ?>">Ρυθμίσεις</a>.</p></div>
			<?php else : ?>

			<div class="gtmif-queue-controls">
				<label>
					Ουρά για
					<input type="number" id="gtmif-queue-limit" min="1" max="2000" value="200" style="width:90px" />
					προϊόντα
				</label>
				<label>
					<input type="checkbox" id="gtmif-reset-done" />
					Επανεπεξεργασία (reset META_DONE)
				</label>
				<button class="button button-primary button-large" id="gtmif-btn-queue">▶ Έναρξη</button>
				<span id="gtmif-queue-feedback" style="margin-left:12px;color:#0073aa;display:none"></span>
			</div>

			<?php endif; ?>
		</div>

		<!-- Reset stats -->
		<div style="margin-top:30px;padding-top:16px;border-top:1px solid #ddd;">
			<form method="post">
				<?php wp_nonce_field( 'gtmif_reset_stats' ); ?>
				<button class="button" name="gtmif_reset_stats" value="1"
					onclick="return confirm('<?php echo esc_js( 'Να γίνει επαναφορά όλων των στατιστικών;' ); ?>')">
					🔄 Reset Στατιστικών
				</button>
			</form>
		</div>

		<script>
		// Auto-refresh κάθε 5 δευτερόλεπτα όταν τρέχει job
		(function($){
			var pollInterval = null;
			var isRunning = <?php echo json_encode( $job_status === 'running' ); ?>;

			function updateDashboard(data) {
				var s = data.stats, l = data.live;

				// Cards
				$('#stat-total-products').text(l.total_products.toLocaleString());
				$('#stat-no-featured').text(l.no_featured.toLocaleString());
				$('#stat-no-gallery').text(l.no_gallery.toLocaleString());
				$('#stat-needs-work').text(l.needs_work.toLocaleString());
				$('#stat-featured-set').text((s.featured_set||0).toLocaleString());
				$('#stat-gallery-set').text((s.gallery_set||0).toLocaleString());
				$('#stat-total-queued').text((s.total_queued||0).toLocaleString());
				$('#stat-errors').text((s.errors||0).toLocaleString());
				$('#stat-pending-as').text((data.pending||0).toLocaleString());

				// Status banner
				var status = s.current_job_status || 'idle';
				var icons  = {running:'⚡', paused:'⏸', finished:'✅', idle:'💤'};
				var labels = {running:'Τρέχει...', paused:'Παυσαρισμένο', finished:'Ολοκληρώθηκε', idle:'Αδρανές'};
				$('#gtmif-status-banner').attr('class', 'gtmif-status-banner gtmif-status-' + status);
				$('#gtmif-status-icon').text(icons[status]||'💤');
				$('#gtmif-status-label').text(labels[status]||'');

				var proc  = parseInt(s.current_job_processed)||0;
				var total = parseInt(s.current_job_total)||0;
				var pct   = total > 0 ? Math.min(100, Math.round(proc/total*100)) : 0;
				$('#gtmif-status-detail').text( total > 0 ? proc + ' / ' + total + ' προϊόντα' : '' );
				$('#gtmif-progress-fill').css('width', pct + '%');
				$('#gtmif-progress-pct').text(pct + '%');

				// Stop polling when done
				if ( status !== 'running' && pollInterval ) {
					clearInterval(pollInterval);
					pollInterval = null;
					isRunning = false;
				}
			}

			function poll() {
				$.post(GTMIF.ajax_url, {action:'gtmif_progress', nonce:GTMIF.nonce}, function(resp) {
					if (resp.success) updateDashboard(resp.data);
				});
			}

			if (isRunning) {
				pollInterval = setInterval(poll, 5000);
			}

			// Queue button
			$('#gtmif-btn-queue').on('click', function() {
				var limit  = parseInt($('#gtmif-queue-limit').val()) || 200;
				var reset  = $('#gtmif-reset-done').is(':checked') ? 1 : 0;
				var $btn   = $(this);
				var $fb    = $('#gtmif-queue-feedback');
				$btn.prop('disabled', true).text('Φορτώνει...');
				$fb.show().text('Προσθήκη στο queue...');

				$.post(GTMIF.ajax_url, {
					action: 'gtmif_queue',
					nonce:  GTMIF.nonce,
					limit:  limit,
					reset:  reset
				}, function(resp) {
					$btn.prop('disabled', false).text('▶ Έναρξη');
					if (resp.success) {
						$fb.text('✅ ' + resp.data.queued + ' προϊόντα στο queue!');
						if (resp.data.queued > 0) {
							if (!pollInterval) pollInterval = setInterval(poll, 5000);
							poll();
						}
					} else {
						$fb.text('❌ ' + (resp.data.message || 'Σφάλμα'));
					}
				});
			});

			// Pause
			$(document).on('click', '#gtmif-btn-pause', function() {
				$.post(GTMIF.ajax_url, {action:'gtmif_pause', nonce:GTMIF.nonce}, function(resp) {
					poll();
				});
			});

			// Resume
			$(document).on('click', '#gtmif-btn-resume', function() {
				$.post(GTMIF.ajax_url, {action:'gtmif_resume', nonce:GTMIF.nonce}, function(resp) {
					if (!pollInterval) pollInterval = setInterval(poll, 5000);
					poll();
				});
			});

		})(jQuery);
		</script>
		<?php
	}

	// ──────────────────────────────────────────────────────────────────────────
	// SETTINGS TAB
	// ──────────────────────────────────────────────────────────────────────────

	private static function tab_settings( array $settings ) : void {
		$o = Settings::OPTION;
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'gtmif_settings_group' ); ?>

			<h2>🔑 Google Custom Search API</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="google_api_key">API Key</label></th>
					<td>
						<input class="regular-text" type="password" name="<?php echo esc_attr($o); ?>[google_api_key]" id="google_api_key" value="<?php echo esc_attr( $settings['google_api_key'] ); ?>" autocomplete="off" />
						<p class="description">Απόκτησε στο <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a></p>
					</td>
				</tr>
				<tr>
					<th><label for="google_cx">Search Engine ID (CX)</label></th>
					<td>
						<input class="regular-text" name="<?php echo esc_attr($o); ?>[google_cx]" id="google_cx" value="<?php echo esc_attr( $settings['google_cx'] ); ?>" />
						<p class="description">Δημιούργησε στο <a href="https://programmablesearchengine.google.com/" target="_blank">Programmable Search Engine</a> (Image search ON, Search entire web)</p>
					</td>
				</tr>
			</table>

			<h2>🔍 Αναζήτηση</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="query_template">Query Template</label></th>
					<td>
						<input class="regular-text" name="<?php echo esc_attr($o); ?>[query_template]" id="query_template" value="<?php echo esc_attr( $settings['query_template'] ); ?>" />
						<p class="description">Χρήση <code>{sku}</code> και <code>{title}</code>. Π.χ. <code>"{sku}" {title} product image</code></p>
					</td>
				</tr>
				<tr>
					<th>Επιλογές</th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr($o); ?>[include_title]" value="1" <?php checked( $settings['include_title'], 1 ); ?> /> Συμπερίληψη τίτλου στην αναζήτηση</label><br/>
						<label><input type="checkbox" name="<?php echo esc_attr($o); ?>[only_instock]" value="1" <?php checked( $settings['only_instock'], 1 ); ?> /> Μόνο προϊόντα <strong>In stock</strong></label>
					</td>
				</tr>
				<tr>
					<th>Max αποτελέσματα Google</th>
					<td><input type="number" min="1" max="10" name="<?php echo esc_attr($o); ?>[max_results]" value="<?php echo esc_attr( $settings['max_results'] ); ?>" /> <span class="description">(max 10 ανά αίτημα, 2 pages = 20 σύνολο)</span></td>
				</tr>
			</table>

			<h2>🖼️ Τι να Συμπληρώσω</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th>Featured Image</th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr($o); ?>[fill_featured]" value="1" <?php checked( $settings['fill_featured'], 1 ); ?> /> Προσθήκη featured image αν δεν υπάρχει</label><br/>
						<label><input type="checkbox" name="<?php echo esc_attr($o); ?>[skip_if_has_featured]" value="1" <?php checked( $settings['skip_if_has_featured'], 1 ); ?> /> Παράβλεψε το προϊόν αν ΉΔΗ έχει featured</label>
					</td>
				</tr>
				<tr>
					<th>Gallery</th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr($o); ?>[fill_gallery]" value="1" <?php checked( $settings['fill_gallery'], 1 ); ?> /> Συμπλήρωσε gallery</label><br/><br/>
						<label>Ελάχιστος αριθμός εικόνων gallery πριν θεωρηθεί "πλήρες":
							<input type="number" min="1" max="10" name="<?php echo esc_attr($o); ?>[min_gallery_count]" value="<?php echo esc_attr( $settings['min_gallery_count'] ); ?>" />
						</label><br/>
						<label>Στόχος εικόνων gallery (θα προσπαθήσει να φτάσει εδώ):
							<input type="number" min="3" max="10" name="<?php echo esc_attr($o); ?>[gallery_target]" value="<?php echo esc_attr( $settings['gallery_target'] ); ?>" />
						</label>
					</td>
				</tr>
			</table>

			<h2>⚙️ Ποιότητα & Επιδόσεις</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th>Ελάχιστο μέγεθος εικόνας</th>
					<td>
						<input type="number" min="0" name="<?php echo esc_attr($o); ?>[min_width]"  value="<?php echo esc_attr( $settings['min_width'] ); ?>" style="width:80px" /> ×
						<input type="number" min="0" name="<?php echo esc_attr($o); ?>[min_height]" value="<?php echo esc_attr( $settings['min_height'] ); ?>" style="width:80px" /> pixels
					</td>
				</tr>
				<tr>
					<th>Max download tries ανά προϊόν</th>
					<td><input type="number" min="1" max="10" name="<?php echo esc_attr($o); ?>[max_download_tries]" value="<?php echo esc_attr( $settings['max_download_tries'] ); ?>" /></td>
				</tr>
				<tr>
					<th>Max attempts ανά προϊόν</th>
					<td><input type="number" min="1" max="10" name="<?php echo esc_attr($o); ?>[max_attempts]" value="<?php echo esc_attr( $settings['max_attempts'] ); ?>" />
					<label><input type="checkbox" name="<?php echo esc_attr($o); ?>[skip_if_attempted]" value="1" <?php checked( $settings['skip_if_attempted'], 1 ); ?> /> Παράβλεψε αν υπερβεί τα max attempts</label></td>
				</tr>
				<tr>
					<th><label for="allowed_domains">Allowed Domains</label></th>
					<td>
						<input class="regular-text" name="<?php echo esc_attr($o); ?>[allowed_domains]" id="allowed_domains" value="<?php echo esc_attr( $settings['allowed_domains'] ); ?>" />
						<p class="description">Διαχωρισμός με κόμμα. Κενό = παντού. Π.χ. <code>gsmarena.com,phonearena.com</code></p>
					</td>
				</tr>
				<tr>
					<th>Request Timeout (δευτερόλεπτα)</th>
					<td><input type="number" min="5" max="60" name="<?php echo esc_attr($o); ?>[request_timeout]" value="<?php echo esc_attr( $settings['request_timeout'] ); ?>" /></td>
				</tr>
				<tr>
					<th>User Agent</th>
					<td><input class="large-text" name="<?php echo esc_attr($o); ?>[user_agent]" value="<?php echo esc_attr( $settings['user_agent'] ); ?>" /></td>
				</tr>
			</table>

			<h2>📋 Logging</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th>Logs</th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr($o); ?>[logging_enabled]" value="1" <?php checked( $settings['logging_enabled'], 1 ); ?> /> Ενεργοποίηση logs</label><br/>
						Level: <select name="<?php echo esc_attr($o); ?>[log_level]">
							<?php foreach ( ['debug','info','error'] as $lv ) : ?>
								<option value="<?php echo $lv; ?>" <?php selected($settings['log_level'],$lv); ?>><?php echo $lv; ?></option>
							<?php endforeach; ?>
						</select>
						&nbsp;&nbsp;
						Retention (ημέρες): <input type="number" min="1" max="365" name="<?php echo esc_attr($o); ?>[log_retention_days]" value="<?php echo esc_attr( $settings['log_retention_days'] ); ?>" style="width:70px" />
					</td>
				</tr>
			</table>

			<?php submit_button( 'Αποθήκευση Ρυθμίσεων' ); ?>
		</form>
		<?php
	}

	// ──────────────────────────────────────────────────────────────────────────
	// LOGS TAB
	// ──────────────────────────────────────────────────────────────────────────

	private static function tab_logs() : void {
		$table = new Logs_Table();
		$table->prepare_items();
		?>
		<h2>Φίλτρα</h2>
		<form method="get" style="margin-bottom:12px">
			<input type="hidden" name="page" value="gtmif" />
			<input type="hidden" name="tab"  value="logs" />
			<label>Level:
				<select name="level">
					<option value="">όλα</option>
					<?php foreach ( ['debug','info','error'] as $lv ) : ?>
						<option value="<?php echo $lv; ?>" <?php selected( $_GET['level']??'', $lv ); ?>><?php echo $lv; ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			&nbsp;
			<label>Product ID: <input type="number" name="product_id" value="<?php echo esc_attr($_GET['product_id']??''); ?>" style="width:90px" /></label>
			&nbsp;
			<label>SKU: <input type="text" name="sku" value="<?php echo esc_attr($_GET['sku']??''); ?>" /></label>
			<button class="button" style="margin-left:8px">Φίλτρο</button>
		</form>

		<form method="get">
			<input type="hidden" name="page" value="gtmif" />
			<input type="hidden" name="tab"  value="logs" />
			<?php $table->display(); ?>
		</form>

		<form method="post" style="margin-top:16px">
			<?php wp_nonce_field( 'gtmif_clear_logs' ); ?>
			<button class="button button-secondary" name="gtmif_clear_logs" value="1"
				onclick="return confirm('Διαγραφή όλων των logs;')">🗑 Διαγραφή Logs</button>
		</form>
		<?php
	}

	// ──────────────────────────────────────────────────────────────────────────
	// AJAX: Queue από Dashboard
	// ──────────────────────────────────────────────────────────────────────────

	public static function ajax_queue() : void {
		check_ajax_referer( 'gtmif_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '', 403 );

		$limit = max( 1, min( 2000, absint( $_POST['limit'] ?? 200 ) ) );
		$reset = ! empty( $_POST['reset'] );

		$queued = Runner::enqueue( $limit, $reset );

		if ( $queued > 0 ) {
			wp_send_json_success([
				'queued'  => $queued,
				'message' => "{$queued} προϊόντα προστέθηκαν στο queue.",
			]);
		} else {
			wp_send_json_success([
				'queued'  => 0,
				'message' => 'Δεν βρέθηκαν προϊόντα που χρειάζονται εικόνες.',
			]);
		}
	}

	// Bulk actions
	public static function register_bulk_action( array $actions ) : array {
		$actions['gtmif_fetch_images'] = '🖼 Fetch εικόνων (GTM Image Fetcher)';
		return $actions;
	}

	public static function handle_bulk_action( string $redirect_to, string $action, array $post_ids ) : string {
		if ( 'gtmif_fetch_images' !== $action ) return $redirect_to;

		$job_id = uniqid( 'gtmif_bulk_', true );
		$count  = 0;
		foreach ( $post_ids as $pid ) {
			$pid = absint( $pid );
			if ( ! $pid ) continue;
			$count++;
			update_post_meta( $pid, Runner::META_JOB_ID, $job_id );
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( Runner::ACTION_PROCESS, [ 'product_id' => $pid, 'job_id' => $job_id ], 'gtmif' );
			} else {
				wp_schedule_single_event( time() + 5, Runner::ACTION_PROCESS, [ $pid, $job_id ] );
			}
		}
		Stats::update([
			'current_job_id'        => $job_id,
			'current_job_total'     => $count,
			'current_job_processed' => 0,
			'current_job_status'    => 'running',
			'last_run_started'      => current_time( 'mysql' ),
		]);
		Stats::increment( 'total_queued', $count );
		Logger::info([ 'action' => 'bulk_action', 'message' => "Bulk: {$count} προϊόντα στο queue (job: {$job_id})." ]);

		return add_query_arg( [ 'gtmif_bulk_enqueued' => $count ], $redirect_to );
	}

	public static function notices() : void {
		if ( isset( $_GET['gtmif_bulk_enqueued'] ) ) {
			$c = absint( $_GET['gtmif_bulk_enqueued'] );
			echo '<div class="notice notice-success is-dismissible"><p>✅ ' . esc_html( $c ) . ' προϊόντα στο queue για εύρεση εικόνων.</p></div>';
		}
	}

	private static function status_label( string $status ) : string {
		return [
			'running'  => 'Τρέχει...',
			'paused'   => 'Παυσαρισμένο',
			'finished' => 'Ολοκληρώθηκε ✓',
			'idle'     => 'Αδρανές',
		][ $status ] ?? 'Άγνωστο';
	}
}
