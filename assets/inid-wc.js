// "Iniciar sesión con InID" on WooCommerce: the pixel hands over a one-time token, this
// store's server exchanges it, signs the buyer in and sends them to their account
document.addEventListener("inid:signin", async (event) => {
  const res = await fetch(window.inidWc.login, {
    method: "POST",
    credentials: "same-origin",
    headers: { "content-type": "application/json", "x-wp-nonce": window.inidWc.nonce },
    body: JSON.stringify({ token: event.detail.token }),
  });
  const data = await res.json().catch(() => ({}));
  if (res.ok && data.redirect) window.location.assign(data.redirect);
});

// Logged in from a checkout InID filled: the data is already in WooCommerce's session.
// The block checkout reloads it from there (classic checkouts were filled on screen).
// Logging in changes WooCommerce's nonce: the block checkout gets the new one first.
document.addEventListener("inid:signedin", (event) => {
  const apiFetch = window.wp && window.wp.apiFetch;
  if (apiFetch && apiFetch.setNonce && event.detail.store_api_nonce) {
    apiFetch.setNonce({ Nonce: event.detail.store_api_nonce, "Nonce-Timestamp": String(Math.floor(Date.now() / 1000)) });
  }
  // The block checkout, logged in with the data in the session, shows it as WooCommerce's
  // own summary card (with "Editar") instead of a filled form: one reload gets there
  if (document.querySelector(".wp-block-woocommerce-checkout, .wc-block-checkout")) {
    setTimeout(() => window.location.reload(), 600);
    return;
  }
  const data = window.wp && window.wp.data;
  if (data && data.select("wc/store/cart")) data.dispatch("wc/store/cart").invalidateResolutionForStore();
});
