(function () {
  const storageKey = "vani_setup_theme";
  const lightClass = "setup-theme-light";
  const darkClass = "setup-theme-dark";

  function preferredTheme() {
    const saved = localStorage.getItem(storageKey);
    if (saved === "light" || saved === "dark") {
      return saved;
    }
    return window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches
      ? "dark"
      : "light";
  }

  function applyTheme(theme) {
    document.body.classList.toggle(lightClass, theme === "light");
    document.body.classList.toggle(darkClass, theme === "dark");
    localStorage.setItem(storageKey, theme);

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
