<style>
.site-menu-trigger{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  flex-direction:column;
  gap:5px;
  width:42px;
  height:42px;
  padding:0;
  border:1px solid rgba(129,140,248,.28);
  border-radius:12px;
  background:rgba(15,23,42,.72);
  color:#f8fafc;
  cursor:pointer;
  box-shadow:0 12px 28px rgba(15,23,42,.18);
}
.site-menu-trigger span{
  display:block;
  width:20px;
  height:2px;
  border-radius:999px;
  background:currentColor;
}
.site-menu-trigger:hover{
  color:#c4b5fd;
  transform:translateY(-1px);
}
.site-menu-overlay{
  position:fixed;
  inset:0;
  background:rgba(2,6,23,.56);
  opacity:0;
  pointer-events:none;
  transition:.22s ease;
  z-index:99990;
}
.site-menu-overlay.show{
  opacity:1;
  pointer-events:auto;
}
.site-side-menu{
  position:fixed;
  top:0;
  right:0;
  width:min(360px,88vw);
  height:100dvh;
  padding:24px;
  background:linear-gradient(180deg,rgba(15,23,42,.98),rgba(30,41,59,.98));
  border-left:1px solid rgba(129,140,248,.3);
  box-shadow:-26px 0 80px rgba(2,6,23,.55);
  transform:translateX(105%);
  transition:.25s ease;
  z-index:100000;
  display:grid;
  align-content:start;
  gap:18px;
}
.site-side-menu.open{
  transform:translateX(0);
}
.site-side-menu-head{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
}
.site-side-menu-head h3{
  color:#f8fafc;
  font-size:20px;
}
.site-menu-close{
  width:40px;
  height:40px;
  border-radius:12px;
  border:1px solid rgba(148,163,184,.22);
  background:#111827;
  color:#e5e7eb;
  cursor:pointer;
  font-size:20px;
}
.site-side-menu-links{
  display:grid;
  gap:12px;
}
.site-side-menu-link{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  min-height:52px;
  padding:0 14px;
  border-radius:14px;
  background:#111827;
  color:#e5e7eb;
  border:1px solid rgba(148,163,184,.22);
  text-decoration:none;
  font-weight:800;
}
.site-side-menu-link.primary{
  background:linear-gradient(135deg,#6366f1,#8b5cf6);
  color:#fff;
  border-color:transparent;
  box-shadow:0 12px 26px rgba(99,102,241,.24);
}
.site-side-menu-link:hover{
  border-color:rgba(196,181,253,.5);
  color:#fff;
}
body.bright .site-menu-trigger,
body:not(.dark).vani-public-theme .site-menu-trigger{
  background:#ffffff;
  color:#3c4043;
  border-color:#dadce0;
  box-shadow:0 1px 2px rgba(60,64,67,.3),0 1px 3px rgba(60,64,67,.15);
}
body.bright .site-menu-overlay,
body:not(.dark).vani-public-theme .site-menu-overlay{
  background:rgba(32,33,36,.32);
}
body.bright .site-side-menu,
body:not(.dark).vani-public-theme .site-side-menu{
  background:linear-gradient(180deg,#ffffff 0%,#f8fafd 100%);
  border-left-color:#dadce0;
  box-shadow:-24px 0 70px rgba(60,64,67,.22);
}
body.bright .site-side-menu-head h3,
body:not(.dark).vani-public-theme .site-side-menu-head h3{
  color:#202124;
}
body.bright .site-menu-close,
body.bright .site-side-menu-link,
body:not(.dark).vani-public-theme .site-menu-close,
body:not(.dark).vani-public-theme .site-side-menu-link{
  background:#ffffff;
  color:#3c4043;
  border-color:#dadce0;
  box-shadow:0 1px 2px rgba(60,64,67,.08);
}
body.bright .site-side-menu-link[data-google-color="blue"],
body:not(.dark).vani-public-theme .site-side-menu-link[data-google-color="blue"]{
  border-left:4px solid #4285f4;
}
body.bright .site-side-menu-link[data-google-color="red"],
body:not(.dark).vani-public-theme .site-side-menu-link[data-google-color="red"]{
  border-left:4px solid #ea4335;
}
body.bright .site-side-menu-link[data-google-color="yellow"],
body:not(.dark).vani-public-theme .site-side-menu-link[data-google-color="yellow"]{
  border-left:4px solid #fbbc04;
}
body.bright .site-side-menu-link[data-google-color="green"],
body:not(.dark).vani-public-theme .site-side-menu-link[data-google-color="green"]{
  border-left:4px solid #34a853;
}
body.bright .site-side-menu-link.primary,
body:not(.dark).vani-public-theme .site-side-menu-link.primary{
  background:linear-gradient(135deg,#4285f4 0%,#34a853 36%,#fbbc04 68%,#ea4335 100%);
  color:#ffffff;
  border-color:transparent;
  box-shadow:0 12px 26px rgba(66,133,244,.22);
}
body.bright .site-side-menu-link.primary[data-google-color="blue"],
body:not(.dark).vani-public-theme .site-side-menu-link.primary[data-google-color="blue"]{
  background:#4285f4;
}
body.bright .site-side-menu-link.primary[data-google-color="red"],
body:not(.dark).vani-public-theme .site-side-menu-link.primary[data-google-color="red"]{
  background:#ea4335;
}
body.bright .site-side-menu-link.primary[data-google-color="yellow"],
body:not(.dark).vani-public-theme .site-side-menu-link.primary[data-google-color="yellow"]{
  background:#fbbc04;
  color:#202124;
}
body.bright .site-side-menu-link.primary[data-google-color="green"],
body:not(.dark).vani-public-theme .site-side-menu-link.primary[data-google-color="green"]{
  background:#34a853;
}
body.bright .site-menu-close:hover,
body.bright .site-side-menu-link:hover,
body:not(.dark).vani-public-theme .site-menu-close:hover,
body:not(.dark).vani-public-theme .site-side-menu-link:hover{
  background:#f8fafd;
  border-color:#d2e3fc;
  color:#1a73e8;
}
body.bright .site-side-menu-link.primary:hover,
body:not(.dark).vani-public-theme .site-side-menu-link.primary:hover{
  color:#ffffff;
}
body.bright .site-side-menu-link.primary[data-google-color="yellow"]:hover,
body:not(.dark).vani-public-theme .site-side-menu-link.primary[data-google-color="yellow"]:hover{
  color:#202124;
}
body.dark .site-side-menu-link.primary{
  background:linear-gradient(135deg,#38bdf8,#2563eb,#0f172a);
}
</style>

<div class="site-menu-overlay" id="siteMenuOverlay" aria-hidden="true"></div>
<aside class="site-side-menu" id="siteSideMenu" aria-label="Site menu">
  <div class="site-side-menu-head">
    <h3>Explore Vani</h3>
    <button class="site-menu-close" type="button" id="siteMenuClose" aria-label="Close menu">×</button>
  </div>
  <div class="site-side-menu-links">
    <?php if (function_exists('is_authenticated_user') && is_authenticated_user()): ?>
      <a class="site-side-menu-link primary" href="dashboard.php">Dashboard <span>→</span></a>
    <?php endif; ?>
    <a class="site-side-menu-link primary" href="index.php">Home <span>→</span></a>
    <?php if (!empty($siteMenuExtraLinks) && is_array($siteMenuExtraLinks)): ?>
      <?php foreach ($siteMenuExtraLinks as $extraLink): ?>
        <?php
          $extraHref = (string)($extraLink['href'] ?? '');
          $extraLabel = (string)($extraLink['label'] ?? '');
        ?>
        <?php if ($extraHref !== '' && $extraLabel !== ''): ?>
          <a class="site-side-menu-link" href="<?php echo htmlspecialchars($extraHref, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($extraLabel, ENT_QUOTES, 'UTF-8'); ?> <span>→</span></a>
        <?php endif; ?>
      <?php endforeach; ?>
    <?php endif; ?>
    <a class="site-side-menu-link" href="subscription.php">Subscription Plans <span>→</span></a>
    <a class="site-side-menu-link" href="terms.php">Terms & Conditions <span>→</span></a>
    <a class="site-side-menu-link" href="privacy-policy.php">Privacy Policy <span>→</span></a>
    <a class="site-side-menu-link" href="cancellation-refund-policy.php">Cancellation & Refund <span>→</span></a>
    <a class="site-side-menu-link" href="contact.php">Contact <span>→</span></a>
    <?php if (function_exists('is_authenticated_user') && is_authenticated_user()): ?>
      <a class="site-side-menu-link" href="logout.php">Logout <span>→</span></a>
    <?php else: ?>
      <a class="site-side-menu-link" href="login.php">Login <span>→</span></a>
    <?php endif; ?>
  </div>
</aside>

<script>
(function(){
  const menu = document.getElementById("siteSideMenu");
  const overlay = document.getElementById("siteMenuOverlay");
  const closeBtn = document.getElementById("siteMenuClose");
  const triggers = document.querySelectorAll(".site-menu-trigger");
  const googleColors = ["blue", "red", "yellow", "green"];

  function assignGoogleMenuColors() {
    let previousColor = "";
    menu?.querySelectorAll(".site-side-menu-link").forEach(link => {
      const availableColors = googleColors.filter(color => color !== previousColor);
      const nextColor = availableColors[Math.floor(Math.random() * availableColors.length)];
      link.dataset.googleColor = nextColor;
      previousColor = nextColor;
    });
  }

  function setSiteMenu(open) {
    if (open) assignGoogleMenuColors();
    menu?.classList.toggle("open", open);
    overlay?.classList.toggle("show", open);
    triggers.forEach(trigger => trigger.setAttribute("aria-expanded", String(open)));
    document.body.style.overflow = open ? "hidden" : "";
  }

  triggers.forEach(trigger => trigger.addEventListener("click", () => setSiteMenu(true)));
  closeBtn?.addEventListener("click", () => setSiteMenu(false));
  overlay?.addEventListener("click", () => setSiteMenu(false));
  menu?.querySelectorAll("a").forEach(link => link.addEventListener("click", () => setSiteMenu(false)));
  document.addEventListener("keydown", event => {
    if (event.key === "Escape") setSiteMenu(false);
  });
})();
</script>
