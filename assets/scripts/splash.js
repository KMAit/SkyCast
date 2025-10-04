// SkyCast — Hide splash overlay after first paint
(function () {
  function hideSplash() {
    const node = document.querySelector('.splash-screen');
    if (!node) return;
    // Petite latence pour éviter un flash
    setTimeout(() => {
      node.classList.add('fade-out'); // correspond à ton CSS .splash-screen.fade-out
      // Optionnel: retirer le noeud après l'anim (600 ms comme en CSS)
      setTimeout(() => node.remove(), 650);
    }, 300);
  }

  // Utiliser 'load' pour s'assurer que la première frame est peinte
  if (document.readyState === 'complete') {
    hideSplash();
  } else {
    window.addEventListener('load', hideSplash, { once: true });
  }
})();
