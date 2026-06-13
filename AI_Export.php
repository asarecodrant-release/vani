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

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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

// Capture the report HTML into a buffer
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Export - <?php echo h($scan['website_domain']); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #2563eb; --slate: #0f172a; --muted: #64748b; --border: #e2e8f0; --bg: #f8fafc; }
        body { font-family: 'Inter', sans-serif; line-height: 1.6; color: var(--slate); padding: 40px; margin: 0; background: #fff; }

        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 4px solid var(--primary); padding-bottom: 20px; margin-bottom: 40px; }
        .brand-box { display: flex; align-items: center; gap: 15px; }
        .logo { width: 48px; height: 48px; object-fit: contain; }
        .brand-name { font-size: 28px; font-weight: 800; color: var(--primary); letter-spacing: -0.02em; }
        .report-meta { text-align: right; }
        .report-meta h1 { margin: 0; font-size: 14px; text-transform: uppercase; color: var(--muted); letter-spacing: 0.1em; }
        .report-meta p { margin: 5px 0 0; font-weight: 600; font-size: 16px; }

        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 40px; }
        .stat-card { background: var(--bg); border: 1px solid var(--border); border-radius: 12px; padding: 20px; text-align: center; }
        .stat-card span { display: block; font-size: 12px; font-weight: 800; color: var(--muted); text-transform: uppercase; margin-bottom: 8px; }
        .stat-card strong { font-size: 24px; color: var(--primary); }

        .section-header { background: var(--slate); color: #fff; padding: 12px 20px; border-radius: 8px; font-size: 18px; font-weight: 800; margin: 40px 0 25px; display: flex; justify-content: space-between; }

        .page-item { border: 1px solid var(--border); border-radius: 12px; padding: 25px; margin-bottom: 25px; page-break-inside: avoid; }
        .page-title { font-size: 20px; font-weight: 800; margin: 0 0 8px; color: var(--slate); }
        .page-url { color: var(--primary); font-size: 13px; font-weight: 600; text-decoration: none; word-break: break-all; margin-bottom: 15px; display: block; }
        .page-summary { background: var(--bg); padding: 20px; border-radius: 8px; border-left: 4px solid var(--primary); font-size: 15px; color: #334155; }

        .faq-item { margin-bottom: 20px; page-break-inside: avoid; border-bottom: 1px solid var(--border); padding-bottom: 20px; }
        .faq-item:last-child { border-bottom: 0; }
        .faq-q { font-weight: 800; font-size: 16px; color: var(--slate); margin-bottom: 8px; display: flex; gap: 10px; }
        .faq-q::before { content: 'Q:'; color: var(--primary); }
        .faq-a { font-size: 15px; color: #475569; padding-left: 28px; }
        .faq-a::before { content: 'A:'; font-weight: 800; color: #10b981; margin-left: -28px; margin-right: 10px; }

        .footer { margin-top: 60px; padding-top: 20px; border-top: 1px solid var(--border); text-align: center; color: var(--muted); font-size: 12px; }

        @media print {
            .no-print { display: none; }
            body { padding: 0; }
            .section-header { background: #eee !important; color: #000 !important; border: 1px solid #ccc; }
            .stat-card { background: #fff !important; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand-box">
            <img src="images/logo_img.png" alt="Vani AI" class="logo" onerror="this.style.display='none'">
            <span class="brand-name">Vani AI</span>
        </div>
        <div class="report-meta">
            <h1>Knowledge Intelligence Report</h1>
            <p><?php echo h($scan['website_domain']); ?></p>
        </div>
    </header>

    <div class="stats-grid">
        <div class="stat-card">
            <span>Captured Pages</span>
            <strong><?php echo count($pages); ?></strong>
        </div>
        <div class="stat-card">
            <span>Knowledge Pairs (FAQs)</span>
            <strong><?php echo count($faqs); ?></strong>
        </div>
        <div class="stat-card">
            <span>Export Date</span>
            <strong><?php echo date('M d, Y'); ?></strong>
        </div>
    </div>

    <div class="section-header">
        <span>Captured Pages & Summaries</span>
        <span><?php echo count($pages); ?> Items</span>
    </div>

    <?php foreach ($pages as $page): ?>
        <div class="page-item">
            <h2 class="page-title"><?php echo h($page['page_title'] ?: 'Untitled Page'); ?></h2>
            <a href="<?php echo h($page['url']); ?>" class="page-url"><?php echo h($page['url']); ?></a>
            <div class="page-summary">
                <?php echo nl2br(h(ai_summary_text_from_page($page))); ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (!empty($faqs)): ?>
    <div class="section-header">
        <span>Captured Knowledge Base (FAQs)</span>
        <span><?php echo count($faqs); ?> Pairs</span>
    </div>

    <div class="faq-list">
        <?php foreach ($faqs as $faq): ?>
            <div class="faq-item">
                <div class="faq-q"><?php echo h($faq['question']); ?></div>
                <div class="faq-a"><?php echo nl2br(h($faq['answer'])); ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <footer class="footer">
        <p>Generated by Vani AI - Knowledge Intelligence Platform</p>
        <p>&copy; <?php echo date('Y'); ?> Vani AI. All rights reserved.</p>
    </footer>
</body>
</html>
<?php
$reportHtml = ob_get_clean();

if ($format === 'pdf'):
    // Output a wrapper HTML page that uses html2pdf.js to generate and download the PDF
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Generating PDF Report...</title>
    <script src="https://cdn.jsdelivr.net/npm/html2pdf.js@0.10.1/dist/html2pdf.bundle.min.js"></script>
    <style>
        body { font-family: sans-serif; text-align: center; padding: 50px; }
        .loader { border: 8px solid #f3f3f3; border-top: 8px solid #3498db; border-radius: 50%; width: 60px; height: 60px; animation: spin 2s linear infinite; margin: 20px auto; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="loader"></div>
    <p>Generating your PDF report. Please wait...</p>
    <script>
        window.onload = function() {
            const reportHtml = <?php echo json_encode($reportHtml); ?>; // Safely pass HTML string
            const filename = `vani-ai-export-<?php echo date('Ymd'); ?>.pdf`;

            html2pdf().set({
                margin: [10, 10, 10, 10],
                filename: filename,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true, logging: false },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
            }).from(reportHtml).save().then(() => {
                // Optional: Redirect back or close window after download
                // window.close(); // Might not work in all browsers
            });
        };
    </script>
</body>
</html>
<?php exit; endif; ?>