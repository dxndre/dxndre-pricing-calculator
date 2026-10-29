<?php
/**
 * Plugin Name: DXNDRE Pricing Calculator
 * Description: Multi-step project pricing calculator
 * Version: 2.0.1
 */

if (!defined('ABSPATH')) exit;

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
	require_once __DIR__ . '/vendor/autoload.php';
}

define('DX_PC_PATH', plugin_dir_path(__FILE__));
define('DX_PC_URL', plugin_dir_url(__FILE__));

require_once DX_PC_PATH . 'inc/config.php';
require_once DX_PC_PATH . 'inc/quotes.php';
require_once DX_PC_PATH . 'inc/ajax.php';

add_shortcode('pricing_calculator', 'dx_pricing_calculator_shortcode');
add_action('wp_enqueue_scripts', 'dx_pricing_calculator_assets');

// Generate next invoice number

function dx_get_next_invoice_number() {
	$current = get_option('dx_last_invoice_number', 69); // last used
	$next = intval($current) + 1;
	update_option('dx_last_invoice_number', $next);
	return 'INV' . str_pad($next, 5, '0', STR_PAD_LEFT);
}

function dx_pricing_calculator_assets() {
	wp_enqueue_script(
		'dx-pricing-calculator',
		DX_PC_URL . 'assets/js/calculator.js',
		[],
		filemtime(DX_PC_PATH . 'assets/js/calculator.js'),
		true
	);

	wp_localize_script(
		'dx-pricing-calculator',
		'DX_PRICING',
		[
			'prices'   => dx_pricing_config(),
			'ajax_url' => add_query_arg('_dx_pricing_nonce', wp_create_nonce('dx_pricing_public'), admin_url('admin-ajax.php')),
		]
	);

	wp_enqueue_style(
		'dx-pricing-calculator',
		DX_PC_URL . 'assets/css/calculator.css',
		[],
		filemtime( DX_PC_PATH . 'assets/css/calculator.css' )
	);
}
add_action('wp_enqueue_scripts', 'dx_pricing_calculator_assets');

function dx_pricing_calculator_shortcode() {
	ob_start();
	include DX_PC_PATH . 'templates/calculator.php';
	return ob_get_clean();
}

add_filter('query_vars', function ($vars) {
	$vars[] = 'ticket_id';
	return $vars;
});

// Building PDF for quotation

function dx_build_quote_html($state) {

	$total = number_format($state['total'], 2);
	$invoice = $state['estimate_reference'] ?? '';
    $portal_quote = function_exists('dxcp_quote_data') ? dxcp_quote_data($invoice) : null;
    $is_final = !empty($portal_quote) && $portal_quote['kind']==='final';
	$is_retainer = ($state['service'] ?? '') === 'retainer';
	$font_dir = DX_PC_PATH . 'assets/fonts/';

	$generated_at = new DateTime($state['estimate_created_at'] ?? 'now', wp_timezone());
	$expiry_at = !empty($state['estimate_expires_at']) ? new DateTime($state['estimate_expires_at'], wp_timezone()) : (clone $generated_at)->modify('+30 days');

	$quote_date  = $generated_at->format('d F Y');
	$expiry_date = $expiry_at->format('d F Y');

	$labels = [
		'service'  => 'Service',
		'scope'    => 'Project Scope',
		'type'     => 'Project Type',
		'timeline' => 'Delivery Timeline',
		'features' => 'Additional Features',
		'extras'   => 'Ongoing Support'
	];

	$logo_url = 'file://' . realpath(DX_PC_PATH . 'assets/img/dxndre-logo-black.png');
	$site_url = home_url('/');
	$font_dir = 'file://' . realpath(DX_PC_PATH . 'assets/fonts') . DIRECTORY_SEPARATOR;

	ob_start(); ?>
<!doctype html>
<html>
	<head>
		<meta charset="utf-8">
		<style>
			@font-face {
				font-family: 'Outfit';
				src: url('<?php echo esc_attr($font_dir . 'Outfit-Regular.ttf'); ?>') format('truetype');
				font-weight: normal;
				font-style: normal;
			}
			@font-face {
				font-family: 'OutfitMedium';
				src: url('<?php echo esc_attr($font_dir . 'Outfit-Medium.ttf'); ?>') format('truetype');
				font-weight: normal;
				font-style: normal;
			}
			@font-face {
				font-family: 'OutfitSemiBold';
				src: url('<?php echo esc_attr($font_dir . 'Outfit-SemiBold.ttf'); ?>') format('truetype');
				font-weight: normal;
				font-style: normal;
			}
			@font-face {
				font-family: 'OutfitBold';
				src: url('<?php echo esc_attr($font_dir . 'Outfit-Bold.ttf'); ?>') format('truetype');
				font-weight: normal;
				font-style: normal;
			}
			@font-face {
				font-family: 'SpaceGrotesk';
				src: url('<?php echo esc_attr($font_dir . 'SpaceGrotesk-Regular.ttf'); ?>') format('truetype');
				font-weight: normal;
				font-style: normal;
			}
			@font-face {
				font-family: 'SpaceGroteskSemiBold';
				src: url('<?php echo esc_attr($font_dir . 'SpaceGrotesk-SemiBold.ttf'); ?>') format('truetype');
				font-weight: normal;
				font-style: normal;
			}
			@font-face {
				font-family: 'SpaceGroteskBold';
				src: url('<?php echo esc_attr($font_dir . 'SpaceGrotesk-Bold.ttf'); ?>') format('truetype');
				font-weight: normal;
				font-style: normal;
			}
			/* ==========================
			Base
			========================== */

			body {
				background: #fff;
				color: #000;
				font-family: 'Outfit', sans-serif;
				font-size: 12px;
				line-height: 1.45;
				padding: 0;
				max-width: 992px;
				margin: 0 auto;
			}

			p {
				margin: 8px 0;
			}

			strong, b {
				font-family: 'OutfitBold', sans-serif;
				font-weight: normal;
			}

			.container {
				border: 1px solid rgba(255,255,255,0.35);
				padding: 8px;
			}

			/* ==========================
			Headings
			========================== */

			h1, h2, h3 {
				font-family: 'SpaceGroteskBold', sans-serif;
				font-weight: normal;
			}

			h1 {
				font-size: 20px;
				margin-bottom: 12px;
				margin-top: 0;
			}

			h4 {
				font-family: 'SpaceGroteskSemiBold', sans-serif;
				font-weight: normal;
				margin: 0;
			}

			/* ==========================
			Header
			========================== */

			.logo-header {
				text-align: right;
				margin-bottom: 24px;
			}

			.header-table {
				width: 100%;
				margin-bottom: 12px;
			}

			.header-table td {
				vertical-align: top;
				padding-bottom: 4px;
			}

			.header-table td.align-right {
				text-align: right;
			}

			.meta-label {
				font-family: 'OutfitMedium', sans-serif;
				font-size: 14px;
				font-weight: normal;
				letter-spacing: 0.12em;
				text-transform: uppercase;
				color: #bbbbbb;
				margin-bottom: 6px;
			}

			.headline {
				font-family: 'SpaceGrotesk', sans-serif;
				text-transform: uppercase;
				font-weight: 400;
				color: rgba(0, 0, 0, 0.6666);
				letter-spacing: 1px;
				margin: 0;
			}

			/* ==========================
			Line items
			========================== */

			.items {
				width: 100%;
				border-collapse: collapse;
				margin-top: 12px;
			}

			.items thead {
				/* text-transform: uppercase; */
				border-bottom: 2px solid rgba(0,0,0,0.75);
			}

			.items tr {
				border-bottom: 2px solid rgba(0,0,0,0.25);
			}

			.items th,
			.items td {
				font-family: 'Outfit', sans-serif;
				font-size: 12px;
				padding: 7px 0;
				/* border-bottom: 1px solid rgba(255,255,255,0.2); */
				text-align: left;
				font-weight: 400;
			}

			.items th {
				font-family: 'OutfitSemiBold', sans-serif;
				font-size: 12px;
				font-weight: normal;
				color: #000;
			}

			.items td:last-child,
			.items th:last-child {
				text-align: right;
			}

			/* ==========================
			Total
			========================== */

			.total {
				margin-top: 14px;
				text-align: right;
				font-family: 'OutfitSemiBold', sans-serif;
				font-size: 18px;
				font-weight: normal;
				letter-spacing: -0.01em;
			}

			/* ==========================
			Footer
			========================== */

			.footer {
				margin-top: 22px;
				font-size: 11px;
				line-height: 1.45;
				color: #000;
			}

			.footer-inner {
				padding: 12px 16px;
				border: 1px solid rgba(0,0,0,0.375);
				max-width: 400px;
			}

			.footer-inner h3 {
				font-size: 16px;
				margin-top: 0;
				margin-bottom: 8px;
			}

			.footer p {
				font-size: 10px;
				margin: 0;
				margin-bottom: 4px;
			}

			@page {
				margin: 40px;
			}

			.pdf-page-footer {
				position: fixed;
				bottom: -24px;
				left: 0;
				font-size: 10px;
			}

			.total strong {
				font-family: inherit;
			}

			.page-number:before {
				content: counter(page);
			}

			.discount {
				color: #7fbf9a;
			}
		</style>
	</head>

	<body>
		<div class="container">

			<div class="logo-header">
				<a href="<?= esc_url($site_url); ?>" target="_blank">
					<img src="<?= esc_attr($logo_url); ?>" alt="DXNDRE" width="180" style="height:auto;">
				</a>
			</div>

			<table class="header-table">
				<tr>
					<td>
						<pre class="headline">Pricing Calculator</pre>
						<h1><?= $is_final ? 'Final quotation' : 'Online Estimate'; ?></h1><p>Estimate reference: <?= esc_html($invoice) ?></p>
						<?php if (!empty($state['client_name']) || !empty($state['client_company'])) : ?><p>Prepared for <?= esc_html(trim(($state['client_name'] ?? '').' · '.($state['client_company'] ?? ''), ' ·')); ?></p><?php endif; ?>
						<p><strong>D’André Phillips</strong><br>
						dandrephillips@outlook.com</p>
					</td>
					<td align="right">
						<td class="align-right">
							<h4>Quotation Generation Date</h4>
							<span><?= date('d F Y') ?></span>
						</td>
						<td class="align-right">
							<h4>Quotation Expiry Date</h4>
							<span><?= $expiry_date ?></span>
						</td>
					</td>
				</tr>
			</table>

			<table class="items">
				<thead>
					<th>Description</th>
					<th align="right">Amount</th>
				</thead>

				<?php echo dx_estimate_breakdown_html($state, 2); ?>
			</table>

			<div class="total">
				<strong><?= $is_retainer ? 'Monthly estimate' : 'Total' ?> £<?= $total ?><?= $is_retainer ? ' / month' : '' ?></strong>
			</div>

			<div class="footer">
				<div class="footer-inner">
					<h3>Important to note</h3>
					<p>This quotation is valid for 30 days. </p>
					<p><?= $is_retainer ? 'Hours are shared across selected support categories and expire monthly. Dedicated landing-page hours apply only to landing-page work. Scheduling targets are not completion guarantees. Additional work and major builds require separate agreement.' : '' ?><?= ' '.esc_html(dx_payment_terms($state)) ?></p>
				</div>
				
			</div>

		</div>

	<footer class="pdf-page-footer">
		<span>Estimate <?= esc_html($invoice); ?></span>
		<span class="page-number"></span>
	</footer>
	</body>
</html>
<?php
	return ob_get_clean();
}

// Dev Preview of PDF quote
add_action('init', function () {
	if (!isset($_GET['dx_preview'])) return;

	if (!current_user_can('manage_options')) {
		wp_die('Unauthorized');
	}

	$mock_state = [
		'service' => 'WordPress Development',
		'scope' => 'Large',
		'type' => 'Business Website',
		'features' => ['eCommerce', 'User Accounts'],
		'timeline' => 'Priority',
		'extras' => ['Maintenance'],
		'total' => 8450
	];

	echo dx_build_quote_html($mock_state);
	exit;
});

// URL Routing with invoie number

add_action('init', function () {
	add_rewrite_rule(
		'^proposal/([^/]+)/?$',
		'index.php?dx_proposal=$matches[1]',
		'top'
	);
});

add_filter('query_vars', function ($vars) {
	$vars[] = 'dx_proposal';
	return $vars;
});

// Intercept proposal requests

add_action('template_redirect', function () {

	$invoice = get_query_var('dx_proposal');
	if (!$invoice) return;

	$quote = dx_get_quote_by_invoice($invoice);

	if (!$quote) {
		wp_die('Quote not found', '404', ['response' => 404]);
	}

	$state = json_decode($quote->state, true);
    $state['total']=(float)$quote->total;
    if(($state['service']??'')!=='retainer' && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['dx_plan_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dx_plan_nonce'])),'dx_plan_'.$invoice)) {
        $state['payment_plan']=sanitize_key($_POST['payment_plan']??'full');
        $state['payment_plan']=dx_payment_schedule($state)['plan'];
        global $wpdb;
        $wpdb->update($wpdb->prefix.'dx_quotes',['state'=>wp_json_encode($state)],['invoice'=>$invoice],['%s'],['%s']);
        wp_safe_redirect(home_url('/proposal/'.rawurlencode($invoice).'/'));exit;
    }
    if(isset($_GET['pdf'])) {
        $state['estimate_reference']=$invoice;$state['estimate_created_at']=$quote->created_at;$state['estimate_expires_at']=$quote->expires_at;
        $pdf=dx_create_dompdf();$pdf->loadHtml(dx_build_quote_html($state),'UTF-8');$pdf->setPaper('A4','portrait');$pdf->render();
        header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="estimate-'.sanitize_file_name($invoice).'.pdf"');echo $pdf->output();exit;
    }
	$total = number_format($quote->total, 2);

	$generated_at = new DateTime($quote->created_at);
	$expiry_date  = (new DateTime($quote->expires_at))->format('d F Y');

	// Detect PDF mode
	$is_pdf = isset($_GET['pdf']);

	include DX_PC_PATH . 'templates/proposal.php';
	exit;
});

// Plugin activation hook to flush rewrite rules

register_activation_hook(__FILE__, function () {
	global $wpdb;

	$table = $wpdb->prefix . 'dx_quotes';
	$charset = $wpdb->get_charset_collate();

	$sql = "
		CREATE TABLE $table (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			invoice VARCHAR(20) NOT NULL UNIQUE,
			state LONGTEXT NOT NULL,
			total DECIMAL(10,2) NOT NULL,
			created_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL
		) $charset;
	";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta($sql);
});