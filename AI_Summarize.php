<?php
require_once __DIR__ . '/session-auth.php';
require_once __DIR__ . '/ai_service.php';

if (!is_authenticated_user()) {
    $_SESSION['auth_return_to'] = 'AI_Summarize.php';
    header('Location: login.php?setup=ai_chatbot&return_to=AI_Summarize.php');
    exit;
}

if (!empty($_SESSION['must_reset_password'])) {
    header('Location: forgot-password.php?forced=1');
    exit;
}

function ai_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ai_summary_text_from_page(array $page): string {
    $summary = $page['summary_json'] ?? [];
    if (is_string($summary)) {
        $decoded = json_decode($summary, true);
        $summary = is_array($decoded) ? $decoded : [];
    }
    return trim((string)($summary['summary'] ?? ''));
}

$email = authenticated_email();
$customerId = (string)($_SESSION['setup_customer_id'] ?? '');
if ($customerId === '') {
    $botRows = ai_safe_rows(supabase(
        'GET',
        'chatbot_signups?select=customer_id&email=eq.' . urlencode($email) . '&order=created_at.desc&limit=1'
    ));
    $customerId = (string)($botRows[0]['customer_id'] ?? '');
}

if ($customerId === '') {
    header('Location: AI_Chatbot_Setup.php');
    exit;
}

$scanId = trim((string)($_GET['scan'] ?? $_SESSION['ai_scan_job_id'] ?? ''));
$scanRows = [];
if ($scanId !== '') {
    $scanRows = ai_safe_rows(supabase(
        'GET',
        'ai_scan_jobs?select=*&id=eq.' . urlencode($scanId) . '&customer_id=eq.' . urlencode($customerId) . '&limit=1'
    ));
}
if (empty($scanRows)) {
    $scanRows = ai_safe_rows(supabase(
        'GET',
        'ai_scan_jobs?select=*&customer_id=eq.' . urlencode($customerId) . '&order=created_at.desc&limit=1'
    ));
}
$scan = $scanRows[0] ?? [];
if (empty($scan)) {
    header('Location: AI_Chatbot_Setup.php');
    exit;
}
$scanId = (string)$scan['id'];
$_SESSION['ai_scan_job_id'] = $scanId;

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $pageId = trim((string)($_POST['page_id'] ?? ''));

    if ($action === 'summarize_page') {
        if (!ai_is_configured()) {
            $error = 'AI provider is not configured.';
        } else {
            $result = ai_summarize_scanned_page($pageId, $customerId);
            $message = !empty($result['success']) ? 'Page summarized.' : '';
            $error = empty($result['success']) ? (string)$result['error'] : '';
        }
    } elseif ($action === 'summarize_all') {
        if (!ai_is_configured()) {
            $error = 'AI provider is not configured.';
        } else {
            $pagesToSummarize = ai_safe_rows(supabase(
                'GET',
                'ai_website_pages?select=id&page_status=neq.summarized&scan_job_id=eq.' . urlencode($scanId)
                    . '&customer_id=eq.' . urlencode($customerId)
                    . '&order=created_at.asc&limit=60'
            ));
            $done = 0;
            $failed = 0;
            foreach ($pagesToSummarize as $page) {
                $result = ai_summarize_scanned_page((string)$page['id'], $customerId);
                if (!empty($result['success'])) {
                    $done++;
                } else {
                    $failed++;
                }
            }
            $message = 'Summarized ' . $done . ' page(s).';
            $error = $failed > 0 ? $failed . ' page(s) could not be summarized.' : '';
        }
    } elseif ($action === 'save_context') {
        $result = ai_update_page_context($pageId, $customerId, (string)($_POST['clean_text'] ?? ''));
        $message = !empty($result['success']) ? 'Page context saved. Please summarize again.' : '';
        $error = empty($result['success']) ? (string)$result['error'] : '';
    } elseif ($action === 'save_summary') {
        $result = ai_update_page_summary($pageId, $customerId, (string)($_POST['summary_text'] ?? ''));
        $message = !empty($result['success']) ? 'Summary saved.' : '';
        $error = empty($result['success']) ? (string)$result['error'] : '';
    } elseif ($action === 'save_faq') {
        $result = ai_update_faq(
            trim((string)($_POST['faq_id'] ?? '')),
            $customerId,
            (string)($_POST['question'] ?? ''),
            (string)($_POST['answer'] ?? '')
        );
        $message = !empty($result['success']) ? 'FAQ saved.' : '';
        $error = empty($result['success']) ? (string)$result['error'] : '';
    } elseif ($action === 'add_faq') {
        $question = trim((string)($_POST['question'] ?? ''));
        $answer = trim((string)($_POST['answer'] ?? ''));
        if ($question === '' || $answer === '') {
            $error = 'Question and answer are required.';
        } else {
            supabase('POST', 'ai_website_faqs', [[
                'scan_job_id' => $scanId,
                'customer_id' => $customerId,
                'page_url' => (string)($_POST['page_url'] ?? $scan['website_url'] ?? ''),
                'question' => $question,
                'answer' => $answer,
                'source' => 'manual'
            ]]);
            $message = 'FAQ added.';
        }
    }
}

$pages = ai_safe_rows(supabase(
    'GET',
    'ai_website_pages?select=*&scan_job_id=eq.' . urlencode($scanId)
        . '&customer_id=eq.' . urlencode($customerId)
        . '&order=created_at.asc&limit=100'
));

$faqs = ai_safe_rows(supabase(
    'GET',
    'ai_website_faqs?select=*&scan_job_id=eq.' . urlencode($scanId)
        . '&customer_id=eq.' . urlencode($customerId)
        . '&order=created_at.asc&limit=500'
));

$summarizedCount = 0;
foreach ($pages as $page) {
    if ((string)($page['page_status'] ?? '') === 'summarized') {
        $summarizedCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="images/logo_img.png">
<title>AI Summarize - Vani AI</title>
<link rel="stylesheet" href="css/public-theme.css">
<script defer src="js/public-theme.js"></script>
<style>
*{box-sizing:border-box;font-family:Inter,Arial,sans-serif}
body{margin:0;background:#f6f8fb;color:#0f172a}
.shell{max-width:1220px;margin:0 auto;padding:34px 18px 70px}
.top{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:22px}
.top h1{margin:0;font-size:32px;line-height:1.15}
.top p{margin:8px 0 0;color:#64748b;line-height:1.6}
.actions{display:flex;gap:10px;flex-wrap:wrap}
button,.btn{min-height:42px;border:0;border-radius:8px;padding:0 14px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
button{background:#2563eb;color:#fff}
.btn{background:#e2e8f0;color:#0f172a}
.grid{display:grid;grid-template-columns:1.45fr .85fr;gap:18px;align-items:start}
.panel{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:18px;box-shadow:0 12px 34px rgba(15,23,42,.06)}
.metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:18px}
.metric{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:14px}
.metric span{display:block;color:#64748b;font-size:12px;font-weight:800}
.metric strong{display:block;margin-top:6px;font-size:22px}
.message{margin-bottom:14px;padding:12px 14px;border-radius:8px;font-weight:800}
.success{background:#dcfce7;color:#166534}.error{background:#fee2e2;color:#991b1b}
.page-item{border-top:1px solid #e2e8f0;padding:18px 0}
.page-item:first-child{border-top:0;padding-top:0}
.url{color:#2563eb;word-break:break-all;font-size:13px}
.status{display:inline-flex;margin:8px 0;padding:4px 8px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:12px;font-weight:800}
textarea,input{width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:10px 12px;font:inherit}
textarea{min-height:120px;resize:vertical;line-height:1.5}
.context{min-height:170px;font-size:13px;color:#334155;background:#f8fafc}
.row{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
.faq{border-top:1px solid #e2e8f0;padding:14px 0}
.faq:first-child{border-top:0}
.muted{color:#64748b;font-size:13px;line-height:1.5}
@media(max-width:900px){.grid,.metrics{grid-template-columns:1fr}.top{display:grid}}
</style>
</head>
<body>
<?php include 'navbar.php'; ?>
<main class="shell">
  <div class="top">
    <div>
      <h1>Review captured website pages</h1>
      <p><?php echo ai_h($scan['website_domain'] ?? ''); ?> pages are captured first. Summaries and FAQs can be reviewed and edited here.</p>
    </div>
    <div class="actions">
      <form method="POST"><input type="hidden" name="action" value="summarize_all"><button type="submit">Summarize all pages</button></form>
      <a class="btn" href="AI_Chatbot_Setup.php">Scan another website</a>
    </div>
  </div>

  <?php if ($message !== ''): ?><div class="message success"><?php echo ai_h($message); ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="message error"><?php echo ai_h($error); ?></div><?php endif; ?>

  <div class="metrics">
    <div class="metric"><span>Captured Pages</span><strong><?php echo count($pages); ?></strong></div>
    <div class="metric"><span>Summarized</span><strong><?php echo $summarizedCount; ?></strong></div>
    <div class="metric"><span>Captured FAQs</span><strong><?php echo count($faqs); ?></strong></div>
    <div class="metric"><span>Scan Status</span><strong><?php echo ai_h($scan['status'] ?? ''); ?></strong></div>
  </div>

  <div class="grid">
    <section class="panel">
      <h2>Pages</h2>
      <?php if (empty($pages)): ?>
        <p class="muted">No pages were captured. Check scan diagnostics or try a sitemap/manual content source.</p>
      <?php endif; ?>
      <?php foreach ($pages as $page): ?>
        <?php $summaryText = ai_summary_text_from_page($page); ?>
        <article class="page-item">
          <strong><?php echo ai_h($page['page_title'] ?: 'Untitled page'); ?></strong>
          <div class="url"><?php echo ai_h($page['url']); ?></div>
          <span class="status"><?php echo ai_h($page['page_status']); ?></span>
          <p class="muted">
            HTTP <?php echo ai_h($page['http_status'] ?? ''); ?>,
            <?php echo ai_h($page['content_length'] ?? 0); ?> bytes,
            <?php echo ai_h($page['discovered_links_count'] ?? 0); ?> links found
          </p>

          <form method="POST">
            <input type="hidden" name="action" value="save_context">
            <input type="hidden" name="page_id" value="<?php echo ai_h($page['id']); ?>">
            <label>Captured context</label>
            <textarea class="context" name="clean_text"><?php echo ai_h($page['clean_text'] ?? ''); ?></textarea>
            <div class="row">
              <button type="submit">Save context</button>
            </div>
          </form>

          <form method="POST">
            <input type="hidden" name="action" value="save_summary">
            <input type="hidden" name="page_id" value="<?php echo ai_h($page['id']); ?>">
            <label>Editable summary</label>
            <textarea name="summary_text" placeholder="Summarize this page to generate editable summary."><?php echo ai_h($summaryText); ?></textarea>
            <div class="row"><button type="submit">Save summary</button></div>
          </form>
          <form method="POST">
            <input type="hidden" name="action" value="summarize_page">
            <input type="hidden" name="page_id" value="<?php echo ai_h($page['id']); ?>">
            <div class="row"><button type="submit">Summarize this page</button></div>
          </form>
          <?php if (!empty($page['ai_error'])): ?><p class="muted"><?php echo ai_h($page['ai_error']); ?></p><?php endif; ?>
        </article>
      <?php endforeach; ?>
    </section>

    <aside class="panel">
      <h2>Captured FAQs</h2>
      <p class="muted">FAQs detected from FAQ schema, HTML accordions, and AI extraction after summarizing FAQ-like pages.</p>
      <form method="POST" class="faq">
        <input type="hidden" name="action" value="add_faq">
        <input type="hidden" name="page_url" value="<?php echo ai_h($scan['website_url'] ?? ''); ?>">
        <label>Add FAQ</label>
        <input name="question" placeholder="Question">
        <textarea name="answer" placeholder="Answer"></textarea>
        <div class="row"><button type="submit">Add FAQ</button></div>
      </form>
      <?php foreach ($faqs as $faq): ?>
        <form method="POST" class="faq">
          <input type="hidden" name="action" value="save_faq">
          <input type="hidden" name="faq_id" value="<?php echo ai_h($faq['id']); ?>">
          <label>Question</label>
          <input name="question" value="<?php echo ai_h($faq['question']); ?>">
          <label>Answer</label>
          <textarea name="answer"><?php echo ai_h($faq['answer']); ?></textarea>
          <p class="muted">Source: <?php echo ai_h($faq['source']); ?></p>
          <div class="row"><button type="submit">Save FAQ</button></div>
        </form>
      <?php endforeach; ?>
    </aside>
  </div>
</main>
</body>
</html>
