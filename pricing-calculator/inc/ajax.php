<?php

use Dompdf\Dompdf;
use Dompdf\Options;

// Finalising a quote

add_action('wp_ajax_dx_finalize_quote', 'dx_finalize_quote');
add_action('wp_ajax_nopriv_dx_finalize_quote', 'dx_finalize_quote');

function dx_finalize_quote() {

	if (empty($_POST['state'])) {
		wp_send_json_error(['message' => 'Missing state']);
	}

	$state = json_decode(stripslashes($_POST['state']), true);

	if (!$state || !is_array($state)) {
		wp_send_json_error(['message' => 'Invalid data']);
	}

	if (empty($state['total'])) {
		wp_send_json_error(['message' => 'Missing total']);
	}

	// Generate invoice number
	$invoice = dx_get_next_invoice_number();

	// Save quote
	dx_save_quote(
		$invoice,
		$state,
		$state['total']
	);

	wp_send_json_success([
		'invoice' => $invoice,
		'url'     => home_url("/proposal/$invoice/")
	]);
}

/* ==========================================================
   DOMPDF FACTORY
========================================================== */

function dx_create_dompdf() {

	$options = new Options();
	$options->set('isRemoteEnabled', true);
	$options->set('isHtml5ParserEnabled', true);
	$options->set('defaultFont', 'Outfit');

	// IMPORTANT: local font access
	$options->set(
		'fontDir',
		realpath(DX_PC_PATH . 'assets/fonts')
	);

	$options->set(
		'fontCache',
		realpath(WP_CONTENT_DIR . '/uploads/dompdf-font-cache')
	);

	return new Dompdf($options);
}

/* ==========================================================
   SEND QUOTE (EMAIL PROPOSAL)
========================================================== */

add_action('wp_ajax_dx_send_quote', 'dx_send_quote');
add_action('wp_ajax_nopriv_dx_send_quote', 'dx_send_quote');

function dx_send_quote() {

	if (empty($_POST['state'])) {
		wp_send_json_error(['message' => 'Missing state']);
	}

	$state = json_decode(stripslashes($_POST['state']), true);

	if (!$state || !is_array($state)) {
		wp_send_json_error(['message' => 'Invalid data']);
	}

	if (!class_exists(Dompdf::class)) {
		wp_send_json_error(['message' => 'PDF engine unavailable']);
	}

	// Build HTML
	$html = dx_build_quote_html($state);

	// Create Dompdf
	$dompdf = dx_create_dompdf();
	$dompdf->loadHtml($html, 'UTF-8');
	$dompdf->setPaper('A4', 'portrait');
	$dompdf->render();

	$pdf_output = $dompdf->output();

	// Save temporary file
	$upload_dir = wp_upload_dir();
	$filename   = 'project-proposal-' . time() . '.pdf';
	$file_path  = trailingslashit($upload_dir['path']) . $filename;

	file_put_contents($file_path, $pdf_output);

	// Email recipient
	$to = sanitize_email(
		$state['contact']['email'] ?? get_option('admin_email')
	);

	// Send email
	$sent = wp_mail(
		$to,
		'Your Project Proposal',
		'
		<p>Hi,</p>
		<p>Attached is your personalised project proposal.</p>
		<p>If you’d like to discuss next steps, simply reply to this email.</p>
		<p>— D’André</p>
		',
		['Content-Type: text/html; charset=UTF-8'],
		[$file_path]
	);

	// Cleanup temp file
	if (file_exists($file_path)) {
		unlink($file_path);
	}

	if (!$sent) {
		wp_send_json_error(['message' => 'Email failed']);
	}

	// Optional lead logging
	if (function_exists('dx_log_lead')) {
		dx_log_lead($to, 'pricing-calculator');
	}

	wp_send_json_success();
}

/* ==========================================================
   DOWNLOAD PDF (NO EMAIL)
========================================================== */

add_action('wp_ajax_dx_generate_quote_pdf', 'dx_generate_quote_pdf');
add_action('wp_ajax_nopriv_dx_generate_quote_pdf', 'dx_generate_quote_pdf');

function dx_generate_quote_pdf() {

	if (empty($_POST['state'])) {
		wp_die('Missing data');
	}

	$state = json_decode(stripslashes($_POST['state']), true);

	if (!$state || !is_array($state)) {
		wp_die('Invalid data');
	}

	if (!class_exists(Dompdf::class)) {
		wp_die('PDF engine unavailable');
	}

	$html = dx_build_quote_html($state);

	$dompdf = dx_create_dompdf();
	$dompdf->loadHtml($html, 'UTF-8');
	$dompdf->setPaper('A4', 'portrait');
	$dompdf->render();

	header('Content-Type: application/pdf');
	header('Content-Disposition: attachment; filename="project-proposal.pdf"');
	header('Cache-Control: private, max-age=0, must-revalidate');
	header('Pragma: public');

	echo $dompdf->output();
	exit;
}

/* ==========================================================
   LEAD LOGGING (OPTIONAL)
========================================================== */

function dx_log_lead($email, $source) {
	global $wpdb;

	$wpdb->insert(
		$wpdb->prefix . 'dx_pricing_leads',
		[
			'email'      => sanitize_email($email),
			'source'     => sanitize_text_field($source),
			'created_at' => current_time('mysql'),
		]
	);
}