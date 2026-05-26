<?php
require_once __DIR__ . '/session-auth.php';

$currentPage = basename($_SERVER['PHP_SELF']);
$navbarClass = ($currentPage === "index.php") ? "navbar-home" : "navbar-inner";
$navEmail = authenticated_email();
?>

<style>

/* =========================
   BASE NAVBAR
========================= */
nav{
  width:100%;
  position:fixed;
  top:0;
  left:0;
  z-index:10000;

  background: rgba(255,255,255,0.85);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);

  border-bottom: 1px solid rgba(99,102,241,0.15);
  box-shadow: 0 6px 20px rgba(0,0,0,0.05);
}

body.dark nav,
body.setup-theme-dark nav,
body.vani-public-theme.dark nav{
  background:rgba(2,12,28,.86);
  border-bottom-color:rgba(56,189,248,.2);
  box-shadow:0 14px 38px rgba(0,0,0,.26);
}

body.bright nav,
body.setup-theme-light nav,
body.vani-public-theme.bright nav{
  background:rgba(255,255,255,.86);
  border-bottom-color:rgba(217,119,6,.18);
  box-shadow:0 14px 38px rgba(180,83,9,.12);
}

/* page spacing */
body{
  padding-top: 80px;
}

/* =========================
   CONTAINER
========================= */
nav .container{
  max-width:1200px;
  width:100%;
  margin:auto;
  padding:0 16px;
}

/* =========================
   INNER LAYOUT
========================= */
.nav-inner{
  display:flex;
  align-items:center;
  justify-content:space-between;
  height:72px;
  gap:16px;
}

/* =========================
   LOGO
========================= */
.logo{
  display:flex;
  align-items:center;
  flex-shrink:0;
  text-decoration:none;
  gap:10px;
}

.logo img{
  height:52px;
  width:52px;
  object-fit:contain;
  transition:0.3s ease;
  filter:drop-shadow(0 0 16px rgba(99,102,241,.38));
}

.logo img:hover{
  transform:scale(1.05);
}

.logo span{
  color:#111827;
  font-size:21px;
  font-weight:800;
  background:linear-gradient(90deg,#4f46e5,#ec4899);
  -webkit-background-clip:text;
  -webkit-text-fill-color:transparent;
  filter:drop-shadow(0 0 12px rgba(99,102,241,.18));
}

/* =========================
   RIGHT SIDE
========================= */
.nav-right{
  display:flex;
  align-items:center;
  gap:10px;
  flex-shrink:0;
}

.nav-actions{
  display:flex;
  align-items:center;
  gap:10px;
}

/* =========================
   LINKS
========================= */
.nav-link{
  display:inline-flex;
  align-items:center;
  justify-content:center;

  height:40px;
  padding:0 14px;

  text-decoration:none;
  color:#334155;
  font-weight:500;
  font-size:15px;

  border-radius:10px;
  white-space:nowrap;
  transition:0.25s ease;
}

.nav-link:hover{
  color:#6366f1;
  background:#f1f5f9;
}

body.dark .nav-link,
body.setup-theme-dark .nav-link,
body.vani-public-theme.dark .nav-link{
  color:#e5e7eb;
}

body.dark .nav-link:hover,
body.setup-theme-dark .nav-link:hover,
body.vani-public-theme.dark .nav-link:hover{
  color:#7dd3fc;
  background:rgba(56,189,248,.12);
}

body.bright .nav-link,
body.setup-theme-light .nav-link,
body.vani-public-theme.bright .nav-link{
  color:#3f2f15;
}

body.bright .nav-link:hover,
body.setup-theme-light .nav-link:hover,
body.vani-public-theme.bright .nav-link:hover{
  color:#92400e;
  background:rgba(245,158,11,.12);
}

nav .site-menu-trigger{
  background:#fff;
  color:#334155;
  border-color:rgba(99,102,241,.16);
  box-shadow:none;
}

/* =========================
   BUTTON
========================= */
.nav-btn{
  background: linear-gradient(135deg,#6366f1,#8b5cf6);
  color:#fff !important;

  height:40px;
  padding:0 16px;

  border-radius:12px;
  font-weight:500;

  display:inline-flex;
  align-items:center;
  justify-content:center;

  box-shadow:0 6px 15px rgba(99,102,241,0.25);
  transition:0.25s ease;
  text-decoration:none;
  white-space:nowrap;
}

.nav-btn:hover{
  transform: translateY(-1px);
  box-shadow:0 10px 20px rgba(99,102,241,0.35);
}

/* =========================
   USER BOX
========================= */
.user-box{
  display:flex;
  align-items:center;
  gap:10px;
  background:#f1f5f9;
  padding:6px 10px;
  border-radius:14px;
  min-width:0;
}

body.dark .user-box,
body.setup-theme-dark .user-box,
body.vani-public-theme.dark .user-box{
  background:rgba(15,23,42,.78);
  border:1px solid rgba(56,189,248,.18);
}

body.bright .user-box,
body.setup-theme-light .user-box,
body.vani-public-theme.bright .user-box{
  background:rgba(255,255,255,.78);
  border:1px solid rgba(217,119,6,.18);
}

.user-avatar{
  width:36px;
  height:36px;
  border-radius:50%;
  background:#6366f1;
  color:#fff;
  display:flex;
  align-items:center;
  justify-content:center;
  font-weight:600;
  flex:0 0 36px;
}

.user-box span{
  font-size:14px;
  color:#334155;
  max-width:160px;
  min-width:0;
  flex:1 1 auto;
  overflow:hidden;
  text-overflow:ellipsis;
  white-space:nowrap;
}

body.dark .user-box span,
body.setup-theme-dark .user-box span,
body.vani-public-theme.dark .user-box span{
  color:#e5e7eb;
}

body.bright .user-box span,
body.setup-theme-light .user-box span,
body.vani-public-theme.bright .user-box span{
  color:#3f2f15;
}

.user-box .nav-btn{
  flex:0 0 auto;
}

/* =========================
   DEFAULT RESPONSIVE
========================= */
@media(max-width:992px){
  .logo img{
    width:46px;
    height:46px;
  }

  .nav-link,
  .nav-btn,
  .user-box{
    display:none;
  }
}

@media(max-width:768px){

  .nav-inner{
    height:64px;
  }

  .logo img{
    width:46px;
    height:46px;
  }

  .nav-right{
    margin-left:auto;
  }
}

@media(max-width:480px){
  .nav-actions{
    gap:6px;
  }
}

/* =========================
   🔥 INDEX PAGE FIX ONLY
========================= */
.navbar-home .nav-inner{
  height:78px;
}

.navbar-home .logo img{
  width:54px;
  height:54px;
}

.navbar-home .nav-right,
.navbar-home .nav-actions{
  display:flex;
  align-items:center;
  flex-wrap:nowrap;
  gap:10px;
}

/* prevent buttons from dropping */
.navbar-home .nav-right{
  white-space:nowrap;
}

/* mobile fix for index */
@media(max-width:768px){
  .navbar-home .logo img{
    width:46px;
    height:46px;
  }

  .navbar-home .nav-link,
  .navbar-home .nav-btn{
    font-size:13px;
    padding:0 10px;
  }
}

@media(max-width:480px){
  .navbar-home .nav-actions{
    gap:6px;
  }
}

</style>

<nav class="<?php echo $navbarClass; ?>">
  <div class="container nav-inner">

    <!-- LOGO -->
    <a class="logo" href="index.php" aria-label="Vani AI home">
      <img src="images/logo_img.png" alt="Vani AI Logo">
      <span>Vani AI</span>
    </a>

    <!-- RIGHT SIDE -->
    <div class="nav-right">

      <?php if(is_authenticated_user()): ?>

        <div class="user-box">
          <div class="user-avatar">
            <?php echo strtoupper(substr($navEmail,0,1)); ?>
          </div>

          <span>
            <?php echo htmlspecialchars($navEmail); ?>
          </span>

          <a href="logout.php" class="nav-btn">Logout</a>
        </div>
        <a href="index.php" class="nav-link">Home</a>
        <button class="site-menu-trigger" type="button" aria-label="Open menu" aria-expanded="false">
          <span></span>
          <span></span>
          <span></span>
        </button>

      <?php else: ?>

        <div class="nav-actions">

          <a href="index.php" class="nav-link">Home</a>
          <a href="login.php" class="nav-link">Login</a>
          <button class="site-menu-trigger" type="button" aria-label="Open menu" aria-expanded="false">
            <span></span>
            <span></span>
            <span></span>
          </button>

          <?php if($currentPage == "index.php"): ?>
            <a href="#products" class="nav-btn">Get Started</a>
          <?php endif; ?>

        </div>

      <?php endif; ?>

    </div>

  </div>
</nav>

<?php include_once __DIR__ . '/site-menu.php'; ?>
