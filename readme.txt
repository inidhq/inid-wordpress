=== InID ===
Requires at least: 6.3
Requires PHP: 7.4
WC requires at least: 8.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Stable tag: 0.7.1

Tus usuarios inician sesión con InID en cualquier WordPress, sin contraseñas: el formulario va dentro de la página de inicio de sesión, con roles y sesiones sincronizados. Además, mapa de calor y visitantes en vivo. Con WooCommerce, además, tus clientes se registran y llenan el checkout con InID: un toque y su huella o un código por WhatsApp.

== Sin WooCommerce ==
1. Sube la carpeta `inid` a `wp-content/plugins/` y actívala.
2. Ajustes → InID: **Conectar con InID**. El sitio prueba que el dominio es suyo y recibe su llave; no hay nada que copiar. Luego, **Abrir el panel de InID** para ver el mapa de calor y En vivo.
3. Inicio de sesión: aparece solo en la página de inicio de sesión de WordPress (o donde pongas `[inid_signin]`). En Ajustes → InID, **Roles**: un rol de InID = un rol de WordPress por línea (`member=author`). Quién puede registrarse (dominios, lista de espera, invitaciones) se configura en el panel de InID.
4. El mapa de calor, En vivo y el seguimiento de visitas necesitan que el visitante acepte las cookies de estadísticas, y pedirlo es responsabilidad del sitio. Con un aviso compatible con la WP Consent API (Complianz, CookieYes, Cookiebot y otros), InID espera la respuesta del visitante solo. Sin aviso, cuenta a todos.

== Con WooCommerce ==
1. Sube la carpeta `inid` a `wp-content/plugins/` y actívala.
2. WooCommerce → InID: **Conectar con InID**. El sitio prueba que el dominio es suyo y recibe sus llaves, con su dirección y su webhook ya registrados en InID; no hay nada que copiar.

Si ya creaste tu organización en el panel de InID, usa **Ya tengo una organización en InID**: eliges cuál en el panel y el sitio queda conectado a ella.

Al conectar, el sitio le envía a InID su nombre, su dirección y el correo del administrador. Un sitio sin HTTPS o que no es público (local, intranet) no puede conectarse así: pega las llaves del panel de InID en la misma pantalla.

== Qué hace ==
* Pone el pixel de InID en la tienda: reconoce el checkout y el registro sin tocar el tema.
* Crea el cliente de WooCommerce cuando un comprador autoriza la tienda (webhook firmado).
* "Iniciar sesión con InID" en Mi cuenta, sin contraseña.
* Invita a los clientes con sesión iniciada a guardar sus datos en InID.
* Muestra el embudo (solo conteos) y los checkouts sin terminar.

== Changelog ==
= 0.7.1 =
* El ajuste de la dirección de las organizaciones en InID se llama organization_url.
= 0.7.0 =
* Usuarios → Pasar a InID: invita a las cuentas que ya existen (por rol, con su rol en la app y un correo del sitio); se conectan la primera vez que entran con InID.
* Conectar con InID desde el perfil y desde Mi cuenta; aviso a quien aún entra con contraseña.
* Opción para que las cuentas conectadas ya no entren con contraseña (los administradores sí).
* Textos: organización en vez de tienda donde se habla de InID.
= 0.6.0 =
* Acceso con InID en cada página, entrada o producto: cualquiera, con sesión, ciertos roles o ciertos permisos de la app, y la entrada por la que se inicia sesión. El contenido protegido no aparece en listados, búsquedas, feeds ni la API REST.
= 0.5.0 =
* Entradas: los clientes entran por la general y tu equipo por la suya (wp-login.php, [inid_signin entrance="team"]).
* Páginas protegidas por rol: "/intranet = author, editor (team)".
= 0.4.0 =
* Iniciar sesión con InID en cualquier WordPress, dentro de wp-login.php y con el shortcode [inid_signin].
* Roles de InID → roles de WordPress; cambios de nombre, correo y rol por webhook; quitar a alguien cierra sus sesiones y guarda su última copia.
* Salir de WordPress termina la sesión en InID, y terminarla en InID cierra la de WordPress.
