// SkyCast - Home page interactions (geolocation button)
// Requires skycast-core.js (for SkyCast.ready)

(function () {
  /** Append a polite aria-live region to the container if missing. */
  function ensureAriaLive(container) {
    if (!container) return;
    if (!container.getAttribute('role')) {
      container.setAttribute('role', 'status'); // announced by screen readers
    }
    if (!container.getAttribute('aria-live')) {
      container.setAttribute('aria-live', 'polite');
    }
  }

  /** Display a message to the user (instead of alert). */
  function displayMessage(message, type = 'info') {
    const container = document.querySelector('#geo-message');
    if (!container) return;
    ensureAriaLive(container);

    container.textContent = message;
    container.className = `geo-message ${type}`;
  }

  /** Handle geolocation success. */
  function handleGeoSuccess(position) {
    const { latitude, longitude, accuracy } = position.coords;

    const form = document.getElementById('city-form');
    const cityInput = form?.querySelector('input[name="city"]');
    if (cityInput) cityInput.value = '';

    displayMessage(
      `Position détectée : ${latitude.toFixed(4)}, ${longitude.toFixed(4)} (±${Math.round(accuracy)}m)`,
      'success'
    );

    // Build a safe URL using the form action (fallback: current path)
    const base = form?.action || window.location.pathname;
    const url = new URL(base, window.location.origin);
    url.searchParams.set('lat', String(latitude));
    url.searchParams.set('lon', String(longitude));

    window.location.assign(url.toString());
  }

  /** Handle geolocation errors. */
  function handleGeoError(error) {
    console.warn('[SkyCast] Geolocation error:', error);

    const messages = {
      1: "Permission refusée. Autorisez l'accès à la position.",
      2: 'Position indisponible. Réessayez à l’extérieur ou vérifiez vos paramètres.',
      3: 'Délai dépassé. Réessayez.',
    };

    displayMessage(messages[error?.code] || "Impossible d'obtenir votre position.", 'error');
  }

  /** Wire up the geolocation button. */
  function initGeolocation() {
    // Prefer a data attribute if you add it later; fallback to aria-label (current)
    const geoBtn =
      document.querySelector('[data-geo-button]') ||
      document.querySelector('button[aria-label="Utiliser ma position"]');

    if (!geoBtn) {
      console.info('[SkyCast] Geolocation button not found.');
      return;
    }
    if (!('geolocation' in navigator)) {
      console.info('[SkyCast] Geolocation API not available.');
      displayMessage("La géolocalisation n'est pas disponible sur ce navigateur.", 'error');
      geoBtn.disabled = true;
      return;
    }

    geoBtn.addEventListener('click', () => {
      geoBtn.disabled = true;
      geoBtn.setAttribute('aria-busy', 'true');

      navigator.geolocation.getCurrentPosition(
        (pos) => {
          try {
            handleGeoSuccess(pos);
          } finally {
            geoBtn.disabled = false;
            geoBtn.removeAttribute('aria-busy');
          }
        },
        (err) => {
          try {
            handleGeoError(err);
          } finally {
            geoBtn.disabled = false;
            geoBtn.removeAttribute('aria-busy');
          }
        },
        {
          enableHighAccuracy: true,
          timeout: 10000,
          maximumAge: 0,
        }
      );
    });
  }

  // Run on DOM ready
  (window.SkyCast
    ? window.SkyCast.ready
    : (fn) => document.addEventListener('DOMContentLoaded', fn))(initGeolocation);
})();
