(function(){
  function savedTheme(){
    return localStorage.getItem("vani-index-theme")
      || localStorage.getItem("vani_dashboard_theme")
      || localStorage.getItem("vani_setup_theme")
      || "dark";
  }
  function setPublicTheme(mode){
    const dark = mode !== "bright";
    document.body.classList.add("vani-public-theme");
    document.body.classList.toggle("dark", dark);
    document.body.classList.toggle("bright", !dark);
    const themeValue = dark ? "dark" : "bright";
    localStorage.setItem("vani-index-theme", themeValue);
    localStorage.setItem("vani_dashboard_theme", themeValue);
    localStorage.setItem("vani_setup_theme", themeValue);
    document.querySelectorAll("#themeToggle,[data-theme-toggle]").forEach(button => {
      button.textContent = dark ? "Bright Mode" : "Dark Mode";
      button.setAttribute("aria-pressed", String(dark));
    });
  }
  setPublicTheme(savedTheme());
  document.addEventListener("click", event => {
    const button = event.target.closest("#themeToggle,[data-theme-toggle]");
    if (!button) return;
    event.preventDefault();
    setPublicTheme(document.body.classList.contains("dark") ? "bright" : "dark");
  });
})();
