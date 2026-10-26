<?php

use Dompdf\Dompdf;
use Dompdf\Options;

// Register Activation Hook

require_once DX_PC_PATH . 'inc/install.php';

register_activation_hook(__FILE__, 'dx_pricing_create_tables');

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

	$state = dx_validate_estimate_state($state);
	if (is_wp_error($state)) wp_send_json_error(['message'=>$state->get_error_message()]);
	$reference = sanitize_text_field($_POST['estimate_reference'] ?? '');
	$token = sanitize_text_field($_POST['estimate_token'] ?? '');
	$is_new = !$reference;
	if ($reference) {
		if (function_exists('dxcp_billing_may_edit_quote') && !dxcp_billing_may_edit_quote($reference)) wp_send_json_error(['message'=>'Sign in to the client account linked to this estimate before editing it.']);
		$stored = get_transient('dx_estimate_edit_' . $reference);
		if (!$stored || !$token || !hash_equals($stored, $token) || !dx_get_quote_by_invoice($reference)) wp_send_json_error(['message'=>'This estimate can no longer be edited. Please start a new estimate.']);
		$invoice = $reference;
		$state['estimate_reference'] = $invoice;
		global $wpdb;
		$saved = $wpdb->update($wpdb->prefix.'dx_quotes', ['state'=>wp_json_encode($state),'total'=>$state['total']], ['invoice'=>$invoice], ['%s','%f'], ['%s']);
	} else {
		$invoice = dx_get_next_invoice_number();
		$token = wp_generate_password(40, false, false);
		$state['estimate_reference'] = $invoice;
		$saved = dx_save_quote($invoice, $state, $state['total']);
		set_transient('dx_estimate_edit_' . $invoice, $token, 30 * DAY_IN_SECONDS);
	}
	if ($saved === false) wp_send_json_error(['message'=>'The estimate could not be saved. Please try again.']);
	do_action('dx_quote_finalized', $invoice, $state, $is_new);
	wp_send_json_success(['invoice'=>$invoice, 'token'=>$token, 'url'=>home_url("/proposal/$invoice/")]);
}

/* ==========================================================
   DOMPDF FACTORY
========================================================== */

function dx_create_dompdf() {

	$options = new Options();
	$options->set('isRemoteEnabled', true);
	$options->set('isHtml5ParserEnabled', true);
	$options->set('defaultFont', 'Outfit');

	// Keep generated font metrics in a writable cache, separate from bundled fonts.
	$font_cache = WP_CONTENT_DIR . '/uploads/dompdf-font-cache';
	wp_mkdir_p($font_cache);
	$options->set('fontDir', $font_cache);
	$options->set('fontCache', $font_cache);
	$options->set('chroot', [WP_CONTENT_DIR, DX_PC_PATH]);

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

	$state = dx_validate_estimate_state($state);
	if (is_wp_error($state)) wp_die(esc_html($state->get_error_message()));

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

	$state = dx_validate_estimate_state($state);
	if (is_wp_error($state)) wp_die(esc_html($state->get_error_message()));

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

// Email Quote

add_action('wp_ajax_dx_email_quote_pdf', 'dx_email_quote_pdf');
add_action('wp_ajax_nopriv_dx_email_quote_pdf', 'dx_email_quote_pdf');


function dx_email_quote_pdf() {

	// Retrieve and sanitise submitted details.
	$name = sanitize_text_field(
		wp_unslash($_POST['name'] ?? '')
	);

	$email = sanitize_email(
		wp_unslash($_POST['email'] ?? '')
	);

	$invoice = sanitize_text_field(
		wp_unslash($_POST['invoice'] ?? '')
	);

	// Validate required fields.
	if (!$name || !is_email($email) || !$invoice) {
		wp_send_json_error([
			'message' => 'Please provide a valid name, email and estimate reference.'
		]);
	}

	// Retrieve the saved quotation.
	$quote = dx_get_quote_by_invoice(basename($invoice));

	if (!$quote) {
		wp_send_json_error([
			'message' => 'Quotation not found.'
		]);
	}

	$state = json_decode($quote->state, true);

	if (!is_array($state)) {
		wp_send_json_error([
			'message' => 'Invalid quotation data.'
		]);
	}

	// Check PDF engine availability.
	if (!class_exists(Dompdf::class)) {
		wp_send_json_error([
			'message' => 'PDF engine unavailable.'
		]);
	}

	// Generate PDF.
	try {
		$html = dx_build_quote_html($state);

		$dompdf = dx_create_dompdf();
		$dompdf->loadHtml($html, 'UTF-8');
		$dompdf->setPaper('A4', 'portrait');
		$dompdf->render();

		$pdf = $dompdf->output();

	} catch (\Throwable $error) {
		error_log('DXNDRE PDF error: ' . $error->getMessage());

		wp_send_json_error([
			'message' => 'Unable to generate your quotation PDF.'
		]);
	}

	// Verify the generated PDF.
	if (substr($pdf, 0, 5) !== '%PDF-') {
		wp_send_json_error([
			'message' => 'The generated PDF is invalid.'
		]);
	}

	// Create a unique temporary file.
	$tmp = wp_tempnam('dxndre-proposal-' . $invoice . '.pdf');

	if (!$tmp) {
		wp_send_json_error([
			'message' => 'Unable to create temporary PDF file.'
		]);
	}

	// Give the temporary file a genuine PDF extension.
	$pdf_path = substr($tmp, 0, -4) . '.pdf';

	if (!@rename($tmp, $pdf_path)) {
		@unlink($tmp);

		wp_send_json_error([
			'message' => 'Unable to prepare PDF attachment.'
		]);
	}

	// Write the PDF contents.
	$written = file_put_contents($pdf_path, $pdf);

	if ($written === false || $written !== strlen($pdf)) {
		@unlink($pdf_path);

		wp_send_json_error([
			'message' => 'Unable to save PDF attachment.'
		]);
	}

	// Create a professional attachment filename.
	$filename = sanitize_file_name(
		'DXNDRE-Proposal-' . $invoice . '.pdf'
	);

	// Email headers.
	$headers = [
		'Content-Type: text/html; charset=UTF-8',
		'From: DXNDRE <hello@dxndre.co.uk>',
	];

	// Escape the customer name before inserting into HTML.
	$safe_name = esc_html($name);

	// Email message.
	$message = "
<!doctype html><html><body style='margin:0;background:#090d0a;color:#eeede7;font-family:Arial,Helvetica,sans-serif;'>
<table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='background:#090d0a;'><tr><td align='center' style='padding:40px 18px;'>
<table role='presentation' width='600' cellspacing='0' cellpadding='0' style='width:100%;max-width:600px;background:#111812;'>
<tr><td style='padding:36px 32px;border-bottom:1px solid #4f4530;'><span style='font-size:26px;color:#eeede7;font-family:Arial,Helvetica,sans-serif;'><b>dxndre</b>.co.uk</span></td></tr>
<tr><td style='padding:36px 32px;font-size:16px;line-height:1.8;color:#eeede7;'>
<p style='font-size:11px;letter-spacing:3px;text-transform:uppercase;color:#d7ad57;margin:0 0 18px;'>Your next chapter</p>
<h1 style='font-size:36px;line-height:1.15;font-weight:400;margin:0 0 32px;color:#eeede7;'>Your project proposal.</h1>
<p>Hi {$safe_name},</p>
<p>Thank you for taking the time to build your quote — I’ve attached a copy of your proposal for reference.</p>
<p>If you have any questions or would like to talk through next steps, just reply to this email.</p>
<p style='margin-top:32px;'>Best,<br><span style='color:#d7ad57;'>D’André</span></p>
</td></tr><tr><td style='padding:24px 32px;border-top:1px solid #4f4530;font-size:12px;color:#b9bbb5;'>Considered design. Websites that work beautifully.</td></tr>
</table></td></tr></table></body></html>";

	// Attach the PDF using its proper filename.
	$attachments = [
		$filename => $pdf_path
	];

	// Send the quotation email.
	$sent = wp_mail(
		$email,
		'Your Project Proposal',
		$message,
		$headers,
		$attachments
	);

	// Remove the temporary PDF after sending.
	if (file_exists($pdf_path)) {
		@unlink($pdf_path);
	}

	// Handle email failures.
	if (!$sent) {
		wp_send_json_error([
			'message' => 'Unable to send your quotation email.'
		]);
	}

	wp_send_json_success([
		'message' => 'Your project proposal has been emailed successfully.'
	]);
}
