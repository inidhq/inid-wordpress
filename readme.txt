=== InID ===
Requires at least: 6.3
Requires PHP: 7.4
WC requires at least: 8.0
Stable tag: 0.2.0

Mapa de calor y visitantes en vivo para cualquier sitio de WordPress. Con WooCommerce, además, tus clientes se registran y llenan el checkout con InID: un toque y su huella o un código por WhatsApp.

== Sin WooCommerce ==
1. Sube la carpeta `inid` a `wp-content/plugins/` y actívala.
2. Ajustes → InID: pega la llave pública del panel de InID.
3. El mapa de calor, En vivo y el seguimiento de visitas necesitan que el visitante acepte las cookies de estadísticas, y pedirlo es responsabilidad del sitio. Con un aviso compatible con la WP Consent API (Complianz, CookieYes, Cookiebot y otros), InID espera la respuesta del visitante solo. Sin aviso, cuenta a todos.

== Con WooCommerce ==
1. Sube la carpeta `inid` a `wp-content/plugins/` y actívala.
2. WooCommerce → InID: pega la llave pública, la llave secreta y el secreto del webhook.
3. En InID, registra el dominio de la tienda y la URL del webhook que muestra esa pantalla.

== Qué hace ==
* Pone el pixel de InID en la tienda: reconoce el checkout y el registro sin tocar el tema.
* Crea el cliente de WooCommerce cuando un comprador autoriza la tienda (webhook firmado).
* "Iniciar sesión con InID" en Mi cuenta, sin contraseña.
* Invita a los clientes con sesión iniciada a guardar sus datos en InID.
* Muestra el embudo (solo conteos) y los checkouts sin terminar.
