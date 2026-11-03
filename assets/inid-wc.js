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
