// The site's cookie notice decides on statistics (WP Consent API, which Complianz, CookieYes,
// Cookiebot and others speak): InID counts and follows visits only once they're allowed
(function () {
  function allowed() {
    return typeof window.wp_has_consent === "function" && window.wp_has_consent("statistics");
  }
  function tell() {
    if (window.InID && window.InID.consent) window.InID.consent({ analytics: allowed() });
  }
  document.addEventListener("wp_listen_for_consent_change", function (event) {
    if (event.detail && "statistics" in event.detail) tell();
  });
  // The pixel loads on its own (async): tell it once it's there
  var tries = 0;
  (function wait() {
    if (window.InID && window.InID.consent) return tell();
    if (tries++ < 50) setTimeout(wait, 200);
  })();
})();
