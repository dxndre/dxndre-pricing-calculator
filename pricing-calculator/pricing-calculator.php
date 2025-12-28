<?php
/**
 * Plugin Name: DXNDRE Pricing Calculator
 * Description: Multi-step project pricing calculator
 * Version: 1.1.0
 */

if (!defined('ABSPATH')) exit;

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