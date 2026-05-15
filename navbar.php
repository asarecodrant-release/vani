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
}

.logo img{
  height:70px;
  width:auto;
  object-fit:contain;
  transition:0.3s ease;
}

.logo img:hover{
  transform:scale(1.05);
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
}

.user-box span{
  font-size:14px;
  color:#334155;
  max-width:160px;
  overflow:hidden;
  text-overflow:ellipsis;
  white-space:nowrap;
}

/* =========================
   DEFAULT RESPONSIVE
========================= */
@media(max-width:992px){
  .logo img{
    height:60px;
  }
}

@media(max-width:768px){

  .nav-inner{
    height:64px;
  }

  .logo img{
    height:80px;
  }

  .user-box span{
    display:none;
  }

  .nav-link{
    font-size:14px;
    padding:0 10px;
  }

  .nav-btn{
    font-size:13px;
    padding:0 12px;
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
  height:78px;
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
    height:60px;
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
    <div class="logo">
      <img src="images/logo.png" alt="Vani AI Logo">
    </div>

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

      <?php else: ?>

        <div class="nav-actions">

          <a href="login.php" class="nav-link">Login</a>

          <?php if($currentPage == "index.php"): ?>
            <a href="#products" class="nav-btn">Get Started</a>
          <?php endif; ?>

        </div>

      <?php endif; ?>

    </div>

  </div>
</nav>
