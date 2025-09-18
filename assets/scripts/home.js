// SkyCast - Home page interactions (geolocation button)

/**
 * Handle geolocation success.
 */
function handleGeoSuccess(position) {
  const { latitude, longitude, accuracy } = position.coords;

  console.debug('[SkyCast] Geolocation success:', { latitude, longitude, accuracy });

  // TODO: remplacer par un appel API interne
  displayMessage(
    `Position détectée : ${latitude.toFixed(4)}, ${longitude.toFixed(4)} (±${Math.round(accuracy)}m)`,
    'success'
  );
}

/**
 * Handle geolocation errors.
 */
function handleGeoError(error) {
  console.warn('[SkyCast] Geolocation error:', error);

  const messages = {
    1: "Permission refusée. Autorisez l'accès à la position.",
    2: "Position indisponible. Réessayez à l'extérieur ou vérifiez vos paramètres.",
    3: 'Délai dépassé. Réessayez.',
  };

  displayMessage(messages[error.code] || "Impossible d'obtenir votre position.", 'error');
}

/**
 * Display a message to the user (instead of alert).
 */
function displayMessage(message, type = 'info') {
  const container = document.querySelector('#geo-message');
  if (!container) return;

  container.textContent = message;
  container.className = `geo-message ${type}`;
}

/**
 * Initialize geolocation button.
 */
function initGeolocation() {
  const geoBtn = document.querySelector('button[aria-label="Utiliser ma position"]');
  if (!geoBtn || !('geolocation' in navigator)) {
    console.info('[SkyCast] Geolocation not available.');
    return;
  }

  geoBtn.addEventListener('click', () => {
    geoBtn.disabled = true;
    geoBtn.setAttribute('aria-busy', 'true');

    navigator.geolocation.getCurrentPosition(
      (pos) => {
        handleGeoSuccess(pos);
        geoBtn.disabled = false;
        geoBtn.removeAttribute('aria-busy');
      },
      (err) => {
        handleGeoError(err);
        geoBtn.disabled = false;
        geoBtn.removeAttribute('aria-busy');
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
document.addEventListener('DOMContentLoaded', initGeolocation);
