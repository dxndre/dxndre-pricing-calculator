<?php
if (!defined('ABSPATH')) exit;

/**
 * Fetch a quote by invoice number
 */
function dx_get_quote_by_invoice($invoice) {
	global $wpdb;

	return $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}dx_quotes WHERE invoice = %s",
			$invoice
		)
	);
}

/**
 * Save a quote
 */
function dx_save_quote($invoice, $state, $total) {
	global $wpdb;

	$wpdb->insert(
		$wpdb->prefix . 'dx_quotes',
		[
			'invoice'    => $invoice,
			'state'      => wp_json_encode($state),
			'total'      => $total,
			'created_at' => current_time('mysql'),
			'expires_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
		],
		['%s', '%s', '%f', '%s', '%s']
	);
}