(function () {
  const storageKey = "vani-index-theme";
  const lightClass = "setup-theme-light";
  const darkClass = "setup-theme-dark";

  function preferredTheme() {
    const saved = localStorage.getItem(storageKey) || localStorage.getItem("vani_dashboard_theme") || localStorage.getItem("vani_setup_theme");
    if (saved === "bright") {
      return "light";
    }
    if (saved === "light" || saved === "dark") {
      return saved;
    }
    return "bright";
  }

  function applyTheme(theme) {
    document.body.classList.toggle(lightClass, theme === "light");
    document.body.classList.toggle(darkClass, theme === "dark");
    document.body.classList.toggle("bright", theme === "light");
    document.body.classList.toggle("dark", theme === "dark");
    const themeValue = theme === "dark" ? "dark" : "bright";
    localStorage.setItem(storageKey, themeValue);
    localStorage.setItem("vani_dashboard_theme", themeValue);
    localStorage.setItem("vani_setup_theme", themeValue);

    const toggle = document.getElementById("setupThemeToggle");
    if (!toggle) {
      return;
    }
    const nextTheme = theme === "dark" ? "bright" : "dark";
    const label = toggle.querySelector("[data-theme-label]");
    toggle.setAttribute("aria-label", "Switch to " + nextTheme + " theme");
    toggle.setAttribute("title", "Switch to " + nextTheme + " theme");
    if (label) {
      label.textContent = theme === "dark" ? "Bright theme" : "Dark theme";
    }
  }

  window.vaniApplySetupTheme = applyTheme;

  document.addEventListener("DOMContentLoaded", function () {
    const theme = preferredTheme();
    applyTheme(theme);

    const toggle = document.getElementById("setupThemeToggle");
    if (toggle) {
      toggle.addEventListener("click", function () {
        const isDark = document.body.classList.contains(darkClass);
        applyTheme(isDark ? "light" : "dark");
      });
    }
  });
})();
