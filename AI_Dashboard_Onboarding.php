<?php
require_once __DIR__ . '/session-auth.php';

if (!is_authenticated_user()) {
    header("Location: login.php");
    exit;
}

$selectedBotId = trim((string)($_GET['bot'] ?? ''));

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="images/logo_img.png">
<title>Upgrade Chatbot to AI - Vani AI</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;font-family:Inter,Arial,sans-serif}
body{min-height:100vh;background:#020706;color:#e7fff7;overflow-x:hidden}
#neuralCanvas{position:fixed;inset:0;width:100%;height:100%;z-index:0;background:
  radial-gradient(circle at 18% 16%,rgba(0,255,170,.18),transparent 32%),
  radial-gradient(circle at 82% 12%,rgba(0,150,255,.16),transparent 30%),
  linear-gradient(135deg,#010403 0%,#03110f 42%,#02080b 100%)}
.page{position:relative;z-index:2;min-height:100vh;padding:28px;display:grid;align-items:center}
.shell{width:min(1180px,100%);margin:0 auto;display:grid;grid-template-columns:minmax(0,1.08fr) minmax(360px,.92fr);gap:24px;align-items:stretch}
.hero,.panel{border:1px solid rgba(148,255,220,.18);background:linear-gradient(145deg,rgba(2,18,16,.82),rgba(4,28,34,.66));box-shadow:0 28px 90px rgba(0,0,0,.38);backdrop-filter:blur(18px);border-radius:24px}
.hero{padding:34px;display:grid;align-content:space-between;min-height:600px;overflow:hidden;position:relative}
.hero:before{content:"";position:absolute;inset:auto -20% -30% 20%;height:240px;background:radial-gradient(circle,rgba(0,255,170,.18),transparent 62%);pointer-events:none}
.brand{display:flex;align-items:center;gap:12px;color:#fff;text-decoration:none;font-weight:900;font-size:22px}
.brand img{width:58px;height:58px;object-fit:contain;filter:drop-shadow(0 0 18px rgba(0,255,170,.48))}
.badge{display:inline-flex;width:fit-content;padding:8px 12px;border:1px solid rgba(250,204,21,.42);border-radius:999px;background:rgba(250,204,21,.12);color:#fde68a;font-weight:900;font-size:12px;letter-spacing:.08em;text-transform:uppercase}
h1{margin:26px 0 16px;font-size:clamp(38px,6vw,76px);line-height:1.02;letter-spacing:0;color:#f8fffb}
.hero p{max-width:720px;color:#b8d8d2;font-size:17px;line-height:1.8}
.actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:26px}
.gold-btn,.dark-btn{min-height:48px;border-radius:14px;padding:0 18px;font-weight:950;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
.gold-btn{background:linear-gradient(135deg,#fef08a,#facc15,#d97706);color:#050505;border:1px solid rgba(234,179,8,.68);box-shadow:0 16px 34px rgba(234,179,8,.28)}
.dark-btn{background:rgba(4,18,22,.78);color:#d7fff5;border:1px solid rgba(148,255,220,.22)}
.mode-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:26px}
.mode{padding:15px;border-radius:18px;border:1px solid rgba(148,255,220,.16);background:rgba(0,0,0,.22)}
.mode strong{display:block;color:#fff;margin-bottom:7px}.mode span{display:block;color:#9ec8c0;font-size:13px;line-height:1.55}
.panel{padding:24px;display:grid;gap:16px;align-content:start}
.panel h2{font-size:24px;color:#fff}
.step{display:grid;grid-template-columns:36px minmax(0,1fr);gap:12px;align-items:start;padding:15px;border-radius:18px;background:rgba(0,0,0,.2);border:1px solid rgba(148,255,220,.13)}
.step i{width:36px;height:36px;border-radius:13px;display:grid;place-items:center;background:rgba(0,255,170,.12);border:1px solid rgba(0,255,170,.3);color:#6ee7b7;font-style:normal;font-weight:950}
.step strong{display:block;color:#f8fffb;margin-bottom:5px}.step span{display:block;color:#a8c8c1;font-size:13px;line-height:1.6}
.notice{padding:15px;border-radius:18px;background:rgba(250,204,21,.1);border:1px solid rgba(250,204,21,.24);color:#fde68a;line-height:1.65;font-size:14px}
.top-links{position:fixed;top:18px;right:18px;z-index:5;display:flex;gap:10px}
.top-links a{min-height:42px;padding:0 14px;border-radius:13px;background:rgba(3,16,18,.76);border:1px solid rgba(148,255,220,.18);color:#e7fff7;text-decoration:none;font-weight:850;display:inline-flex;align-items:center}
@media(max-width:900px){.page{padding:18px;padding-top:72px}.shell{grid-template-columns:1fr}.hero{min-height:auto}.mode-grid{grid-template-columns:1fr}.top-links{left:18px;right:18px;justify-content:space-between}.top-links a{flex:1;justify-content:center}}
@media(max-width:520px){.hero,.panel{border-radius:20px;padding:20px}.actions{display:grid}.gold-btn,.dark-btn{width:100%}}
</style>
</head>
<body>
<canvas id="neuralCanvas" aria-hidden="true"></canvas>
<div class="top-links">
  <a href="dashboard.php<?php echo $selectedBotId !== '' ? '?bot=' . h(urlencode($selectedBotId)) : ''; ?>">Back to Narada</a>
  <a href="logout.php">Logout</a>
</div>
<main class="page">
  <section class="shell">
    <div class="hero">
      <div>
        <a class="brand" href="index.php"><img src="images/logo_img.png" alt="Vani AI"><span>Vani AI</span></a>
        <div style="margin-top:30px"><span class="badge">Premium AI Upgrade</span></div>
        <h1>Upgrade your chatbot into an AI assistant.</h1>
        <p>Move beyond fixed FAQ answers with a managed AI chatbot that can use your FAQs, website content, documents, and customer context while keeping wallet usage under control.</p>
        <div class="actions">
          <a class="gold-btn" href="#upgrade-path">Start AI onboarding</a>
          <a class="dark-btn" href="dashboard.php<?php echo $selectedBotId !== '' ? '?bot=' . h(urlencode($selectedBotId)) : ''; ?>">Continue with FAQ chatbot</a>
        </div>
      </div>
      <div class="mode-grid">
        <div class="mode"><strong>FAQ first</strong><span>Keep trusted approved answers as the default path.</span></div>
        <div class="mode"><strong>AI fallback</strong><span>Use AI when no confident FAQ match is found.</span></div>
        <div class="mode"><strong>Knowledge base</strong><span>Add PDFs, website text, policies, and service details.</span></div>
      </div>
    </div>

    <aside class="panel" id="upgrade-path">
      <h2>AI onboarding path</h2>
      <div class="step"><i>1</i><div><strong>Choose AI mode</strong><span>FAQ only, AI only, or the recommended hybrid mode.</span></div></div>
      <div class="step"><i>2</i><div><strong>Select model access</strong><span>Use Vani shared AI, bring your own API key, or procure managed model access through Vani.</span></div></div>
      <div class="step"><i>3</i><div><strong>Create knowledge base</strong><span>Upload customer documents, website content, service details, and approved FAQs.</span></div></div>
      <div class="step"><i>4</i><div><strong>Set wallet controls</strong><span>Daily spend limit, per-chat cap, fallback behavior, and low-wallet alerts.</span></div></div>
      <div class="notice">This page is the premium AI onboarding entry point. The live AI configuration screens can be added here without crowding the existing FAQ dashboard.</div>
    </aside>
  </section>
</main>
<script>
const canvas = document.getElementById("neuralCanvas");
const ctx = canvas.getContext("2d");
let nodes = [];
function resizeCanvas() {
  const ratio = window.devicePixelRatio || 1;
  canvas.width = Math.floor(window.innerWidth * ratio);
  canvas.height = Math.floor(window.innerHeight * ratio);
  canvas.style.width = window.innerWidth + "px";
  canvas.style.height = window.innerHeight + "px";
  ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
  const count = Math.max(56, Math.min(130, Math.floor(window.innerWidth * window.innerHeight / 11000)));
  nodes = Array.from({length: count}, (_, index) => ({
    x: Math.random() * window.innerWidth,
    y: Math.random() * window.innerHeight,
    vx: (Math.random() - .5) * .38,
    vy: (Math.random() - .5) * .38,
    r: Math.random() < .12 ? 2.7 : 1.7,
    kind: Math.random() < .1 ? "red" : (Math.random() < .24 ? "silver" : "green"),
    phase: Math.random() * Math.PI * 2 + index
  }));
}
function colorFor(node, alpha) {
  if (node.kind === "red") return `rgba(255,50,70,${alpha})`;
  if (node.kind === "silver") return `rgba(220,235,238,${alpha})`;
  return `rgba(0,245,178,${alpha})`;
}
function tick(time) {
  ctx.clearRect(0, 0, window.innerWidth, window.innerHeight);
  for (const node of nodes) {
    node.x += node.vx;
    node.y += node.vy;
    if (node.x < -20) node.x = window.innerWidth + 20;
    if (node.x > window.innerWidth + 20) node.x = -20;
    if (node.y < -20) node.y = window.innerHeight + 20;
    if (node.y > window.innerHeight + 20) node.y = -20;
  }
  for (let i = 0; i < nodes.length; i++) {
    for (let j = i + 1; j < nodes.length; j++) {
      const a = nodes[i], b = nodes[j];
      const dx = a.x - b.x, dy = a.y - b.y;
      const dist = Math.sqrt(dx * dx + dy * dy);
      if (dist < 132) {
        const alpha = (1 - dist / 132) * .26;
        ctx.strokeStyle = `rgba(0,185,210,${alpha})`;
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(a.x, a.y);
        ctx.lineTo(b.x, b.y);
        ctx.stroke();
      }
    }
  }
  for (const node of nodes) {
    const blink = .38 + Math.abs(Math.sin(time / 520 + node.phase)) * .62;
    ctx.fillStyle = colorFor(node, blink);
    ctx.shadowColor = colorFor(node, .9);
    ctx.shadowBlur = node.kind === "red" ? 14 : 9;
    ctx.beginPath();
    ctx.arc(node.x, node.y, node.r * blink, 0, Math.PI * 2);
    ctx.fill();
  }
  ctx.shadowBlur = 0;
  requestAnimationFrame(tick);
}
resizeCanvas();
window.addEventListener("resize", resizeCanvas);
requestAnimationFrame(tick);
</script>
</body>
</html>
