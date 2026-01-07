<?php
/**
 * Plugin Name: DXNDRE Pricing Calculator
 * Description: Multi-step project pricing calculator
 * Version: 1.2.0
 */

if (!defined('ABSPATH')) exit;

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
	require_once __DIR__ . '/vendor/autoload.php';
}

define('DX_PC_PATH', plugin_dir_path(__FILE__));
define('DX_PC_URL', plugin_dir_url(__FILE__));

require_once DX_PC_PATH . 'inc/config.php';
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
		'1.0',
		true
	);

	wp_localize_script(
		'dx-pricing-calculator',
		'DX_PRICING',
		[
			'prices'   => dx_pricing_config(),
			'ajax_url' => admin_url('admin-ajax.php'),
		]
	);

	wp_enqueue_style(
		'dx-pricing-calculator',
		DX_PC_URL . 'assets/css/calculator.css',
		[],
		'1.0'
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
	$font_dir = DX_PC_PATH . 'assets/fonts/';

	$generated_at = new DateTime('now', wp_timezone());
	$expiry_at    = (clone $generated_at)->modify('+30 days');

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

	$logo_url = get_site_icon_url(512);
	$site_url = home_url('/');

	ob_start(); ?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8">
	<style>
		/* ==========================
		Fonts
		========================== */

		@font-face {
			font-family: 'Space Grotesk';
			font-style: normal;
			font-weight: 300 700;
			src: url('<?= $font_dir ?>SpaceGrotesk-VariableFont_wght.ttf') format('truetype');
		}

		@font-face {
			font-family: 'Outfit';
			font-style: normal;
			font-weight: 300 700;
			src: url('<?= $font_dir ?>Outfit-VariableFont_wght.ttf') format('truetype');
		}

		/* ==========================
		Base
		========================== */

		body {
			background: #fff;
			color: #000;
			font-family: 'Outfit', Helvetica, Arial, sans-serif;
			font-size: 12px;
			line-height: 1.6;
			padding: 40px;
			max-width: 992px;
			margin: 0 auto;
		}

		.container {
			border: 1px solid rgba(255,255,255,0.35);
			padding: 32px;
		}

		/* ==========================
		Headings
		========================== */

		h1, h2 {
			font-family: 'Space Grotesk', Helvetica, Arial, sans-serif;
			font-weight: 600;
			letter-spacing: -0.02em;
			color: #000;
		}

		h1 {
			font-size: 28px;
			margin-bottom: 24px;
		}

		/* ==========================
		Header
		========================== */

		.header-table {
			width: 100%;
			margin-bottom: 40px;
		}

		.header-table td {
			vertical-align: top;
			padding-bottom: 10px;
		}

		.meta-label {
			font-family: 'Outfit', Helvetica, Arial, sans-serif;
			font-size: 10px;
			font-weight: 500;
			letter-spacing: 0.12em;
			text-transform: uppercase;
			color: #bbbbbb;
			margin-bottom: 6px;
		}

		/* ==========================
		Line items
		========================== */

		.items {
			width: 100%;
			border-collapse: collapse;
			margin-top: 28px;
		}

		.items thead {
			text-transform: uppercase;
		}

		.items tr {
			border-bottom: 2px solid black;
		}

		.items th,
		.items td {
			font-family: 'Outfit', Helvetica, Arial, sans-serif;
			font-size: 12px;
			padding: 10px 0;
			border-bottom: 1px solid rgba(255,255,255,0.2);
			text-align: left;
		}

		.items th {
			font-size: 11px;
			font-weight: 500;
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
			margin-top: 24px;
			text-align: right;
			font-family: 'Space Grotesk', Helvetica, Arial, sans-serif;
			font-size: 22px;
			font-weight: 600;
			letter-spacing: -0.01em;
		}

		/* ==========================
		Footer
		========================== */

		.footer {
			margin-top: 40px;
			font-size: 11px;
			line-height: 1.6;
			color: #000;
		}

		@page {
			margin: 40px;
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

		<table class="header-table">
			<tr>
				<td>
					<h1>Quote</h1>
					<p><strong>D’André Phillips</strong><br>
					dandrephillips@outlook.com</p>
				</td>
				<td align="right">
					<p class="meta-label">Quote Total</p>
					<p style="font-size:22px;">£<?= $total ?></p>
				</td>
			</tr>
		</table>

		<table class="items">
			<thead>
				<th>Description</th>
				<th align="right">Amount</th>
			</thead>

			<?php foreach ($state as $key => $value): 
				if (is_array($value)) continue; ?>
				<tr>
					<td><?= ucfirst($key) ?></td>
					<td align="right"><?= esc_html($value) ?></td>
				</tr>
			<?php endforeach; ?>
		</table>

		<div class="total">
			<strong>Total £<?= $total ?></strong>
		</div>

		<div class="footer">
			<h3>Important to note</h3>
			This quotation is valid for 30 days.<br>
			Payment terms: 33.33% upfront, balance on completion.
		</div>

	</div>
</body>

<div class="footer">
	<span>Valid until <?= $expiry_date ?></span>
	<span class="page-number"></span>
</div>

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