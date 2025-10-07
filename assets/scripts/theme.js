// SkyCast — Theme switcher (refactor using SkyCast core)
SkyCast.ready(() => {
  const THEME_KEY = 'theme';
  const THEMES = ['theme-sky', 'theme-nature', 'theme-sunset'];

  const root = document.documentElement;
  const container = document.querySelector('.theme-toggle');
  if (!container) return;

  function applyTheme(theme) {
    THEMES.forEach((t) => root.classList.remove(t));
    root.classList.add(THEMES.includes(theme) ? theme : 'theme-nature');
  }

  function updateToggleUI(activeTheme) {
    const buttons = container.querySelectorAll('[data-theme]');
    buttons.forEach((btn) => {
      const isActive = btn.dataset.theme === activeTheme;
      btn.classList.toggle('is-active', isActive);
    });
  }

  const saved = SkyCast.store.get(THEME_KEY, 'theme-nature');
  applyTheme(saved);
  updateToggleUI(saved);

  container.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-theme]');
    if (!btn) return;
    const theme = btn.dataset.theme;
    applyTheme(theme);
    updateToggleUI(theme);
    SkyCast.store.set(THEME_KEY, theme);
    SkyCast.events.emit('theme:changed', { theme });
  });
});
