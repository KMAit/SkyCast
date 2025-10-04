// SkyCast — tiny core helpers (namespace + DOM ready + events + storage)

(function () {
  if (window.SkyCast && window.SkyCast.__coreReady) return;

  const SkyCast = (window.SkyCast = window.SkyCast || {});

  // Safe DOM ready (runs immediately if DOM already loaded)
  SkyCast.ready = function (fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
      fn();
    }
  };

  // Simple custom event helpers
  SkyCast.events = {
    emit(name, detail = {}) {
      document.dispatchEvent(new CustomEvent(name, { detail }));
    },
    on(name, handler) {
      document.addEventListener(name, handler);
    },
  };

  // Local storage helpers with namespace
  const NS = 'skycast:';
  SkyCast.store = {
    get(key, fallback = null) {
      try {
        const raw = localStorage.getItem(NS + key);
        return raw === null ? fallback : JSON.parse(raw);
      } catch {
        return fallback;
      }
    },
    set(key, value) {
      try {
        localStorage.setItem(NS + key, JSON.stringify(value));
      } catch {
        /* ignore */
      }
    },
  };

  SkyCast.__coreReady = true;
})();
