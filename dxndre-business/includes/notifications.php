<?php
/** Notification templates and delivery eligibility for the shared business outbox. */
defined('ABSPATH') || exit;

function dxbs_notification_templates() {
    return [
        'document_quote' => ['quotations', 'Your quotation {reference}', 'Review the scope and terms for {name}. Your quotation is attached as a PDF.'],
        'quote_revised' => ['quotations', 'Your revised quotation {reference}', 'A revised quotation for {name} is ready to review. The revised PDF is attached.'],
        'quote_accepted' => ['quotations', 'Quotation {reference} accepted', 'The assigned client accepted the issued quotation for {name}.'],
        'quote_declined' => ['quotations', 'Quotation {reference} declined', 'The assigned client declined the issued quotation for {name}.'],
        'quote_expiring' => ['quotations', 'Quotation {reference} expires soon', 'Your quotation for {name} expires on {date}. Review it in your client space.'],
        'document_invoice' => ['payments', 'Your invoice {reference}', 'Your invoice for {name} is attached as a PDF. The payment due date is {date}.'],
        'payment_upcoming' => ['payments', 'Payment for {reference} is due soon', 'The outstanding balance is {amount}, due on {date}. See your invoice for bank transfer instructions.'],
        'payment_due' => ['payments', 'Payment for {reference} is due today', 'The outstanding balance of {amount} is due today. See your invoice for bank transfer instructions.'],
        'payment_overdue' => ['payments', 'Payment for {reference} is overdue', 'The outstanding balance is {amount}. If you have already transferred it, you can report the transfer in your client space.'],
        'payment_reminder' => ['payments', 'Payment reminder for {reference}', 'The outstanding balance is {amount}. If you have already transferred it, you can report the transfer in your client space.'],
        'transfer_reported' => ['payments', 'Bank transfer reported for {reference}', 'The client reported a transfer. Check the bank before recording a verified payment.'],
        'payment_verified' => ['payments', 'Bank payment confirmed for {reference}', 'Your bank payment has been verified and recorded against the invoice.'],
        'document_receipt' => ['payments', 'Your payment receipt {reference}', 'Your receipt for the verified bank payment is attached as a PDF.'],
        'invoice_settled' => ['payments', 'Invoice {reference} settled', 'Your invoice is now fully settled. You can review the payment history in your client space.'],
        'document_credit' => ['payments', 'Your credit note {reference}', 'Your credit note for {name} is attached as a PDF.'],
        'project_created' => ['projects', 'Your project space is ready', 'Your project space for {name} is ready. Open it to view your project and next steps.'],
        'project_stage' => ['projects', 'Project milestone for {name}', 'There is a new milestone update for {name}. {detail}'],
        'project_approval_requested' => ['projects', 'Approval requested for {name}', 'There is something ready for you to review in your project space. {detail}'],
        'project_action_required' => ['projects', 'An action is waiting for {name}', 'Open your project space to see what is needed. {detail}'],
        'project_document' => ['projects', 'New document for {name}', 'A document has been shared with you in your project space. {detail}'],
        'project_status' => ['projects', 'Project update for {name}', 'There is a status update in your project space. {detail}'],
        'ticket_created' => ['support', 'Support request {reference} opened', 'The support request “{name}” has been created. Follow the conversation in the dashboard.'],
        'ticket_assigned' => ['support', 'Support request {reference} assigned', 'A team member has been assigned to “{name}”.'],
        'ticket_updated' => ['support', 'Support request {reference} updated', 'There is an update to “{name}”. {detail}'],
        'ticket_reply' => ['support', 'New reply to {reference}', 'There is a new reply to “{name}”. Open the conversation to read it and respond.'],
        'ticket_status' => ['support', 'Status changed for {reference}', 'The status of “{name}” has changed. {detail}'],
        'ticket_resolved' => ['support', 'Support request {reference} resolved', '“{name}” has been resolved. You can reopen it in the dashboard if you need more help.'],
        'ticket_reopened' => ['support', 'Support request {reference} reopened', '“{name}” has been reopened. Open the dashboard to continue the conversation.'],
    ];
}

function dxbs_notification_categories() {
    return ['quotations' => 'Quotations', 'payments' => 'Invoices and payments', 'projects' => 'Projects', 'support' => 'Support tickets'];
}

function dxbs_notification_preferences() {
    $stored = (array) get_option('dxbs_notification_preferences', []);
    $enabled = array_fill_keys(array_keys(dxbs_notification_categories()), true);
    return [
        'enabled' => array_intersect_key((array) ($stored['enabled'] ?? []), $enabled) + $enabled,
        'templates' => array_intersect_key((array) ($stored['templates'] ?? []), dxbs_notification_templates()),
    ];
}

function dxbs_notification_placeholders() {
    return ['reference', 'name', 'detail', 'date', 'amount'];
}

function dxbs_save_notification_preferences(array $input) {
    if (!dxbs_is_manager()) return dxbs_require_manager();

    $preferences = ['enabled' => [], 'templates' => []];
    foreach (dxbs_notification_categories() as $key => $label) {
        $preferences['enabled'][$key] = !empty($input['enabled'][$key]);
    }
    foreach (dxbs_notification_templates() as $event => [$category, $default_subject, $default_copy]) {
        $template = (array) ($input['templates'][$event] ?? []);
        $subject = sanitize_text_field((string) ($template['subject'] ?? ''));
        $copy = sanitize_textarea_field((string) ($template['copy'] ?? ''));
        foreach ([$subject, $copy] as $value) {
            preg_match_all('/\{([^{}]+)\}/', $value, $matches);
            $remainder = preg_replace('/\{[^{}]+\}/', '', $value);
            if (array_diff($matches[1], dxbs_notification_placeholders()) || strpbrk($remainder, '{}') !== false) {
                return dxbs_error('notification_placeholder', 'An email template uses an unknown or incomplete placeholder.');
            }
        }
        if (mb_strlen($subject) > 200 || mb_strlen($copy) > 4000) {
            return dxbs_error('notification_length', 'Subjects can contain up to 200 characters, and email text up to 4,000.');
        }
        if (($subject !== '' && $subject !== $default_subject) || ($copy !== '' && $copy !== $default_copy)) {
            $preferences['templates'][$event] = ['subject' => $subject, 'copy' => $copy];
        }
    }
    update_option('dxbs_notification_preferences', $preferences, false);
    return true;
}

function dxbs_notification_content($event, array $context) {
    $template = dxbs_notification_templates()[$event] ?? null;
    if (!$template) return null;
    $custom = dxbs_notification_preferences()['templates'][$event] ?? [];
    $replacements = [];
    foreach (dxbs_notification_placeholders() as $key) {
        $replacements['{' . $key . '}'] = sanitize_text_field((string) ($context[$key] ?? ''));
    }
    $subject = trim(strtr(($custom['subject'] ?? '') ?: $template[1], $replacements));
    $copy = trim(strtr(($custom['copy'] ?? '') ?: $template[2], $replacements));
    return [$subject, $copy];
}

function dxbs_notification_recipient($type, $id, $audience, $record = null) {
    $owner = get_user_by('login', 'dxndre');
    if (dxbs_type_table($type)) {
        $record = $record ?? dxbs_record_raw($type, $id);
        if (!$record || !empty($record['voided_at'])) return '';
        if ($audience === 'owner') return $owner ? $owner->user_email : '';
        $snapshot = json_decode($record['snapshot'] ?? '{}', true) ?: [];
        return sanitize_email($snapshot['client']['email'] ?? '');
    }

    $expected_type = ['ticket' => 'ticket', 'project' => 'dxcp_project'][$type] ?? null;
    $record = $record ?? get_post($id);
    if (!$expected_type || !$record || $record->post_type !== $expected_type || !in_array($record->post_status, ['publish', 'private'], true)) return '';
    if ($audience === 'owner') return $owner ? $owner->user_email : '';
    if ($type === 'project') {
        $recipient_id = absint(get_post_meta($id, '_dxcp_client_id', true));
    } elseif ($audience === 'assignee') {
        $recipient_id = absint(get_post_meta($id, 'ticket_assignee', true));
        if (!$recipient_id || !user_can($recipient_id, 'manage_options')) return '';
    } else {
        if (!function_exists('dxcp_ticket_client_id')) return '';
        $recipient_id = dxcp_ticket_client_id($record);
    }
    if (!$recipient_id || !function_exists('dxcp_can_access') || !dxcp_can_access($record, $recipient_id)) return '';
    $recipient = get_userdata($recipient_id);
    return $recipient ? $recipient->user_email : '';
}

function dxbs_notify($event, $type, $id, $dedupe, $to, array $context = []) {
    $template = dxbs_notification_templates()[$event] ?? null;
    $category = ['ticket' => 'support', 'project' => 'projects', 'quote' => 'quotations', 'invoice' => 'payments', 'receipt' => 'payments', 'credit' => 'payments'][$type] ?? null;
    $owner = get_user_by('login', 'dxndre');
    if (!$template || $template[0] !== $category || !$owner || !dxbs_is_manager($owner->ID) || !dxbs_notification_preferences()['enabled'][$category]) return false;

    $audience = $context['audience'] ?? 'client';
    if (!in_array($audience, ['client', 'owner', 'assignee'], true)) return false;
    $expected = dxbs_notification_recipient($type, $id, $audience);
    if (!$expected || strcasecmp($to, $expected) !== 0) return false;

    $actor = get_current_user_id();
    if (in_array($type, ['project', 'ticket'], true) && $actor && (!function_exists('dxcp_can_access') || !dxcp_can_access($id, $actor))) return false;

    $link = wp_validate_redirect(esc_url_raw($context['link'] ?? ''), home_url('/dashboard/'));
    [$subject, $copy] = dxbs_notification_content($event, $context);
    return dxbs_queue_email($event, $type, $id, $dedupe, $to, $subject, dxbs_email_html($subject, $copy, $link), [], (int) $owner->ID, false, [
        'automated' => true, 'audience' => $audience, 'payment_id' => absint($context['payment_id'] ?? 0),
        'due_date' => $context['due_date'] ?? '', 'balance_minor' => $context['balance_minor'] ?? null,
    ]);
}

function dxbs_finance_notification($event, $type, array $record, $dedupe, array $context = []) {
    $audience = $context['audience'] ?? 'client';
    $id = (int) $record['id'];
    $date = $type === 'quote' ? ($record['expires_at'] ?? '') : ($record['due_date'] ?? '');
    $date = $date ? wp_date('j F Y', strtotime($date . ' UTC'), new DateTimeZone('Europe/London')) : '';
    $destination = $type === 'quote' ? 'quotations' : 'payments';
    $context += [
        'reference' => $record['number'], 'name' => $record['title'] ?? $record['number'], 'date' => $date,
        'link' => add_query_arg($audience === 'owner' ? ['section' => 'business', 'business_view' => $destination] : ['section' => $destination], home_url('/dashboard/')),
    ];
    return dxbs_notify($event, $type, $id, $dedupe, dxbs_notification_recipient($type, $id, $audience, $record), $context);
}

