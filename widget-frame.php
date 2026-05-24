<?php
header_remove('X-Frame-Options');
header('Content-Security-Policy: frame-ancestors *');
$customerId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['id'] ?? ''));
$sourceUrl = filter_var((string)($_GET['source_url'] ?? ''), FILTER_VALIDATE_URL) ? (string)$_GET['source_url'] : '';
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
    <script src="widget.js" data-id="<?php echo htmlspecialchars($customerId, ENT_QUOTES, 'UTF-8'); ?>" data-source-url="<?php echo htmlspecialchars($sourceUrl, ENT_QUOTES, 'UTF-8'); ?>"></script>
  <?php endif; ?>
</body>
</html>
