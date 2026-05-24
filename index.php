<?php
include 'auth.php';
$notice = (string)($_GET['notice'] ?? '');
$showSelectProductNotice = is_authenticated_user() && $notice === 'select_product';
$showResetPasswordNotice = is_authenticated_user() && (string)($_GET['reset_password'] ?? '') === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="images/logo_img.png">

<title>Vani – AI Chatbot Platform</title>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>

*{
  margin:0;
  padding:0;
  box-sizing:border-box;
  font-family:'Inter',sans-serif;
}

html{
  scroll-behavior:smooth;
}

body{
  background:
    linear-gradient(135deg,#f0f9ff,#eef2ff,#faf5ff);
  color:#0f172a;
  overflow-x:hidden;
  transition:background .25s ease,color .25s ease;
}

body.dark{
  background:
    radial-gradient(circle at top left,rgba(99,102,241,.34),transparent 34%),
    radial-gradient(circle at 85% 10%,rgba(236,72,153,.24),transparent 28%),
    linear-gradient(135deg,#020617 0%,#08111f 46%,#111827 100%);
  color:#f8fafc;
}

/* =========================
   CONTAINER
========================= */
.container{
  width:100%;
  max-width:1280px;
  margin:auto;
  padding:0 20px;
}

/* =========================
   NAVBAR
========================= */
nav{
  width:100%;
  padding:16px 0;
}

.nav-inner{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:20px;
}

.logo{
  display:inline-flex;
  align-items:center;
  text-decoration:none;
}

.logo img{
  height:85px;
  width:auto;
  transition:0.3s ease;
}

.logo:hover img{
  transform:scale(1.05);
}

.logo-dark{
  display:none;
  align-items:center;
  gap:12px;
  position:relative;
  padding:7px 10px 7px 6px;
  border-radius:16px;
  background:linear-gradient(135deg,rgba(99,102,241,.12),rgba(236,72,153,.1));
  border:1px solid rgba(129,140,248,.18);
}

.logo-dark img{
  width:54px;
  height:54px;
  object-fit:contain;
  filter:drop-shadow(0 0 18px rgba(99,102,241,.7)) drop-shadow(0 0 24px rgba(236,72,153,.28));
}

.logo-dark span{
  background:linear-gradient(90deg,#ffffff,#c4b5fd 48%,#f9a8d4);
  -webkit-background-clip:text;
  -webkit-text-fill-color:transparent;
  font-size:23px;
  font-weight:800;
  letter-spacing:0;
  line-height:1;
  white-space:nowrap;
  filter:drop-shadow(0 0 14px rgba(129,140,248,.28));
}

body.dark .logo-light{
  display:none;
}

body.dark .logo-dark{
  display:flex;
}

.nav-links{
  display:flex;
  align-items:center;
  gap:14px;
  flex-wrap:wrap;
}

.nav-links a{
  text-decoration:none;
  color:#334155;
  font-weight:500;
  font-size:15px;
  transition:0.25s ease;
}

.nav-links a:hover{
  color:#6366f1;
}

.nav-btn{
  background: linear-gradient(45deg,#6366f1,#8b5cf6);
  color:#fff !important;
  padding:11px 18px;
  border-radius:10px;
  text-decoration:none;   /* force remove underline */
}
.nav-btn:hover{
 transform:scale(1.03);
}
.nav-links a.nav-btn{
  text-decoration:none !important;
}
    

.nav-btn1{
  background: linear-gradient(45deg,#6366f1,#8b5cf6);
  color:#fff !important;
  padding:11px 18px;
  border-radius:10px;
  text-decoration:none;   /* force remove underline */
}
.nav-btn1:hover{
 transform:scale(1.03);
}
.nav-links a.nav-btn1{
  text-decoration:none !important;
}    
.customer-notice{
  max-width:1180px;
  margin:0 auto 18px;
  padding:0 20px;
}
.customer-notice-card{
  border:1px solid rgba(99,102,241,.22);
  border-radius:18px;
  background:linear-gradient(135deg,rgba(99,102,241,.12),rgba(236,72,153,.08));
  padding:16px 18px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:14px;
  box-shadow:0 18px 42px rgba(99,102,241,.12);
}
.customer-notice-card strong{display:block;font-size:16px;margin-bottom:4px}
.customer-notice-card p{color:#475569;line-height:1.55;font-size:14px}
body.dark .customer-notice-card{background:linear-gradient(135deg,rgba(99,102,241,.2),rgba(236,72,153,.13));border-color:rgba(129,140,248,.24)}
body.dark .customer-notice-card p{color:#cbd5e1}
@media(max-width:720px){.customer-notice-card{display:grid}.customer-notice-card .nav-btn{width:100%;text-align:center}}
/* =========================
   HERO
========================= */
.hero{
  padding:20px 0 40px;
  text-align:center;
  animation:fadeUp 0.8s ease;
}

.hero-badge{
  display:inline-flex;
  align-items:center;
  gap:8px;
  background:#ffffffcc;
  border:1px solid rgba(99,102,241,0.15);
  padding:10px 16px;
  border-radius:999px;
  font-size:14px;
  color:#4f46e5;
  margin-bottom:24px;
  backdrop-filter:blur(8px);
}

.hero h1{
  font-size:62px;
  line-height:1.15;
  font-weight:700;
  letter-spacing:-1.5px;
  background:
    linear-gradient(90deg,#6366f1,#ec4899);
  -webkit-background-clip:text;
  -webkit-text-fill-color:transparent;
}

.hero p{
  margin-top:24px;
  color:#475569;
  max-width:850px;
  margin-left:auto;
  margin-right:auto;
  font-size:19px;
  line-height:1.8;
}

.hero-buttons{
  margin-top:36px;
  display:flex;
  justify-content:center;
  gap:16px;
  flex-wrap:wrap;
}

.primary-btn,
.secondary-btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  text-decoration:none;
  padding:15px 24px;
  border-radius:12px;
  font-weight:600;
  transition:0.3s ease;
  min-width:180px;
}

.primary-btn{
  background:
    linear-gradient(45deg,#6366f1,#ec4899);
  color:#fff;
  box-shadow:
    0 12px 25px rgba(99,102,241,0.25);
}

.primary-btn:hover{
  transform:translateY(-2px);
}

.secondary-btn{
  background:#ffffff;
  color:#334155;
  border:1px solid #e2e8f0;
}

.secondary-btn:hover{
  transform:translateY(-2px);
}

/* =========================
   PRODUCTS
========================= */
.products{
  display:grid;
  grid-template-columns:
    repeat(auto-fit,minmax(320px,1fr));
  gap:28px;
  margin-top:30px;
  padding-bottom:20px;
}

.card{
  background:rgba(255,255,255,0.75);
  backdrop-filter:blur(14px);
  border:1px solid rgba(255,255,255,0.4);
  border-radius:22px;
  padding:32px;
  box-shadow:
    0 12px 30px rgba(15,23,42,0.08);

  transition:0.4s ease;

  transform:translateY(40px);
  opacity:0;
}

.card.show{
  transform:translateY(0);
  opacity:1;
}

.card:hover{
  transform:translateY(-8px);
}

.card-icon{
  font-size:42px;
  margin-bottom:18px;
}

.card h2{
  font-size:28px;
  margin-bottom:14px;
  color:#111827;
}

.card p{
  color:#475569;
  line-height:1.8;
  font-size:15px;
}

.feature-list{
  margin-top:22px;
  padding-left:18px;
}

.feature-list li{
  margin-bottom:10px;
  color:#334155;
  line-height:1.7;
}

.download-button{
  display:inline-block;
  margin-top:26px;
  padding:13px 22px;
  border-radius:12px;
  text-decoration:none;
  background:
    linear-gradient(45deg,#6366f1,#ec4899);
  color:#fff;
  font-weight:600;
  transition:0.3s ease;
}

.download-button:hover{
  transform:scale(1.03);
}

/* =========================
   CTA
========================= */
.cta{
  margin-top:90px;
  margin-bottom:50px;
  border-radius:28px;
  overflow:hidden;
  background:
    linear-gradient(135deg,#6366f1,#ec4899);
  color:#fff;
  text-align:center;
  padding:70px 30px;
  position:relative;
}

.cta h2{
  font-size:42px;
  line-height:1.3;
}

.cta p{
  margin-top:16px;
  font-size:18px;
  opacity:0.95;
}

.cta a{
  text-decoration:none;
}

.cta button{
  margin-top:30px;
  padding:15px 28px;
  border:none;
  border-radius:12px;
  background:#fff;
  color:#111827;
  font-weight:700;
  font-size:15px;
  cursor:pointer;
  transition:0.3s ease;
}

.cta button:hover{
  transform:translateY(-2px);
}

/* =========================
   FOOTER
========================= */
footer{
  text-align:center;
  padding:30px 20px 40px;
  color:#64748b;
  font-size:14px;
}

/* =========================
   ANIMATION
========================= */
@keyframes fadeUp{
  from{
    opacity:0;
    transform:translateY(30px);
  }
  to{
    opacity:1;
    transform:translateY(0);
  }
}

/* =========================
   TABLET
========================= */
@media (max-width:992px){

  .hero h1{
    font-size:50px;
  }

  .hero p{
    font-size:17px;
  }

  .cta h2{
    font-size:34px;
  }

  .nav-a1{
    display:none;
  }

  .user-box{
    display:none;
  }

}

/* =========================
   MOBILE
========================= */
@media (max-width:768px){

  .container{
    padding:0 16px;
  }

  .nav-inner{
    flex-direction:column;
    justify-content:center;
  }

  .logo img{
    height:80px;
      margin-right: 220px;
        margin-top: -20px;
  }

  .logo-dark img{
    width:46px;
    height:46px;
    margin:0;
  }

  .logo-dark span{
    font-size:20px;
  }

  .nav-links{
    justify-content:center;
  }

  .hero{
    padding-top:10px;
  }

  .hero h1{
    font-size:38px;
    line-height:1.25;
  }

  .hero p{
    font-size:16px;
    line-height:1.8;
    margin-top:18px;
  }

  .hero-buttons{
    flex-direction:column;
    align-items:center;
  }

  .primary-btn,
  .secondary-btn{
    width:100%;
    max-width:320px;
  }

  .products{
    grid-template-columns:1fr;
    gap:22px;
    margin-top:24px;
  }

  .card{
    padding:26px;
  }

  .card h2{
    font-size:24px;
  }

  .cta{
    margin-top:70px;
    padding:55px 22px;
    border-radius:22px;
  }

  .cta h2{
    font-size:30px;
  }

  .cta p{
    font-size:16px;
  }

  .cta button{
    width:100%;
    max-width:300px;
  }

}

/* =========================
   SMALL MOBILE
========================= */
@media (max-width:480px){

  .hero-badge{
    font-size:13px;
    padding:8px 14px;
  }

  .hero h1{
    font-size:32px;
  }

  .hero p{
    font-size:15px;
  }

  .card{
    padding:22px;
    border-radius:18px;
  }

  .card h2{
    font-size:22px;
  }

  .card p,
  .feature-list li{
    font-size:14px;
  }

  .download-button{
    width:100%;
    text-align:center;
  }

  .cta h2{
    font-size:26px;
  }

}
.nav-right{
  display:flex;
  align-items:center;
  gap:14px;
}

.nav-a1{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height:42px;
  padding:0 16px;
  border-radius:12px;
  background:rgba(255,255,255,.75);
  border:1px solid rgba(99,102,241,.16);
  color:#334155;
  text-decoration:none;
  font-weight:700;
  cursor:pointer;
}

.nav-menu-btn,.theme-btn{
  border:1px solid rgba(99,102,241,.18);
  background:rgba(255,255,255,.78);
  color:#334155;
  min-height:42px;
  padding:0 16px;
  border-radius:12px;
  font-weight:800;
  cursor:pointer;
}

.nav-menu-btn{
  display:inline-flex;
  align-items:center;
  width:44px;
  padding:0;
  gap:5px;
  flex-direction:column;
  justify-content:center;
}

.nav-menu-btn span{
  display:block;
  width:20px;
  height:2px;
  border-radius:999px;
  background:currentColor;
}

.nav-menu-btn:hover,.theme-btn:hover,.nav-a1:hover{
  color:#4f46e5;
  transform:translateY(-1px);
}

.menu-overlay{
  position:fixed;
  inset:0;
  background:rgba(15,23,42,.38);
  opacity:0;
  pointer-events:none;
  transition:.22s ease;
  z-index:90;
}

.menu-overlay.show{
  opacity:1;
  pointer-events:auto;
}

.side-menu{
  position:fixed;
  top:0;
  right:0;
  width:min(360px,88vw);
  height:100dvh;
  padding:24px;
  background:rgba(255,255,255,.96);
  border-left:1px solid rgba(99,102,241,.16);
  box-shadow:-24px 0 60px rgba(15,23,42,.18);
  transform:translateX(105%);
  transition:.25s ease;
  z-index:100;
  display:grid;
  align-content:start;
  gap:18px;
}

.side-menu.open{
  transform:translateX(0);
}

.side-menu-head{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
}

.side-menu-head h3{
  font-size:20px;
}

.menu-close{
  width:40px;
  height:40px;
  border-radius:12px;
  border:1px solid rgba(99,102,241,.18);
  background:#fff;
  cursor:pointer;
  font-size:20px;
}

.side-menu-links{
  display:grid;
  gap:12px;
}

.side-menu-link{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  min-height:52px;
  padding:0 14px;
  border-radius:14px;
  background:linear-gradient(135deg,#6366f1,#8b5cf6);
  color:#fff;
  text-decoration:none;
  font-weight:800;
  box-shadow:0 12px 26px rgba(99,102,241,.24);
}

.side-menu-link.secondary{
  background:#f8fafc;
  color:#334155;
  border:1px solid #e5e7eb;
  box-shadow:none;
}

body.dark .hero-badge,
body.dark .card,
body.dark .nav-a1,
body.dark .nav-menu-btn,
body.dark .theme-btn,
body.dark .user-box{
  background:rgba(15,23,42,.72);
  border-color:rgba(148,163,184,.22);
  color:#e5e7eb;
}

body.dark .hero-badge{
  background:rgba(15,23,42,.78);
  border-color:rgba(129,140,248,.34);
  color:#c4b5fd;
  box-shadow:0 14px 36px rgba(79,70,229,.18);
}

body.dark .card{
  background:linear-gradient(145deg,rgba(15,23,42,.88),rgba(30,41,59,.7));
  border-color:rgba(129,140,248,.24);
  box-shadow:0 22px 55px rgba(0,0,0,.32), inset 0 1px 0 rgba(255,255,255,.04);
}

body.dark .card:hover{
  box-shadow:0 26px 70px rgba(79,70,229,.22), inset 0 1px 0 rgba(255,255,255,.06);
}

body.dark .hero p,
body.dark .card p,
body.dark .feature-list li,
body.dark .cta p,
body.dark footer{
  color:#cbd5e1;
}

body.dark .card h2,
body.dark .side-menu-head h3{
  color:#f8fafc;
}

body.dark .secondary-btn{
  background:rgba(15,23,42,.8);
  border-color:rgba(129,140,248,.34);
  color:#f8fafc;
  box-shadow:0 16px 36px rgba(2,6,23,.28);
}

body.dark .download-button,
body.dark .primary-btn{
  box-shadow:0 18px 42px rgba(236,72,153,.22);
}

body.dark .cta{
  background:
    radial-gradient(circle at top left,rgba(255,255,255,.22),transparent 32%),
    linear-gradient(135deg,#4f46e5,#db2777);
  box-shadow:0 24px 72px rgba(79,70,229,.28);
}

body.dark .side-menu{
  background:linear-gradient(180deg,rgba(15,23,42,.98),rgba(30,41,59,.98));
  border-color:rgba(129,140,248,.3);
  box-shadow:-26px 0 80px rgba(2,6,23,.55);
}

body.dark .menu-close,
body.dark .side-menu-link.secondary{
  background:#111827;
  border-color:rgba(148,163,184,.22);
  color:#e5e7eb;
}

.theme-choice-panel{
  position:fixed;
  right:20px;
  bottom:20px;
  z-index:1200;
  width:min(340px,calc(100vw - 32px));
  padding:18px;
  border-radius:18px;
  background:rgba(255,255,255,.94);
  border:1px solid rgba(99,102,241,.16);
  box-shadow:0 22px 60px rgba(15,23,42,.18);
  display:none;
}

.theme-choice-panel.show{display:block}
.theme-choice-panel strong{display:block;color:#111827;font-size:17px;margin-bottom:6px}
.theme-choice-panel p{color:#64748b;font-size:13px;line-height:1.55;margin-bottom:14px}
.theme-choice-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.theme-choice-actions button{
  min-height:42px;
  border-radius:12px;
  border:1px solid rgba(99,102,241,.18);
  cursor:pointer;
  font-weight:800;
}
.theme-choice-bright{background:#fff;color:#334155}
.theme-choice-dark{background:#111827;color:#f8fafc}
body.dark .theme-choice-panel{
  background:rgba(15,23,42,.96);
  border-color:rgba(129,140,248,.28);
  box-shadow:0 22px 60px rgba(0,0,0,.36);
}
body.dark .theme-choice-panel strong{color:#f8fafc}
body.dark .theme-choice-panel p{color:#cbd5e1}

.user-box{
  display:flex;
  align-items:center;
  gap:10px;

  background:rgba(255,255,255,0.7);
  padding:10px 14px;
  border-radius:14px;

  backdrop-filter:blur(10px);

  box-shadow:
    0 4px 15px rgba(0,0,0,0.06);

  font-size:14px;
  font-weight:600;
  color:#334155;

  max-width:260px;
}

.user-avatar{
  width:36px;
  height:36px;
  border-radius:50%;

  background:
    linear-gradient(135deg,#6366f1,#ec4899);

  color:white;

  display:flex;
  align-items:center;
  justify-content:center;

  font-size:15px;
  font-weight:700;

  flex-shrink:0;
}

.user-box span{
  overflow:hidden;
  text-overflow:ellipsis;
  white-space:nowrap;
}
@media (max-width: 768px) {
    .nav-btn1 {
        margin-right: 0;
        margin-top: 0;
    }
}
    @media (max-width: 768px) {
.nav-a1 {
        margin-right: 0;
        margin-top: 0;
}
}
@media(max-width:768px){

  .nav-inner{
    flex-direction:row;
    justify-content:space-between;
  }

  .logo img{
    height:64px;
    margin:0;
  }

  .logo{
    max-width:58%;
  }

  .logo-light{
    max-width:100%;
    height:auto;
  }

  .logo-dark img{
    width:46px;
    height:46px;
  }

  .logo-dark span{
    font-size:20px;
  }

  .user-box{
    padding:8px 10px;
    max-width: 170px;
  }

  .user-box span{
    font-size:12px;
  }

  .user-avatar{
    width:32px;
    height:32px;
    font-size:13px;
  }

  .nav-right{
    justify-content:flex-end;
    flex-wrap:nowrap;
  }

  .theme-btn,.nav-a1,.nav-btn1{
    min-height:40px;
    padding:0 12px;
    font-size:13px;
  }

  .nav-menu-btn{
    display:inline-flex;
    min-width:42px;
    min-height:40px;
    padding:0;
  }
}
</style>
</head>

<body>
<nav>

  <div class="container nav-inner">

    <a class="logo" href="index.php" aria-label="Vani AI home">
      <img class="logo-light" src="images/logo.png" alt="Vani AI Logo">
      <span class="logo-dark" aria-hidden="true">
        <img src="images/logo_img.png" alt="">
        <span>Vani AI</span>
      </span>
    </a>

   <div class="nav-right">

<?php if(is_authenticated_user()): ?>

  <div class="user-box">

    <div class="user-avatar">
      <?php echo strtoupper(substr(authenticated_email(),0,1)); ?>
    </div>

    <span>
      <?php echo htmlspecialchars(authenticated_email()); ?>
    </span>
	 <a href="logout.php" class="nav-btn">Logout</a>
  </div>
  <button class="nav-menu-btn" type="button" id="menuToggle" aria-label="Open menu" aria-expanded="false">
    <span></span>
    <span></span>
    <span></span>
  </button>

<?php else: ?>

  <a href="login.php" class="nav-a1">Login</a>
  <button class="nav-menu-btn" type="button" id="menuToggle" aria-label="Open menu" aria-expanded="false">
    <span></span>
    <span></span>
    <span></span>
  </button>

<?php endif; ?>

</div>

  </div>

</nav>

<?php if ($showSelectProductNotice): ?>
  <div class="customer-notice">
    <div class="customer-notice-card">
      <div>
        <strong><?php echo $showResetPasswordNotice ? 'Subscription activated. Reset your password, then create your chatbot.' : 'Select your product to continue.'; ?></strong>
        <p>
          <?php if ($showResetPasswordNotice): ?>
            For security, use Forgot Password on the login page to reset the temporary password. Then create your chatbot and your subscription will be assigned automatically.
          <?php else: ?>
            You do not have a chatbot yet. Create your Vani AI chatbot first, then your dashboard will become available.
          <?php endif; ?>
        </p>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <?php if ($showResetPasswordNotice): ?><a class="nav-btn" href="logout.php?next=forgot-password.php">Reset password</a><?php endif; ?>
        <a class="nav-btn" href="freebot.php">Create chatbot</a>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="menu-overlay" id="menuOverlay" aria-hidden="true"></div>
<aside class="side-menu" id="sideMenu" aria-label="Site menu">
  <div class="side-menu-head">
    <h3>Explore Vani</h3>
    <button class="menu-close" type="button" id="menuClose" aria-label="Close menu">×</button>
  </div>
  <div class="side-menu-links">
    <button class="theme-btn" type="button" id="themeToggle">Dark / Bright</button>
    <?php if(is_authenticated_user()): ?>
      <a class="side-menu-link" href="dashboard.php">Dashboard <span>→</span></a>
    <?php endif; ?>
    <a class="side-menu-link secondary" href="index.php">Home <span>→</span></a>
    <a class="side-menu-link" href="subscription.php">Subscription Plans <span>→</span></a>
    <a class="side-menu-link secondary" href="#products">Products <span>→</span></a>
    <a class="side-menu-link secondary" href="terms.php">Terms & Conditions <span>→</span></a>
    <a class="side-menu-link secondary" href="privacy-policy.php">Privacy Policy <span>→</span></a>
    <a class="side-menu-link secondary" href="cancellation-refund-policy.php">Cancellation & Refund <span>→</span></a>
    <a class="side-menu-link secondary" href="contact.php">Contact <span>→</span></a>
    <?php if(is_authenticated_user()): ?>
      <a class="side-menu-link secondary" href="logout.php">Logout <span>→</span></a>
    <?php else: ?>
      <a class="side-menu-link secondary" href="login.php">Login <span>→</span></a>
    <?php endif; ?>
  </div>
</aside>

<div class="theme-choice-panel" id="themeChoicePanel" role="dialog" aria-live="polite" aria-label="Choose site theme">
  <strong>Choose your theme</strong>
  <p>Select once and Vani AI will keep the same theme across website, login, setup, and dashboard pages.</p>
  <div class="theme-choice-actions">
    <button class="theme-choice-bright" type="button" data-theme-choice="bright">Bright</button>
    <button class="theme-choice-dark" type="button" data-theme-choice="dark">Dark</button>
  </div>
</div>

<!-- HERO -->
<section class="hero">

  <div class="container">

    <div class="hero-badge">
      🚀 AI Chatbots for Modern Businesses
    </div>

    <h1>
      Turn Your Website Into <br>
      a 24/7 Sales & Support Machine
    </h1>

    <p>
      Offer your customers a ready-to-use chatbot powered by your FAQs.
      Select the version that best fits your needs — a lightweight free bot
      or a powerful AI-driven chatbot for smarter engagement.
    </p>

    <div class="hero-buttons">

      <a href="freebot.php" class="primary-btn">
        Start Free →
      </a>

      <a href="#products" class="secondary-btn">
        Explore Products
      </a>

    </div>

  </div>

</section>

<!-- PRODUCTS -->
<section class="container">

  <div class="products" id="products">

    <!-- FREE -->
    <div class="card">

      <div class="card-icon">💬</div>

      <h2>Free FAQ Chatbot</h2>

      <p>
        Perfect for businesses that want a simple FAQ-driven chatbot
        without advanced AI complexity.
      </p>

      <ul class="feature-list">
        <li>FAQ-based conversation flow</li>
        <li>No AI training required</li>
        <li>Fast setup & lightweight performance</li>
        <li>Responsive chatbot widget</li>
      </ul>

      <a class="download-button" href="freebot.php">
        Get Started Free
      </a>

    </div>

    <!-- AI -->
    <div class="card">

      <div class="card-icon">🧠</div>

      <h2>AI-Powered Chatbot</h2>

      <p>
        Advanced AI chatbot with intelligent conversations,
        contextual understanding and smarter automation.
      </p>

      <ul class="feature-list">
        <li>Natural language understanding</li>
        <li>Context-aware AI replies</li>
        <li>AI-powered support automation</li>
        <li>Smart learning system</li>
      </ul>

      <a class="download-button" href="#" style="opacity:.65;pointer-events:none;">
        Coming Soon
      </a>

    </div>

  </div>

</section>

<!-- CTA -->
<section class="container">

  <div class="cta">

    <h2>
      Start Automating Your Website Today
    </h2>

    <p>
      No credit card required • Setup in minutes
    </p>

    <a href="freebot.php">
      <button>
        Get Started Free
      </button>
    </a>

  </div>

</section>

<!-- FOOTER -->
<footer>
  © <?php echo date("Y"); ?> Vani AI by Codrant
</footer>

<script>

const menuToggle = document.getElementById("menuToggle");
const menuClose = document.getElementById("menuClose");
const menuOverlay = document.getElementById("menuOverlay");
const sideMenu = document.getElementById("sideMenu");
const themeToggle = document.getElementById("themeToggle");
const themeChoicePanel = document.getElementById("themeChoicePanel");

function setMenu(open) {
  sideMenu?.classList.toggle("open", open);
  menuOverlay?.classList.toggle("show", open);
  menuToggle?.setAttribute("aria-expanded", String(open));
  document.body.style.overflow = open ? "hidden" : "";
}

function setTheme(mode) {
  const dark = mode === "dark";
  document.body.classList.toggle("dark", dark);
  if (themeToggle) {
    themeToggle.setAttribute("aria-pressed", String(dark));
    themeToggle.textContent = dark ? "Bright Mode" : "Dark Mode";
  }
  localStorage.setItem("vani-index-theme", dark ? "dark" : "bright");
  localStorage.removeItem("vani_dashboard_theme");
  localStorage.removeItem("vani_setup_theme");
}

const savedTheme = localStorage.getItem("vani-index-theme") || localStorage.getItem("vani_dashboard_theme") || localStorage.getItem("vani_setup_theme");
setTheme(savedTheme === "dark" ? "dark" : "bright");
if (!savedTheme) {
  themeChoicePanel?.classList.add("show");
}

menuToggle?.addEventListener("click", () => setMenu(true));
menuClose?.addEventListener("click", () => setMenu(false));
menuOverlay?.addEventListener("click", () => setMenu(false));
sideMenu?.querySelectorAll("a").forEach(link => {
  link.addEventListener("click", () => setMenu(false));
});
document.addEventListener("keydown", event => {
  if (event.key === "Escape") setMenu(false);
});
themeToggle?.addEventListener("click", () => {
  setTheme(document.body.classList.contains("dark") ? "bright" : "dark");
  themeChoicePanel?.classList.remove("show");
});

document.querySelectorAll("[data-theme-choice]").forEach(button => {
  button.addEventListener("click", () => {
    setTheme(button.dataset.themeChoice === "dark" ? "dark" : "bright");
    themeChoicePanel?.classList.remove("show");
  });
});

// Scroll animation
const cards = document.querySelectorAll('.card');

const observer = new IntersectionObserver(entries => {

  entries.forEach(entry => {

    if(entry.isIntersecting){

      entry.target.classList.add('show');
    }

  });

});

cards.forEach(card => observer.observe(card));

</script>

</body>
</html>
