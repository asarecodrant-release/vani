<?php
header_remove('X-Frame-Options');
header_remove('Content-Security-Policy');
header('Cache-Control: private, max-age=60, stale-while-revalidate=120');
$customerId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['id'] ?? ''));
$sourceUrl = filter_var((string)($_GET['source_url'] ?? ''), FILTER_VALIDATE_URL) ? (string)$_GET['source_url'] : '';
$forceOpenHint = (string)($_GET['open'] ?? '') === '1';
$openByDefaultHint = $forceOpenHint || (string)($_GET['open_hint'] ?? '') === '1';
$widgetVersion = max(
    is_file(__DIR__ . '/widget.js') ? filemtime(__DIR__ . '/widget.js') : time(),
    is_file(__DIR__ . '/widget-payment.js') ? filemtime(__DIR__ . '/widget-payment.js') : 0
);
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/png" href="images/logo_img.png">
  <meta name="robots" content="noindex,nofollow">
  <title>Vani AI Chatbot</title>
  <style>
    html,
    body {
      width: 100%;
      height: 100%;
      margin: 0;
      overflow: hidden;
      background: transparent;
    }
  </style>
</head>
<body>
  <?php if ($customerId !== ''): ?>
    <script src="widget.js?v=<?php echo (int)$widgetVersion; ?>" data-id="<?php echo htmlspecialchars($customerId, ENT_QUOTES, 'UTF-8'); ?>" data-source-url="<?php echo htmlspecialchars($sourceUrl, ENT_QUOTES, 'UTF-8'); ?>" data-open-default="<?php echo $openByDefaultHint ? '1' : '0'; ?>" data-force-open="<?php echo $forceOpenHint ? '1' : '0'; ?>"></script>
  <?php endif; ?>
</body>
</html>
