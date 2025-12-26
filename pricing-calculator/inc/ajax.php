<?php

use Dompdf\Dompdf;

add_action('wp_ajax_dx_send_quote', 'dx_send_quote');
add_action('wp_ajax_nopriv_dx_send_quote', 'dx_send_quote');

function dx_send_quote() {

	if (empty($_POST['state'])) {
		wp_send_json_error(['message' => 'Missing state']);
	}

	$state = json_decode(stripslashes($_POST['state']), true);

	if (!$state) {
		wp_send_json_error(['message' => 'Invalid JSON']);
	}

	/* ==========================
	   BUILD PDF HTML
	========================== */

	$html  = '<h1>Project Quotation</h1>';
	$html .= '<ul>';

	foreach ($state as $key => $value) {
		if (is_array($value)) continue;
		$html .= '<li><strong>' . esc_html($key) . ':</strong> ' . esc_html($value) . '</li>';
	}

	$html .= '</ul>';
	$html .= '<p><strong>Total:</strong> £' . number_format($state['total'], 2) . '</p>';

	/* ==========================
	   GENERATE PDF
	========================== */

	if (!class_exists(Dompdf::class)) {
		wp_send_json_error(['message' => 'Dompdf not available']);
	}

	$dompdf = new Dompdf();
	$dompdf->loadHtml($html);
	$dompdf->setPaper('A4', 'portrait');
	$dompdf->render();

	$pdf_output = $dompdf->output();

	/* ==========================
	   SAVE TEMP FILE
	========================== */

	$upload_dir = wp_upload_dir();
	$filename   = 'quote-' . time() . '.pdf';
	$file_path = trailingslashit($upload_dir['path']) . $filename;

	file_put_contents($file_path, $pdf_output);

	/* ==========================
	   SEND EMAIL
	========================== */

	$to = sanitize_email($state['contact']['email'] ?? get_option('admin_email'));

	$sent = wp_mail(
		$to,
		'Your Project Estimate',
		'Attached is your project quotation. If you have any questions, just reply to this email.',
		['Content-Type: text/html; charset=UTF-8'],
		[$file_path]
	);

	/* ==========================
	   CLEAN UP
	========================== */

	if (file_exists($file_path)) {
		unlink($file_path);
	}

	if (!$sent) {
		wp_send_json_error(['message' => 'Mail failed']);
	}

	wp_send_json_success();
}