<?php
require_once __DIR__ . '/session-auth.php';
require_once __DIR__ . '/ai_service.php';

if (!is_authenticated_user()) {
    die('Authentication required.');
}

$scanId = trim((string)($_GET['scan'] ?? ''));
$format = trim((string)($_GET['format'] ?? 'excel'));
$customerId = (string)($_SESSION['setup_customer_id'] ?? '');

if ($customerId === '') {
    $email = authenticated_email();
    if ($email !== '') {
        $botRows = ai_safe_rows(supabase(
            'GET',
            'chatbot_signups?select=customer_id&email=eq.' . urlencode($email) . '&order=created_at.desc&limit=1'
        ));
        $customerId = (string)($botRows[0]['customer_id'] ?? '');
    }
}

if ($scanId === '') {
    $scanId = trim((string)($_SESSION['ai_scan_job_id'] ?? ''));
}

if ($customerId === '') {
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
    $fallbackRows = ai_safe_rows(supabase(
        'GET',
        'ai_scan_jobs?select=*&customer_id=eq.' . urlencode($customerId) . '&order=created_at.desc&limit=1'
    ));
    $scan = $fallbackRows[0] ?? [];
    if (!empty($scan)) {
        $scanId = (string)$scan['id'];
    }
}
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
        :root {
            --ink: #0f172a;
            --muted: #5b6475;
            --border: #dbe4f0;
            --surface: #ffffff;
            --soft: #f6f8fc;
            --brand: #0f4c81;
            --brand-2: #1e73be;
            --brand-3: #0ea5e9;
            --accent: #10b981;
        }

        @page { size: A4; margin: 12mm; }

        html, body { margin: 0; padding: 0; background: #e8eef7; color: var(--ink); font-family: 'Inter', sans-serif; }
        body { line-height: 1.55; }

        .report {
            width: 100%;
            max-width: 170mm;
            margin: 0 auto;
            padding: 0 2mm;
            overflow-x: hidden;
        }

        .cover-page {
            min-height: 224mm;
            box-sizing: border-box;
            border-radius: 24px;
            overflow: hidden;
            background:
                radial-gradient(circle at top left, rgba(30, 115, 190, 0.18), transparent 28%),
                radial-gradient(circle at 85% 12%, rgba(14, 165, 233, 0.16), transparent 26%),
                linear-gradient(145deg, #f8fbff 0%, #e9f3ff 100%);
            padding: 12mm 10mm 8mm;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            page-break-after: always;
            width: 100%;
            margin: 0 auto;
        }

        .brand-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .brand-lockup {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .brand-copy {
            display: grid;
            gap: 2px;
        }

        .brand-name {
            font-size: 30px;
            font-weight: 900;
            color: var(--brand);
            letter-spacing: -0.03em;
            line-height: 1;
        }

        .brand-tag {
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            font-weight: 800;
        }

        .report-chip {
            display: inline-flex;
            align-items: center;
            min-height: 30px;
            padding: 0 12px;
            border-radius: 999px;
            background: rgba(15, 76, 129, 0.1);
            color: var(--brand);
            border: 1px solid rgba(15, 76, 129, 0.18);
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .hero {
            display: grid;
            gap: 14px;
            max-width: 154mm;
            margin: 14mm auto 0;
            text-align: center;
        }

        .hero h1 {
            margin: 0;
            font-size: 26pt;
            line-height: 1.05;
            letter-spacing: -0.03em;
            color: var(--ink);
        }

        .hero p {
            margin: 0;
            font-size: 12pt;
            color: #334155;
            max-width: 154mm;
        }

        .hero-note {
            display: grid;
            gap: 8px;
            max-width: 154mm;
            margin: 3mm auto 0;
            color: var(--muted);
            font-size: 10pt;
            text-align: center;
        }

        .hero-note strong {
            color: var(--ink);
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin: 10mm auto 0;
            max-width: 154mm;
        }

        .metric-card {
            background: rgba(255, 255, 255, 0.88);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 14px;
            box-shadow: 0 10px 26px rgba(15, 23, 42, 0.05);
        }

        .metric-card span {
            display: block;
            font-size: 9px;
            font-weight: 900;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.09em;
            margin-bottom: 6px;
        }

        .metric-card strong {
            display: block;
            font-size: 22px;
            line-height: 1;
            color: var(--brand);
        }

        .cover-footer {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-end;
            color: var(--muted);
            font-size: 10pt;
            max-width: 154mm;
            margin: 10mm auto 0;
        }

        .content-wrap {
            display: grid;
            gap: 14px;
            max-width: 154mm;
            margin: 0 auto;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 11px 14px;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--ink), #173554);
            color: #fff;
            font-size: 13px;
            font-weight: 900;
            letter-spacing: 0.02em;
            page-break-after: avoid;
            width: 100%;
            box-sizing: border-box;
            margin-left: auto;
            margin-right: auto;
        }

        .section-header span:last-child {
            color: rgba(255, 255, 255, 0.76);
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .page-item,
        .faq-item,
        .metric-card {
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .page-item {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 13px 14px;
            margin-bottom: 12px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05);
            width: 100%;
            box-sizing: border-box;
            margin-left: auto;
            margin-right: auto;
        }

        .page-title {
            margin: 0 0 6px;
            font-size: 16px;
            line-height: 1.25;
            color: var(--ink);
            text-align: center;
        }

        .page-url {
            display: block;
            margin-bottom: 8px;
            color: var(--brand-2);
            font-size: 10px;
            font-weight: 800;
            word-break: break-word;
            overflow-wrap: anywhere;
            text-decoration: none;
            text-align: center;
        }

        .page-summary {
            background: linear-gradient(180deg, #f8fbff, #eef5ff);
            border: 1px solid #d9e7fb;
            border-left: 4px solid var(--brand-2);
            border-radius: 10px;
            padding: 10px 12px;
            color: #334155;
            font-size: 10.8pt;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .faq-list {
            display: grid;
            gap: 12px;
        }

        .faq-item {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 12px 14px;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.04);
            width: 100%;
            box-sizing: border-box;
            margin-left: auto;
            margin-right: auto;
        }

        .faq-q {
            font-weight: 900;
            font-size: 11.5pt;
            color: var(--ink);
            margin-bottom: 6px;
            text-align: center;
        }

        .faq-q::before {
            content: 'Q';
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            margin-right: 8px;
            border-radius: 999px;
            background: rgba(15, 76, 129, 0.1);
            color: var(--brand);
            font-size: 10px;
            font-weight: 900;
        }

        .faq-a {
            color: #334155;
            font-size: 10.6pt;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .faq-a::before {
            content: 'A';
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            margin-right: 8px;
            margin-top: 4px;
            border-radius: 999px;
            background: rgba(16, 185, 129, 0.12);
            color: var(--accent);
            font-size: 10px;
            font-weight: 900;
        }

        .footer {
            margin-top: 10mm;
            padding-top: 8mm;
            border-top: 1px solid var(--border);
            text-align: center;
            color: var(--muted);
            font-size: 9px;
        }

        @media print {
            body { background: #fff; }
            .cover-page,
            .page-item,
            .faq-item,
            .metric-card,
            .section-header { box-shadow: none !important; }
        }
    </style>
</head>
<body>
    <main class="report">
        <section class="cover-page">
            <div>
                <div class="brand-row">
                    <div class="brand-lockup">
                        <div class="brand-copy">
                            <div class="brand-name">Vani AI</div>
                            <div class="brand-tag">Knowledge Intelligence Report</div>
                        </div>
                    </div>
                    <span class="report-chip">A4 professional export</span>
                </div>

                <div class="hero">
                    <h1><?php echo h($scan['website_domain']); ?></h1>
                    <p>
                        Captured website pages, summaries, and FAQs compiled into a branded customer report.
                        This export is designed for review, sharing, and printing without losing content.
                    </p>
                </div>

                <div class="hero-note">
                    <div><strong>Scan job:</strong> <?php echo h($scan['id']); ?></div>
                    <div><strong>Website:</strong> <?php echo h($scan['website_url']); ?></div>
                    <div><strong>Generated:</strong> <?php echo h(date('M d, Y H:i')); ?></div>
                </div>
            </div>

            <div>
                <div class="summary-grid">
                    <div class="metric-card">
                        <span>Captured Pages</span>
                        <strong><?php echo count($pages); ?></strong>
                    </div>
                    <div class="metric-card">
                        <span>Knowledge Pairs</span>
                        <strong><?php echo count($faqs); ?></strong>
                    </div>
                    <div class="metric-card">
                        <span>Pages Summarized</span>
                        <strong><?php echo count(array_filter($pages, fn($page) => (string)($page['page_status'] ?? '') === 'summarized')); ?></strong>
                    </div>
                    <div class="metric-card">
                        <span>Pages Failed</span>
                        <strong><?php echo count(array_filter($pages, fn($page) => (string)($page['page_status'] ?? '') === 'failed')); ?></strong>
                    </div>
                </div>

                <div class="cover-footer" style="margin-top: 14mm;">
                    <div>
                        <strong style="display:block;color:var(--ink);font-size:12pt;">Prepared for customer review</strong>
                        <span>This document includes all captured page summaries and FAQs for the active scan.</span>
                    </div>
                    <div style="text-align:right;">
                        <strong style="display:block;color:var(--ink);font-size:12pt;">Vani AI</strong>
                        <span>Professional export</span>
                    </div>
                </div>
            </div>
        </section>

        <section class="content-wrap">
            <div class="section-header">
                <span>Captured Pages & Summaries</span>
                <span><?php echo count($pages); ?> items</span>
            </div>

            <?php foreach ($pages as $page): ?>
                <article class="page-item">
                    <h2 class="page-title"><?php echo h($page['page_title'] ?: 'Untitled Page'); ?></h2>
                    <a href="<?php echo h($page['url']); ?>" class="page-url"><?php echo h($page['url']); ?></a>
                    <div class="page-summary">
                        <?php echo nl2br(h(ai_summary_text_from_page($page))); ?>
                    </div>
                </article>
            <?php endforeach; ?>

            <?php if (!empty($faqs)): ?>
                <div class="section-header">
                    <span>Captured Knowledge Base (FAQs)</span>
                    <span><?php echo count($faqs); ?> pairs</span>
                </div>

                <div class="faq-list">
                    <?php foreach ($faqs as $faq): ?>
                        <article class="faq-item">
                            <div class="faq-q"><?php echo h($faq['question']); ?></div>
                            <div class="faq-a"><?php echo nl2br(h($faq['answer'])); ?></div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <footer class="footer">
                <p>Generated by Vani AI - Knowledge Intelligence Platform</p>
                <p>&copy; <?php echo date('Y'); ?> Vani AI. All rights reserved.</p>
            </footer>
        </section>
    </main>
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
        window.onload = async function() {
            const reportHtml = <?php echo json_encode($reportHtml); ?>; // Safely pass HTML string
            const filename = `vani-ai-export-<?php echo date('Ymd'); ?>.pdf`;
            const frame = document.createElement('iframe');
            frame.style.position = 'fixed';
            frame.style.left = '-10000px';
            frame.style.top = '0';
            frame.style.width = '1200px';
            frame.style.height = '2000px';
            frame.style.opacity = '0';
            frame.srcdoc = reportHtml;
            document.body.appendChild(frame);

            frame.addEventListener('load', async () => {
                try {
                    const frameDoc = frame.contentDocument || frame.contentWindow.document;
                    if (frameDoc?.fonts?.ready) {
                        await frameDoc.fonts.ready;
                    }
                    await new Promise((resolve) => setTimeout(resolve, 250));
                    const images = Array.from(frameDoc?.images || []);
                    await Promise.all(images.map((img) => img.complete ? Promise.resolve() : new Promise((resolve) => {
                        img.addEventListener('load', resolve, { once: true });
                        img.addEventListener('error', resolve, { once: true });
                    })));
                    const reportRoot = frameDoc.querySelector('.report') || frameDoc.body;
                    await html2pdf().set({
                        margin: [8, 8, 8, 8],
                        filename: filename,
                        image: { type: 'jpeg', quality: 1 },
                        html2canvas: {
                            scale: 1.1,
                            useCORS: true,
                            logging: false,
                            scrollY: 0,
                            windowWidth: 1280,
                            backgroundColor: '#f8fafd'
                        },
                        pagebreak: { mode: ['css', 'legacy'], avoid: ['.cover-page', '.page-item', '.faq-item', '.metric-card', '.section-header'] },
                        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
                    }).from(reportRoot).save();
                } catch (error) {
                    document.body.innerHTML = '<p style="font-family:sans-serif;padding:24px;color:#b91c1c;">PDF generation failed. Please try again.</p>';
                    console.error(error);
                } finally {
                    frame.remove();
                }
            }, { once: true });
        };
    </script>
</body>
</html>
<?php exit; endif; ?>
