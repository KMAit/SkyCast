// SkyCast — Splash screen fade-out logic
(function () {
  /** Hide splash overlay once the page has fully loaded. */
  function hideSplash() {
    const splash = document.querySelector('.splash-screen');
    if (!splash) return;

    // Small delay to avoid white flash before first paint
    setTimeout(() => {
      splash.classList.add('fade-out'); // triggers CSS transition
      // Remove node after the fade duration (≈600ms)
      setTimeout(() => splash.remove(), 650);
    }, 300);
  }

  /** Initialize splash handling on window load. */
  function initSplash() {
    if (document.readyState === 'complete') {
      hideSplash();
    } else {
      window.addEventListener('load', hideSplash, { once: true });
    }
  }

  // Run once DOM and SkyCast core are ready
  if (window.SkyCast && typeof SkyCast.ready === 'function') {
    SkyCast.ready(initSplash);
  } else {
    document.addEventListener('DOMContentLoaded', initSplash);
  }
})();
