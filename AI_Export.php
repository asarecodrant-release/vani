<?php
require_once __DIR__ . '/session-auth.php';
require_once __DIR__ . '/ai_service.php';

if (!is_authenticated_user()) {
    die('Authentication required.');
}

$scanId = trim((string)($_GET['scan'] ?? ''));
$format = trim((string)($_GET['format'] ?? 'excel'));
$customerId = (string)($_SESSION['setup_customer_id'] ?? '');

if ($scanId === '' || $customerId === '') {
    die('Invalid request.');
}

function ai_summary_text_from_page(array $page): string {
    $summaryJson = $page['summary_json'] ?? '';
    $summary = [];
    if (is_array($summaryJson)) {
        $summary = $summaryJson;
    } elseif (is_string($summaryJson) && $summaryJson !== '') {
        $summary = json_decode($summaryJson, true) ?: [];
    }
    return (string)($summary['summary'] ?? '');
}

$scan = ai_get_scan_job_for_customer($scanId, $customerId);
if (empty($scan)) {
    die('Scan job not found.');
}

$pages = ai_scan_review_pages($scanId, $customerId, 1000);
$faqs = ai_scan_review_faqs($scanId, $customerId, 2000);
if ($format === 'excel') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=vani-ai-export-' . date('Ymd') . '.csv');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Type', 'Title/Question', 'URL/Source', 'Summary/Answer', 'Status']);
    
    foreach ($pages as $page) {
        fputcsv($output, [
            'Page',
            $page['page_title'] ?: 'Untitled',
            $page['url'],
            ai_summary_text_from_page($page),
            $page['page_status']
        ]);
    }
    
    foreach ($faqs as $faq) {
        fputcsv($output, [
            'FAQ',
            $faq['question'],
            $faq['source'],
            $faq['answer'],
            'captured'
        ]);
    }
    fclose($output);
    exit;
}

if ($format === 'pdf'): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Export - <?php echo htmlspecialchars($scan['website_domain']); ?></title>
    <style>
        body { font-family: sans-serif; line-height: 1.5; color: #333; padding: 40px; }
        h1 { border-bottom: 2px solid #eee; padding-bottom: 10px; }
        h2 { margin-top: 30px; color: #2563eb; page-break-before: always; }
        .item { margin-bottom: 30px; border-bottom: 1px solid #eee; padding-bottom: 20px; }
        .meta { font-size: 12px; color: #666; margin-bottom: 10px; }
        .summary { background: #f9fafb; padding: 15px; border-radius: 8px; font-style: italic; }
        .faq-grid { display: grid; gap: 20px; }
        @media print {
            .no-print { display: none; }
            body { padding: 0; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="background:#fff7ed; padding:15px; border:1px solid #ffedd5; margin-bottom:20px; text-align:center;">
        <strong>Print Preview:</strong> Use your browser's Print feature (Ctrl+P / Cmd+P) and select "Save as PDF". 
        <button onclick="window.print()">Open Print Dialog</button>
    </div>

    <h1>Content Export: <?php echo htmlspecialchars($scan['website_domain']); ?></h1>
    <p>Generated on <?php echo date('F j, Y'); ?></p>

    <h2>Captured Pages (<?php echo count($pages); ?>)</h2>
    <?php foreach ($pages as $page): ?>
        <div class="item">
            <strong><?php echo htmlspecialchars($page['page_title'] ?: 'Untitled'); ?></strong>
            <div class="meta"><?php echo htmlspecialchars($page['url']); ?></div>
            <div class="summary">
                <?php echo nl2br(htmlspecialchars(ai_summary_text_from_page($page))); ?>
            </div>
        </div>
    <?php endforeach; ?>

    <h2>Captured FAQs (<?php echo count($faqs); ?>)</h2>
    <div class="faq-grid">
        <?php foreach ($faqs as $faq): ?>
            <div class="item">
                <strong>Q: <?php echo htmlspecialchars($faq['question']); ?></strong>
                <p>A: <?php echo nl2br(htmlspecialchars($faq['answer'])); ?></p>
                <div class="meta">Source: <?php echo htmlspecialchars($faq['source']); ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <script>
        window.onload = () => {
            // Optional: Automatically trigger print dialog
            // window.print();
        };
    </script>
</body>
</html>
<?php exit; endif; ?>