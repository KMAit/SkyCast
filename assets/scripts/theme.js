// SkyCast - Theme switcher (persisted in localStorage)

const THEME_KEY = 'skycast-theme';
const THEMES = ['theme-sky', 'theme-nature', 'theme-sunset'];

function applyTheme(theme) {
  const root = document.documentElement;
  // remove existing theme classes
  THEMES.forEach((t) => root.classList.remove(t));
  // add chosen theme (fallback -> theme-nature)
  root.classList.add(THEMES.includes(theme) ? theme : 'theme-nature');
}

function updateToggleUI(activeTheme) {
  const buttons = document.querySelectorAll('.theme-toggle [data-theme]');
  buttons.forEach((btn) => {
    const isActive = btn.getAttribute('data-theme') === activeTheme;
    btn.classList.toggle('is-active', isActive);
    btn.setAttribute('aria-pressed', String(isActive));
  });
}

function initTheme() {
  const saved = localStorage.getItem(THEME_KEY) || 'theme-nature';
  applyTheme(saved);
  updateToggleUI(saved);

  const container = document.querySelector('.theme-toggle');
  if (!container) return;

  container.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-theme]');
    if (!btn) return;
    const theme = btn.getAttribute('data-theme');
    applyTheme(theme);
    updateToggleUI(theme);
    localStorage.setItem(THEME_KEY, theme);
  });
}

document.addEventListener('DOMContentLoaded', initTheme);
