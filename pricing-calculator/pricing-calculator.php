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

	ob_start(); ?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8">
	<style>
		body {
			background: #121212;
			color: #ffffff;
			font-family: Helvetica, Arial, sans-serif;
			font-size: 12px;
			padding: 40px;
		}

		.container {
			border: 1px solid #ffffff;
			padding: 30px;
		}

		h1 {
			font-size: 26px;
			margin-bottom: 30px;
		}

		.header-table {
			width: 100%;
			margin-bottom: 40px;
		}

		.header-table td {
			vertical-align: top;
			padding-bottom: 10px;
		}

		.meta-label {
			color: #bbbbbb;
			font-size: 10px;
			text-transform: uppercase;
			letter-spacing: 1px;
		}

		.items {
			width: 100%;
			border-collapse: collapse;
			margin-top: 30px;
		}

		.items th,
		.items td {
			border-bottom: 1px solid #444;
			padding: 10px 0;
			text-align: left;
		}

		.items th {
			font-size: 11px;
			color: #cccccc;
		}

		.total {
			text-align: right;
			font-size: 20px;
			margin-top: 20px;
		}

		.footer {
			margin-top: 40px;
			font-size: 11px;
			color: #aaaaaa;
			line-height: 1.6;
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
			<tr>
				<th>Description</th>
				<th align="right">Amount</th>
			</tr>

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
			This quotation is valid for 30 days.<br>
			Payment terms: 33.33% upfront, balance on completion.
		</div>

	</div>
</body>
</html>
<?php
	return ob_get_clean();
}