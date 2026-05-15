<?php include 'auth.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

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
  display:flex;
  align-items:center;
}

.logo img{
  height:85px;
  width:auto;
  transition:0.3s ease;
}

.logo img:hover{
  transform:scale(1.05);
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
        margin-right: -180px;
        margin-top: -115px;
    }
}
    @media (max-width: 768px) {
.nav-a1 {
        margin-right: 0;
        margin-top: -115px;
}
}
@media(max-width:768px){

  .user-box{
    padding:8px 10px;
    max-width: 155px;
    margin-right: -150px;
    margin-top: -115px;
    margin-left: 60px;
  }

  .user-box span{
    font-size:12px;
  }

  .user-avatar{
    width:32px;
    height:32px;
    font-size:13px;
  }
}
</style>
</head>

<body>
<nav>

  <div class="container nav-inner">

    <div class="logo">
      <img src="images/logo.png" alt="Vani AI Logo">
    </div>

   <div class="nav-right">

<?php if(isset($_SESSION['email'])): ?>

  <div class="user-box">

    <div class="user-avatar">
      <?php echo strtoupper(substr($_SESSION['email'],0,1)); ?>
    </div>

    <span>
      <?php echo htmlspecialchars($_SESSION['email']); ?>
    </span>
	 <a href="logout.php" class="nav-btn">Logout</a>
  </div>

<?php else: ?>

  <a href="login.php" class="nav-a1">Login</a>
  <a href="#products" class="nav-btn1">Get Started</a>

<?php endif; ?>

</div>

  </div>

</nav>

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

    <a href="signup.php">
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