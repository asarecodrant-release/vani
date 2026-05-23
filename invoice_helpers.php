<?php
require_once __DIR__ . '/billing.php';

if (!function_exists('safe_rows')) {
    function safe_rows(array $response): array {
        $data = $response['data'] ?? null;
        return is_array($data) ? $data : [];
    }
}

function invoice_money(int $paise): string {
    return 'Rs. ' . number_format($paise / 100, 2);
}

function invoice_plain_text(string $value): string {
    $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
    $value = preg_replace('/[^\x20-\x7E]/', ' ', $value);
    return trim(preg_replace('/\s+/', ' ', $value));
}

function invoice_pdf_escape(string $value): string {
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], invoice_plain_text($value));
}

function invoice_number(): string {
    return 'VANI-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function invoice_customer_context(string $customerId, string $email): array {
    $signup = safe_rows(supabase(
        'GET',
        'chatbot_signups?select=website_name,business_type,email&customer_id=eq.' . urlencode($customerId) . '&limit=1'
    ));
    $profile = safe_rows(supabase(
        'GET',
        'customer_profiles?select=first_name,last_name,country_code,mobile_number,address_line1,address_line2,city,state_region,country,postal_code&email=eq.' . urlencode($email) . '&limit=1'
    ));
    return [
        'signup' => $signup[0] ?? [],
        'profile' => $profile[0] ?? []
    ];
}

function invoice_customer_name(array $context, string $email): string {
    $profile = $context['profile'] ?? [];
    $name = trim((string)($profile['first_name'] ?? '') . ' ' . (string)($profile['last_name'] ?? ''));
    return $name !== '' ? $name : $email;
}

function invoice_customer_address(array $context): string {
    $profile = $context['profile'] ?? [];
    $parts = array_filter([
        $profile['address_line1'] ?? '',
        $profile['address_line2'] ?? '',
        $profile['city'] ?? '',
        $profile['state_region'] ?? '',
        $profile['country'] ?? '',
        $profile['postal_code'] ?? ''
    ], fn($part) => trim((string)$part) !== '');
    return implode(', ', array_map('strval', $parts));
}

function invoice_pdf_binary(array $invoice, array $context): string {
    $plan = billing_plan((string)($invoice['plan_id'] ?? 'free'));
    $invoiceNo = (string)($invoice['invoice_number'] ?? '');
    $createdAt = substr((string)($invoice['created_at'] ?? gmdate('c')), 0, 10);
    $periodStart = substr((string)($invoice['billing_period_start'] ?? ''), 0, 10);
    $periodEnd = substr((string)($invoice['billing_period_end'] ?? ''), 0, 10);
    $email = (string)($invoice['email'] ?? '');
    $customerName = invoice_customer_name($context, $email);
    $address = invoice_customer_address($context);
    $website = (string)($context['signup']['website_name'] ?? '');
    $business = (string)($context['signup']['business_type'] ?? '');
    $description = $plan['name'] . ' Plan - VANI AI Subscription';
    if (($invoice['invoice_type'] ?? '') === 'auto_recharge') {
        $description = 'Auto Wallet Recharge - ' . $plan['name'] . ' Plan';
    }

    $lines = [];
    $lines[] = ['VANI AI', 48, 780, 28, '0.31 0.27 0.90 rg'];
    $lines[] = ['Invoice from Codrant', 48, 755, 13, '0.35 0.40 0.50 rg'];
    $lines[] = ['INVOICE', 410, 780, 24, '0.02 0.06 0.18 rg'];
    $lines[] = ['Invoice No: ' . $invoiceNo, 410, 752, 11, '0.10 0.12 0.18 rg'];
    $lines[] = ['Date: ' . $createdAt, 410, 735, 11, '0.10 0.12 0.18 rg'];
    $lines[] = ['Status: PAID', 410, 718, 11, '0.08 0.45 0.24 rg'];

    $lines[] = ['From', 48, 680, 12, '0.02 0.06 0.18 rg'];
    $lines[] = ['Codrant', 48, 662, 11, '0.10 0.12 0.18 rg'];
    $lines[] = ['Behind Golden Care Hospital, Bhumkar Chawak', 48, 646, 10, '0.35 0.40 0.50 rg'];
    $lines[] = ['Wakad, Pune 411057, Maharashtra, India', 48, 631, 10, '0.35 0.40 0.50 rg'];
    $lines[] = ['info@codrant.com | +91-9579246848', 48, 616, 10, '0.35 0.40 0.50 rg'];

    $lines[] = ['Bill To', 330, 680, 12, '0.02 0.06 0.18 rg'];
    $lines[] = [$customerName, 330, 662, 11, '0.10 0.12 0.18 rg'];
    $lines[] = [$email, 330, 646, 10, '0.35 0.40 0.50 rg'];
    if ($address !== '') $lines[] = [substr($address, 0, 58), 330, 631, 10, '0.35 0.40 0.50 rg'];
    if ($website !== '') $lines[] = ['Website: ' . $website, 330, 616, 10, '0.35 0.40 0.50 rg'];
    if ($business !== '') $lines[] = ['Business: ' . $business, 330, 601, 10, '0.35 0.40 0.50 rg'];

    $lines[] = ['Description', 58, 545, 11, '1 1 1 rg'];
    $lines[] = ['Period', 335, 545, 11, '1 1 1 rg'];
    $lines[] = ['Amount', 475, 545, 11, '1 1 1 rg'];
    $lines[] = [$description, 58, 512, 11, '0.10 0.12 0.18 rg'];
    $lines[] = [($periodStart && $periodEnd) ? ($periodStart . ' to ' . $periodEnd) : '30 days', 335, 512, 10, '0.35 0.40 0.50 rg'];
    $lines[] = [invoice_money((int)($invoice['subtotal_paise'] ?? 0)), 475, 512, 11, '0.10 0.12 0.18 rg'];
    $lines[] = ['Subtotal', 360, 448, 11, '0.35 0.40 0.50 rg'];
    $lines[] = [invoice_money((int)($invoice['subtotal_paise'] ?? 0)), 475, 448, 11, '0.10 0.12 0.18 rg'];
    $lines[] = ['Tax', 360, 428, 11, '0.35 0.40 0.50 rg'];
    $lines[] = [invoice_money((int)($invoice['tax_paise'] ?? 0)), 475, 428, 11, '0.10 0.12 0.18 rg'];
    $lines[] = ['Total Paid', 360, 396, 14, '0.02 0.06 0.18 rg'];
    $lines[] = [invoice_money((int)($invoice['total_paise'] ?? 0)), 475, 396, 14, '0.31 0.27 0.90 rg'];
    $lines[] = ['Payment Ref: ' . (string)($invoice['payment_reference'] ?? '-'), 48, 350, 10, '0.35 0.40 0.50 rg'];
    $lines[] = ['Order Ref: ' . (string)($invoice['order_reference'] ?? '-'), 48, 335, 10, '0.35 0.40 0.50 rg'];
    $lines[] = ['Thank you for purchasing VANI AI subscription product from Codrant.', 48, 285, 12, '0.10 0.12 0.18 rg'];
    $lines[] = ['This is a system generated invoice.', 48, 265, 10, '0.35 0.40 0.50 rg'];

    $content = "0.96 0.97 1 rg 0 0 595 842 re f\n";
    $content .= "0.31 0.27 0.90 rg 0 815 595 27 re f\n";
    $content .= "0.31 0.27 0.90 rg 48 535 500 28 re f\n";
    $content .= "0.89 0.91 1 rg 48 488 500 43 re f\n";
    $content .= "0.31 0.27 0.90 rg 352 384 196 34 re f\n";
    foreach ($lines as [$text, $x, $y, $size, $color]) {
        if ($text === '') continue;
        $content .= $color . "\nBT /F1 " . (int)$size . " Tf " . (int)$x . " " . (int)$y . " Td (" . invoice_pdf_escape($text) . ") Tj ET\n";
    }

    $objects = [];
    $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    $objects[] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    return $pdf;
}

function invoice_email_html(array $invoice, array $context): string {
    $plan = billing_plan((string)($invoice['plan_id'] ?? 'free'));
    $name = htmlspecialchars(invoice_customer_name($context, (string)($invoice['email'] ?? '')), ENT_QUOTES, 'UTF-8');
    $invoiceNo = htmlspecialchars((string)($invoice['invoice_number'] ?? ''), ENT_QUOTES, 'UTF-8');
    $amount = htmlspecialchars(invoice_money((int)($invoice['total_paise'] ?? 0)), ENT_QUOTES, 'UTF-8');
    $planName = htmlspecialchars((string)$plan['name'], ENT_QUOTES, 'UTF-8');
    return '<!doctype html><html><body style="margin:0;background:#f3f4f6;font-family:Arial,sans-serif;color:#111827">'
        . '<div style="padding:24px"><div style="max-width:640px;margin:auto;background:#fff;border-radius:22px;overflow:hidden;box-shadow:0 18px 45px rgba(15,23,42,.12)">'
        . '<div style="background:linear-gradient(135deg,#4f46e5,#ec4899);padding:34px;color:#fff;text-align:center"><h1 style="margin:0;font-size:30px">VANI AI Invoice</h1><p style="margin:10px 0 0;opacity:.92">Thank you for purchasing from Codrant.</p></div>'
        . '<div style="padding:32px"><h2 style="margin:0 0 12px">Hi ' . $name . ',</h2>'
        . '<p style="line-height:1.7;color:#475569">Your invoice for the ' . $planName . ' subscription product is ready. A PDF copy is attached with this email.</p>'
        . '<div style="margin:24px 0;border:1px solid #e5e7eb;border-radius:16px;overflow:hidden"><div style="display:flex;justify-content:space-between;padding:16px 18px;background:#f8fafc"><strong>Invoice</strong><span>' . $invoiceNo . '</span></div>'
        . '<div style="display:flex;justify-content:space-between;padding:16px 18px"><strong>Total paid</strong><span style="color:#4f46e5;font-weight:800">' . $amount . '</span></div></div>'
        . '<p style="line-height:1.7;color:#475569">You can also download all invoices anytime from Dashboard → Billing → Invoices.</p>'
        . '<p style="margin-top:28px;color:#64748b;font-size:13px">VANI AI by Codrant<br>info@codrant.com</p></div></div></div></body></html>';
}

function create_customer_invoice(string $customerId, string $email, string $planId, int $amountPaise, string $paymentReference, string $orderReference, string $invoiceType = 'subscription', array $metadata = []): array {
    if ($customerId === '' || $email === '' || $amountPaise <= 0 || $paymentReference === '') {
        return [];
    }
    $existing = safe_rows(supabase(
        'GET',
        'customer_invoices?select=*&payment_reference=eq.' . urlencode($paymentReference) . '&customer_id=eq.' . urlencode($customerId) . '&limit=1'
    ));
    if (!empty($existing[0])) {
        return $existing[0];
    }

    $periodStart = gmdate('Y-m-d\TH:i:s\Z');
    $periodEnd = gmdate('Y-m-d\TH:i:s\Z', strtotime('+30 days'));
    $invoiceNumber = invoice_number();
    $filename = $invoiceNumber . '.pdf';
    $res = supabase('POST', 'customer_invoices', [[
        'invoice_number' => $invoiceNumber,
        'customer_id' => $customerId,
        'email' => $email,
        'plan_id' => $planId,
        'invoice_type' => $invoiceType,
        'status' => 'paid',
        'currency' => 'INR',
        'subtotal_paise' => $amountPaise,
        'tax_paise' => 0,
        'total_paise' => $amountPaise,
        'payment_reference' => $paymentReference,
        'order_reference' => $orderReference,
        'billing_period_start' => $periodStart,
        'billing_period_end' => $periodEnd,
        'pdf_filename' => $filename,
        'metadata' => (object)$metadata
    ]]);
    return $res['data'][0] ?? [];
}

function send_customer_invoice_email(array $invoice): bool {
    if (empty($invoice['email']) || empty($invoice['customer_id'])) {
        return false;
    }
    require_once __DIR__ . '/email.php';
    $context = invoice_customer_context((string)$invoice['customer_id'], (string)$invoice['email']);
    $pdf = invoice_pdf_binary($invoice, $context);
    $sent = sendBrevoEmail(
        (string)$invoice['email'],
        'Invoice ' . (string)$invoice['invoice_number'] . ' - VANI AI by Codrant',
        invoice_email_html($invoice, $context),
        [[
            'name' => (string)($invoice['pdf_filename'] ?? ((string)$invoice['invoice_number'] . '.pdf')),
            'content' => base64_encode($pdf)
        ]]
    );
    if ($sent) {
        supabase('PATCH', 'customer_invoices?id=eq.' . urlencode((string)$invoice['id']), [
            'emailed_at' => gmdate('Y-m-d\TH:i:s\Z')
        ]);
    }
    return $sent;
}
?>
