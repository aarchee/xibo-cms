# 🍌 DisplaFruit — Guía de configuración (qué ajustar para dejarlo listo)

Esta guía es la **lista ordenada de cosas a configurar** desde que el sistema arranca hasta que
está listo para usarse de verdad con pantallas. Es complementaria a:
- **`GUIA_DE_USO_DISPLAFRUIT.md`** → el día a día (encender, publicar, etc.).
- **`README_DISPLAFRUIT.md`** → la parte técnica/instalación.

> Haz los pasos **en orden**. Los marcados con 🟢 son imprescindibles; los 🔵 son recomendables;
> los ⚪ son opcionales/avanzados.

---

## 0. Primero, decide tu escenario

| | **A) Solo pruebas en este PC** | **B) Uso real con Smart TVs** |
|---|---|---|
| Para qué | Aprender, probar publicaciones | Pantallas reales en tienda/almacén |
| Pantallas | Ninguna real (o el propio navegador) | Smart TVs con Xibo for Android |
| Configuración necesaria | Mínima (pasos 1-2) | Completa (pasos 1-10) |

Si solo vas a trastear, haz los pasos **1 y 2** y salta al uso. Si vas a montar pantallas de
verdad, sigue toda la guía.

Todos los ajustes del sistema están en: **menú lateral → Administración → Ajustes (Settings)**.
Si no encuentras una pestaña, busca el ajuste por el **nombre técnico** que indico entre
paréntesis (p. ej. `XMR_PUB_ADDRESS`).

---

## 1. 🟢 Seguridad y datos básicos (5 minutos)

1. Entra como administrador: **http://localhost** → `xibo_admin` / `password`.
2. **Cambia la contraseña** ya: arriba a la derecha, tu nombre → *Editar perfil* → nueva contraseña.
3. (Recomendado) crea **tu propio** usuario administrador con tu nombre y correo, y deja
   `xibo_admin` solo como respaldo.

---

## 2. 🟢 Idioma y zona horaria (importante: ahora está en inglés/Londres)

En **Administración → Ajustes**, pestaña **Regional**:

| Ajuste | Valor actual | Cámbialo a |
|---|---|---|
| Idioma por defecto (`DEFAULT_LANGUAGE`) | `en_GB` (inglés) | **Español (España)** |
| Zona horaria (`defaultTimezone`) | `Europe/London` | **Europe/Madrid** |
| Formato de fecha / hora | formato UK | el que prefieras (p. ej. `d/m/Y H:i`) |

Guarda y **recarga** la página. A partir de ahí el CMS estará en español y las horas (clave para
programar contenido) serán las de España.

---

## 3. 🟢 (Escenario B) Hacer accesible el servidor en la red

Las Smart TVs no entienden `localhost`: necesitan la **dirección de red del PC** donde corre el CMS.

1. **Averigua la IP del PC** (PowerShell): `ipconfig` → busca *Dirección IPv4* (algo como
   `192.168.1.50`). Esa es tu **IP del servidor**; la llamaré `IP-SERVIDOR`.
2. **Recomendado:** pide a tu red que esa IP sea **fija** (reserva DHCP por MAC en el router), para
   que no cambie y las pantallas no se queden sin conexión.
3. **Abre el firewall de Windows** para que las TVs puedan conectarse (PowerShell **como
   administrador**):
   ```powershell
   New-NetFirewallRule -DisplayName "DisplaFruit CMS (80)"  -Direction Inbound -Protocol TCP -LocalPort 80   -Action Allow
   New-NetFirewallRule -DisplayName "DisplaFruit XMR (9505)" -Direction Inbound -Protocol TCP -LocalPort 9505 -Action Allow
   ```
4. Comprueba desde otro dispositivo de la misma red (móvil): abre `http://IP-SERVIDOR` → debe
   salir el login de DisplaFruit.
5. El PC servidor debe estar **encendido** siempre que quieras que las pantallas funcionen.

---

## 4. 🟢 (Escenario B) Datos para conectar pantallas: CMS Key + tiempo real (XMR)

### a) La CMS Key (clave secreta del servidor)
Cada Smart TV necesita esta clave para emparejarse con el CMS. Para **consultarla**:

```powershell
docker compose exec -T db mysql -uroot -proot cms -N -e "SELECT value FROM setting WHERE setting='SERVER_KEY';"
```

También la ves/editas en **Ajustes → (Red/Network) → "CMS Secret Key"** (`SERVER_KEY`).
Guárdala; la pondrás en cada TV (paso 5).

### b) XMR — para que las publicaciones lleguen AL INSTANTE
Sin esto el sistema funciona, pero las pantallas tardan ~1–2 min en recoger los cambios. Con esto,
publicar es inmediato. En **Ajustes → Displays**:

| Ajuste | Valor actual | Cámbialo a |
|---|---|---|
| XMR Public Address (`XMR_PUB_ADDRESS`) | `tcp://cms.example.org:9505` (placeholder) | **`tcp://IP-SERVIDOR:9505`** |

> ℹ️ Nota técnica (para IT): en este entorno de desarrollo, la conexión interna CMS→XMR
> (`XMR_ADDRESS`) apunta a `tcp://localhost:5555` y el contenedor `xmr` solo publica el puerto de
> players (9505). Para push interno real habría que exponer el puerto privado de XMR y poner
> `XMR_ADDRESS=tcp://xmr:5555`. Para empezar, basta con el `XMR_PUB_ADDRESS` de arriba; si el
> tiempo real no llega, el contenido igualmente aparece en el siguiente sondeo del player.

---

## 5. 🟢 (Escenario B) Mostrar contenido en las pantallas — dos vías

Hay dos formas de que una pantalla muestre lo que publicas. Elige una:

### 5A. ⭐ GRATIS — "Player web" (recomendado, sin licencia ni aparato extra)

DisplaFruit incluye un reproductor propio basado en navegador. La Smart TV abre una página web a
pantalla completa que muestra lo publicado. **No necesita Xibo for Android ni pagar nada.**

Por cada pantalla:
1. En la Smart TV instala un **navegador-kiosko gratuito** que arranque solo al encender y abra una
   URL a pantalla completa. (En Android TV hay apps de tipo *kiosk browser* con versión gratuita; la
   opción exacta depende de la marca de la TV.)
2. Configúralo para abrir al encender, a pantalla completa, esta URL:
   **`http://IP-SERVIDOR/displafruit/player`**
   *(para un grupo concreto en el futuro: `…/displafruit/player?group=NOMBRE`; por defecto muestra "todas").*
3. ¡Listo! Lo que publiques con **"Publicar en todas las pantallas"** aparece ahí solo (la página se
   refresca cada pocos segundos). Cuando no hay nada publicado, muestra una pantalla de espera con tu logo.

Notas honestas de esta vía:
- Muestra **imagen, vídeo y PDF a pantalla completa**; no layouts complejos ni widgets.
- El **vídeo se reproduce sin sonido** (regla de autoplay de los navegadores).
- Estas pantallas **no** aparecen en *Pantallas (Displays)* del CMS (no son players Xibo), así que el
  contador "Publicado en N pantallas" del panel **no las cuenta** (saldrá 0 aunque la TV sí muestre el
  contenido). Es lo esperado con esta vía.
- La fiabilidad del **auto-arranque** depende de la marca de TV.

### 5B. 💶 DE PAGO — Xibo for Android (más completo, requiere licencia)

Solo si quieres todas las funciones de Xibo (layouts, estadísticas, etc.) y aceptas la licencia
(~28 € pago único por pantalla). Por cada pantalla:
1. Instala la app **Xibo for Android** (Play Store o APK).
2. En sus ajustes: **CMS Address** = `http://IP-SERVIDOR`, **CMS Key** = la del paso 4a.
3. En el CMS → **Pantallas (Displays)** → **autoriza** la pantalla y ponle nombre.
4. **Perfil:** usa automáticamente el perfil **"Android"** (ya creado).
5. **Comparte la pantalla con el grupo "Operador Pantallas"** (paso 6) para que los operadores publiquen en ella.

> Con la vía 5B, cuando la tarjeta de la pantalla esté **verde** en el panel, está lista.

---

## 6. 🟢 Usuarios operadores y permisos

El día a día lo harán usuarios **Operador** (no el admin). Resumen (detalle en `GUIA_DE_USO`):

1. **Administración → Usuarios → Añadir Usuario.** Tipo **User** (no Super Admin).
2. Asígnalo al grupo **"Operador Pantallas"**.
3. 🔑 **Comparte las pantallas con ese grupo:** menú **Pantallas** → en cada pantalla (o grupo de
   pantallas) → **Permisos** → da acceso al grupo "Operador Pantallas". *Sin esto, el operador entra
   pero ve el panel vacío.*

El operador, al entrar, aterriza en su panel simple (`/displafruit/dashboard`) y solo puede subir
contenido y publicar.

---

## 7. 🔵 Biblioteca y contenido

En **Ajustes → (Biblioteca/Library)**:

- **Límite de biblioteca** (`LIBRARY_SIZE_LIMIT_KB`): ahora **0 = sin límite**. Si el disco del
  servidor es pequeño, pon un tope (en KB) para que no se llene.
- **Duraciones por defecto** de imágenes/vídeos: cuánto dura cada archivo si no indicas otra cosa.

Organiza el contenido en **carpetas** (menú Biblioteca/Carpetas): por tienda, por campaña, etc.
Puedes dar permiso a cada operador solo sobre su carpeta.

---

## 8. 🔵 Layout por defecto (qué se ve cuando NO hay nada programado)

Cuando una pantalla no tiene contenido publicado, muestra el **layout por defecto**
(`DEFAULT_LAYOUT`). Recomendado: crea un cartel sencillo con tu **marca DisplaFruit** (logo + fondo)
y ponlo como predeterminado, para que las pantallas nunca se vean "vacías".

- Crea el layout en **menú → Diseño → Layouts** (o `/prototype/...`), publícalo.
- Ponlo como predeterminado en **Ajustes → Displays → "Default Layout"**, o por pantalla en sus
  ajustes.

---

## 9. 🔵 Correo (SMTP) — para recuperar contraseñas y recibir avisos

Ahora el remitente es un placeholder (`mail_from = mail@yoursite.com`), así que el CMS **no puede
enviar correos** (recuperación de contraseña, alertas de pantallas caídas…). Para activarlo,
configura tu servidor SMTP. En este entorno Docker, el correo se gestiona con **msmtp**; los datos
se pasan por variables de entorno (`.env`): servidor, puerto, usuario, contraseña, remitente.

Mientras no lo configures: no uses "He olvidado mi contraseña" (no llegará el correo); cambia las
contraseñas desde el panel de administrador.

---

## 10. 🔵 Copias de seguridad y mantenimiento

**Qué hay que respaldar** (es donde vive TODO):
- La **base de datos** `cms` (usuarios, pantallas, programación).
- La carpeta **`library/`** (tus imágenes/vídeos/PDF subidos + claves + marca).

**Copia rápida de la base de datos** (PowerShell, en la carpeta del proyecto):
```powershell
docker compose exec -T db mysqldump -uroot -proot cms > "backup_cms_$(Get-Date -Format yyyy-MM-dd).sql"
```
Guarda ese `.sql` y una copia de `library/` en sitio seguro (otro disco / nube).

**Actualizar Xibo** (avanzado, lo hace IT): traer cambios de upstream a `displafruit/main`,
revisar los pocos ficheros de core editados (ver `CHANGELOG_DISPLAFRUIT.md`) y reconstruir.

---

## 11. ⚪ (Opcional) Publicar automáticamente desde Power Automate / API

Si quieres que otra app publique sin entrar a la web (p. ej. Power Automate):

1. **Administración → Aplicaciones → Añadir Aplicación**, tipo *Client Credentials*. Que el
   **propietario sea un administrador** (así el token tiene acceso completo).
2. Pide un token y llama a `POST /api/displafruit/publish-all`.

Los comandos exactos (curl) están en `README_DISPLAFRUIT.md`, apartado 6.

---

## 12. ✅ Checklist "listo para producción"

- [ ] Contraseña de `xibo_admin` cambiada.
- [ ] Idioma **Español** y zona horaria **Europe/Madrid**.
- [ ] (Escenario B) IP del servidor conocida/fija; firewall abierto (80 y 9505).
- [ ] (Escenario B) `XMR_PUB_ADDRESS` = `tcp://IP-SERVIDOR:9505`.
- [ ] (Escenario B) Pantallas mostrando contenido — vía **player web** (la TV abre
      `http://IP-SERVIDOR/displafruit/player`, GRATIS) o vía **Xibo for Android** (autorizada y en verde).
- [ ] Pantallas **compartidas** con el grupo "Operador Pantallas".
- [ ] Al menos un usuario **Operador** creado y probado (publica y aparece en pantalla).
- [ ] Layout por defecto con marca DisplaFruit.
- [ ] (Recomendado) SMTP configurado.
- [ ] Copia de seguridad hecha (BD + `library/`).

---

*DisplaFruit S.A. — Sistema de Cartelería Digital. ¿Dudas técnicas? `README_DISPLAFRUIT.md`.*
