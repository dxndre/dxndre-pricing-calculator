<?php

use Dompdf\Dompdf;
use Dompdf\Options;

// Register Activation Hook

require_once DX_PC_PATH . 'inc/install.php';

register_activation_hook(__FILE__, 'dx_pricing_create_tables');

/**
 * Public calculator actions remain available to guests, but require a same-site
 * WordPress nonce and bounded request rates. Nonces are not ownership proof.
 */
function dx_pricing_rate_key($operation) {
	$group = in_array($operation, ['send', 'email'], true) ? 'mail' : $operation;
	$address = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
	return 'dx_pc_rate_' . $group . '_' . hash_hmac('sha256', $address, wp_salt('nonce'));
}

function dx_pricing_request_error($operation) {
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return new WP_Error('method_not_allowed', 'Please submit the calculator form again.', ['status'=>405]);
	$nonce = $_POST['_dx_pricing_nonce'] ?? ($_GET['_dx_pricing_nonce'] ?? '');
	if (!is_string($nonce) || !wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)), 'dx_pricing_public')) return new WP_Error('invalid_nonce', 'Your calculator session has expired. Refresh the page and try again.', ['status'=>403]);
	$limits = ['finalize'=>60, 'pdf'=>20, 'send'=>5, 'email'=>5];
	if (!isset($limits[$operation])) return new WP_Error('invalid_action', 'This calculator action is not available.', ['status'=>400]);
	if (isset($_POST['state']) && (!is_string($_POST['state']) || strlen($_POST['state']) > MB_IN_BYTES)) return new WP_Error('invalid_state', 'The calculator data is too large or invalid. Please start again.', ['status'=>400]);
	$key = dx_pricing_rate_key($operation);
	$now = time();
	$bucket = get_transient($key);
	if (!is_array($bucket) || (int)($bucket['until'] ?? 0) <= $now) $bucket = ['count'=>0, 'until'=>$now + 15 * MINUTE_IN_SECONDS];
	if ((int)$bucket['count'] >= $limits[$operation]) return new WP_Error('rate_limited', 'You have made several requests. Please wait a few minutes before trying again.', ['status'=>429]);
	++$bucket['count'];
	set_transient($key, $bucket, max(1, (int)$bucket['until'] - $now));
	return null;
}

function dx_pricing_require_ajax($operation) {
	$error = dx_pricing_request_error($operation);
	if (is_wp_error($error)) {
		$data = $error->get_error_data();
		wp_send_json_error(['message'=>$error->get_error_message(), 'code'=>$error->get_error_code()], (int)($data['status'] ?? 400));
	}
	if (in_array($operation, ['send','email'], true) && !function_exists('wp_tempnam')) require_once ABSPATH . 'wp-admin/includes/file.php';
}

/** Read only the existing HttpOnly calculator-claim cookie, never a request token. */
function dx_pricing_guest_claims() {
	$cookie = $_COOKIE['dxcp_estimate_claims'] ?? '';
	if (!is_string($cookie) || strlen($cookie) > 4096) return [];
	$claims = json_decode(base64_decode(wp_unslash($cookie), true) ?: '', true);
	return is_array($claims) ? array_slice($claims, -8, null, true) : [];
}

/** Preserve guest-email access even when the portal theme is not active. */
function dx_pricing_remember_guest_claim($reference) {
	if (is_user_logged_in() || headers_sent() || function_exists('dxcp_billing_remember_guest_estimate')) return;
	$token = get_transient('dx_estimate_edit_' . $reference);
	if (!is_string($token) || strlen($token) < 20 || strlen($token) > 100) return;
	$claims = dx_pricing_guest_claims();
	unset($claims[$reference]);
	$claims[$reference] = $token;
	$value = base64_encode(wp_json_encode(array_slice($claims, -8, null, true)));
	setcookie('dxcp_estimate_claims', $value, ['expires'=>time()+30*DAY_IN_SECONDS, 'path'=>defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/', 'secure'=>is_ssl(), 'httponly'=>true, 'samesite'=>'Lax']);
	$_COOKIE['dxcp_estimate_claims'] = $value;
}

/**
 * A reference/nonce alone never authorises saved quotation access. Once assigned,
 * account ownership wins over every old guest cookie or calculator edit token.
 */
function dx_pricing_can_access_saved_quote($reference) {
	if (!is_string($reference) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,49}\z/D', $reference)) return false;
	$assignment = function_exists('dxcp_quote_assignment') ? dxcp_quote_assignment($reference) : get_option('dxcp_quote_' . hash('sha256', $reference), []);
	if (!is_array($assignment)) return false;
	if ($assignment) {
		if (function_exists('dxcp_billing_may_edit_quote')) return dxcp_billing_may_edit_quote($reference);
		if (current_user_can('manage_options')) return true;
		$uid = get_current_user_id();
		$project = absint($assignment['project_id'] ?? 0);
		return $uid > 0 && (int)($assignment['client_id'] ?? 0) === $uid && get_post_type($project) === 'dxcp_project' && (int)get_post_meta($project, '_dxcp_client_id', true) === $uid;
	}
	if (current_user_can('manage_options')) return true;
	$token = dx_pricing_guest_claims()[$reference] ?? '';
	$stored = get_transient('dx_estimate_edit_' . $reference);
	return is_string($token) && strlen($token) >= 20 && strlen($token) <= 100 && is_string($stored) && hash_equals($stored, $token);
}

function dx_pricing_require_saved_reference($state) {
	$reference = $state['estimate_reference'] ?? '';
	if ($reference !== '' && !dx_pricing_can_access_saved_quote($reference)) wp_send_json_error(['message'=>'This estimate is not available to your account or calculator session.'], 403);
}

// Finalising a quote

add_action('wp_ajax_dx_finalize_quote', 'dx_finalize_quote');
add_action('wp_ajax_nopriv_dx_finalize_quote', 'dx_finalize_quote');

function dx_finalize_quote() {

	dx_pricing_require_ajax('finalize');

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
	dx_pricing_remember_guest_claim($invoice);
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

	dx_pricing_require_ajax('send');

	if (empty($_POST['state'])) {
		wp_send_json_error(['message' => 'Missing state']);
	}

	$state = json_decode(stripslashes($_POST['state']), true);

	if (!$state || !is_array($state)) {
		wp_send_json_error(['message' => 'Invalid data']);
	}

	dx_pricing_require_saved_reference($state);
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
	$temporary = wp_tempnam('dxndre-calculator-proposal.pdf');
	$file_path = $temporary ? $temporary . '.pdf' : '';
	if (!$temporary || !@rename($temporary, $file_path) || file_put_contents($file_path, $pdf_output) !== strlen($pdf_output)) {
		if ($temporary) @unlink($temporary);
		if ($file_path) @unlink($file_path);
		wp_send_json_error(['message'=>'The proposal attachment could not be prepared.'], 500);
	}
	@chmod($file_path, 0600);

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

	dx_pricing_require_ajax('pdf');

	if (empty($_POST['state'])) {
		wp_die('Missing data');
	}

	$state = json_decode(stripslashes($_POST['state']), true);

	if (!$state || !is_array($state)) {
		wp_die('Invalid data');
	}

	dx_pricing_require_saved_reference($state);
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

	dx_pricing_require_ajax('email');

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
	if (!dx_pricing_can_access_saved_quote($invoice)) {
		wp_send_json_error(['message'=>'This estimate is not available to your account or calculator session.'], 403);
	}
	$quote = dx_get_quote_by_invoice($invoice);

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
