# 🍌 HANDOFF — DisplaFruit Signage (fork de Xibo CMS)

> **Propósito de este documento.** Es un traspaso autocontenido: cualquier persona o IA que lo
> lea debe entender **qué es el proyecto, qué se ha hecho, cómo arrancarlo, qué problemas reales
> aparecieron y cómo se resolvieron**, sin necesidad de leer el resto del repo. Los documentos
> detallados se referencian al final (§11).
>
> **Última actualización:** 2026-07-02. **Rama:** `displafruit/main` (fork de Xibo `develop`).
> **Idioma del proyecto:** español (commits y docs en español).

---

## 1. TL;DR (lee esto primero)

**DisplaFruit** es un sistema interno de **cartelería digital** para **DisplaFruit S.A.**
(distribuidora de plátano IGP, Alginet/Valencia), construido como **fork de Xibo CMS**
(open source, AGPLv3). Se sube una imagen/vídeo/PDF y aparece en pantallas (Smart TVs).

- **Estado:** ✅ Operativo. La **primera Smart TV real está conectada, autorizada y reproduciendo**
  contenido programado (sesión del 2026-07-01).
- **Estrategia de código:** *overlay + ediciones de core mínimas*. Casi todo el código custom vive
  fuera del core (en `custom/DisplaFruit/`, `docker/brand/` y una migración) para que el `git diff`
  contra upstream sea pequeño y los merges futuros indoloros.
- **Responsable IT:** Raúl.
- **Login admin:** `xibo_admin` / `password` en **http://localhost** (entorno dev en Docker).

**Los 3 gotchas más importantes (causaron toda la depuración de la última sesión):**
1. En el player, la **Dirección del CMS** debe ser la **raíz** (`http://IP-DEL-SERVIDOR`),
   **nunca** `.../prototype`. `/prototype` es solo el panel de admin React; el player habla por
   XMDS (`/xmds.php`), que vive en la raíz.
2. Al **programar eventos** con horario *Custom*, marca **"Ejecutar con la hora del CMS"** o usa
   **"Always"**. Si no, el evento se evalúa contra el **reloj local de la TV** y puede quedar
   "Fuera de plazo".
3. Cada **TV nueva en otra subred/VLAN** necesita una **regla de firewall** hacia el puerto 80 del
   servidor (y el 9505 si se quiere push en tiempo real).

---

## 2. Qué es y cómo está montado

| | |
|---|---|
| Base | **Xibo CMS** (PHP 8.4 / Slim 4 / MySQL 8.4 / Phinx / React 18 en `/prototype`), build alpha del branch `develop`. |
| Fork | `displafruit/main`. Todo cambio vs upstream está registrado en `CHANGELOG_DISPLAFRUIT.md`. |
| Entorno | **Docker Compose** en Windows 11 (Docker Desktop). |
| CMS | **http://localhost** (puerto **80**). ⚠️ El 8080 es **Swagger**, no el CMS. |
| Otros puertos | MySQL `3315`, XMR (push a players) `9505`, Swagger `8080`. |
| Panel admin nuevo (React SPA) | rutas **`/prototype/*`** (bienvenida, pantallas, biblioteca…). |
| Player oficial | **Xibo for Android** (de pago tras 14 días). |
| Player alternativo GRATIS | **"player web"** propio de DisplaFruit (navegador-kiosko), ver §7. |

---

## 3. Arquitectura de las personalizaciones (dónde vive cada cosa)

**Código custom (fuera del core, namespace `Xibo\Custom\DisplaFruit\`):**
- `custom/settings-custom.php` — registra el middleware de DisplaFruit en `$middleware`.
- `custom/DisplaFruit/Middleware/DisplaFruitMiddleware.php` — **punto de extensión clave**: añade
  rutas y registra controladores en el contenedor DI **sin editar `lib/routes*.php` ni
  `lib/Dependencies/`** (aprovecha que `State::setMiddleWare()` llama a `addRoutes()`). También
  registra la feature `displafruit.operator` y marca públicas las rutas del player web.
- `custom/DisplaFruit/Controller/DashboardController.php` — panel simple del operador.
- `custom/DisplaFruit/Controller/PublishController.php` — endpoint `publish-all` (subida → layout a
  pantalla completa → schedule de alta prioridad sobre el grupo "DisplaFruit - Todas").
- `custom/DisplaFruit/Controller/PlayerController.php` — el "player web" (3 rutas públicas).
- `custom/DisplaFruit/views/displafruit-dashboard.twig`, `displafruit-player.twig`.

**Marca (branding):** `docker/brand/` (config.json, theme.css, logo.svg, favicon, iconos PWA) →
se copia a `library/brand/` en el primer arranque y se sirve por el alias Apache `/brand`.
Colores: naranja `#F47920`, marrón `#3D2B1F`.

**Datos / rol:** migraciones Phinx en `db/migrations/`:
- `20260629120000_displafruit_operator_role_migration.php` — grupo de usuario **"Operador Pantallas"**
  (features reducidas `displafruit.operator`; homepage `statusdashboard.view`).
- `20260630120000_displafruit_now_playing_migration.php` — tabla `displafruit_now_playing` (para el player web).

**Ediciones de CORE (mínimas — las únicas; revísalas al hacer merge de upstream):**
`views/login.twig` (branding login), `views/licence.twig` (marca + AGPLv3 conservada),
`lib/Controller/User.php` (`home()`: redirige al operador a `/displafruit/dashboard`),
`docker/entrypoint.sh` (3 líneas: aprovisiona `brand/config.json`),
`docker/brand/theme.css` + `docker/brand/logo.svg`, `.gitignore` (para versionar `custom/DisplaFruit/`).

---

## 4. Entregables completados (histórico)

1. **Entorno de desarrollo + documentación** (docker-compose.override para Windows, guías).
2. **Branding DisplaFruit** (colores, logo placeholder, títulos y textos de login/licencia).
3. **Rol "Operador Pantallas"** (migración Phinx; aterriza en panel simple tras login).
4. **Panel del operador + endpoint `publish-all`** (web con sesión/CSRF y API con OAuth2).
5. **Correcciones tras revisión de código adversarial** (5 hallazgos: path-traversal + cuotas,
   republicar mismo nombre, FK en rollback de migración, conteo de pantallas, permiso muerto).
6. **Correcciones MED** (dashboard solo-operador, ACL en `displayGroupId`, rollback transaccional,
   mensajes de estado vacío).
7. **Validación E2E** — lint PHP + phpcs OK; migración, login, rutas y `publish-all` verificados.
8. **Player web gratuito** (§7).
9. **Panel del operador — gestión de contenido** (2026-07-02): publicación **segmentada**
   (todas / grupo / pantalla), **programación** (temporal / permanente / rango) con `syncTimezone=1`,
   **"En antena ahora"** con miniaturas y botón **Parar**, **Historial** con **Republicar**, y
   **estado auto-refrescado** (online/offline, autorizada, contenido actual por pantalla). Nueva
   tabla `displafruit_publication`. Grupo "DisplaFruit - Todas" **auto-sanado**.

> Detalle exacto de cada cambio y su justificación: `CHANGELOG_DISPLAFRUIT.md`.

---

## 5. Arranque del entorno (Docker en Windows)

**Día a día** (ya bootstrapeado): `docker compose up -d` → http://localhost.

**Primera vez en un PC limpio** (el `Dockerfile.dev` **no** trae Composer/Node y monta el código
del host, así que hay que generar dependencias una vez):

```powershell
docker compose up --build -d
# a) PHP -> vendor/
docker run --rm -v "${PWD}:/app" composer:2 install --ignore-platform-reqs --no-interaction
# b) UI clásica (webpack) -> web/dist
docker run --rm -v "${PWD}:/app" -v xibo_node_modules:/app/node_modules -w /app node:20 `
  sh -c "npm install --no-audit --no-fund && npm run build"
# b2) Frontend React (SPA /prototype/*) -> web/prototype  [OBLIGATORIO: sin esto, 500 tras login]
docker run --rm -v "${PWD}:/app" -v xibo_frontend_nm:/app/frontend/node_modules -w /app/frontend node:22 `
  sh -c "npm install --no-audit --no-fund && npm run build"
docker compose exec -T web sh -c "rm -rf /var/www/cms/web/prototype; cp -r /var/www/cms/frontend/dist /var/www/cms/web/prototype; chown -R www-data:www-data /var/www/cms/web/prototype"
# c) cache/ + permisos
docker compose exec -T web sh -c "mkdir -p /var/www/cms/cache /var/www/cms/library/temp; chmod -R 777 /var/www/cms/cache /var/www/cms/library; chown -R www-data:www-data /var/www/cms/cache /var/www/cms/library"
# d) migraciones (reinicia: el entrypoint instala solo al ver BD vacía)
docker compose restart web
```

**Empezar de cero (borra TODO):**
`docker compose down; docker compose exec -T db mysql -uroot -proot -e "DROP DATABASE cms; CREATE DATABASE cms;"; docker compose up -d`

Notas Windows/PowerShell (validación por CLI): el workdir del contenedor `web` es `/`
(usa `cd /var/www/cms`); columnas BD en snake_case (`is_priority`); la tabla `group` es palabra
reservada (consúltala vía fichero `.sql`, no con backticks escapados en PowerShell).

---

## 6. 🔴 Diario de la última sesión (2026-07-01): conectar la primera Smart TV real

Objetivo: conectar **Xibo for Android v4 R411** en una **Xiaomi TV F 43 2026 (Fire OS)** al CMS.
Se resolvieron **4 problemas encadenados**. Este es el conocimiento más valioso del handoff.

**Entorno de la prueba:**
- TV: Xiaomi TV F 43 (Fire OS), IP `192.168.100.193`, subred WiFi `192.168.100.0/24`. Display `displa1`.
- Servidor (PC `DISCEN08-PC`): IP `192.168.250.178`, subred cableada `192.168.250.0/24`.
- La TV y el servidor están en **VLANs distintas**.

### Problema 1 — Segmentación de red entre subredes (RESUELTO)
`ping` entre subredes funcionaba (ICMP permitido) pero `Test-NetConnection ... -Port 80` daba
`TcpTestSucceeded: False`: el firewall/router de la empresa bloqueaba TCP/80 entre subredes.
El Firewall de Windows del PC estaba desactivado (descartado). El contenedor `web` escuchaba bien.
- **Fix:** el equipo de red añadió regla `192.168.100.0/24` → `192.168.250.178:80`.

### Problema 2 — ⭐ La Dirección del CMS del player llevaba `/prototype` (RESUELTO — causa raíz)
Se había configurado la Dirección del CMS = `http://192.168.250.178/prototype`. El player
construye la URL de XMDS como `{Dirección} + /xmds.php` → `http://192.168.250.178/prototype/xmds.php`.
En `web/.htaccess` (líneas 48-51) hay una regla que reescribe **cualquier** `/prototype/*` que no
sea un fichero real a `/prototype/index.html` (la SPA de React). Como no existe
`web/prototype/xmds.php`, el player recibía **HTML de React en vez de la respuesta SOAP** →
spinner "conectando" infinito, el display no se registraba.
- **Fix:** Dirección del CMS = **`http://192.168.250.178`** (raíz, **sin `/prototype`**, sin barra
  final; el player añade `/xmds.php` solo). Así llega a `web/xmds.php`, que es un fichero real y
  Apache lo ejecuta como PHP.
- **Verificación:** `curl "http://192.168.250.178/xmds.php?what"` devuelve el nº de versión XMDS
  (texto). `curl "http://192.168.250.178/prototype/xmds.php?wsdl"` devuelve HTML (confirma el fallo).
- **Importante:** este fallo **NO** lo causa ninguna modificación de DisplaFruit. La regla
  `/prototype` es de upstream Xibo (SPA React). La clave `SERVER_KEY` (= "CMS Secret Key" en Ajustes)
  que se usó era correcta. → Ver §8 (auditoría).

### Problema 3 — Autorización del display (RESUELTO — flujo normal de Xibo)
Tras registrar, el display sale con `licensed=0` (por defecto `DISPLAY_AUTO_AUTH=0`) y aparece en
**Pantallas** en estado *pendiente*. Hasta autorizarlo, RequiredFiles/Schedule responden
"not authorised".
- **Fix:** Pantallas → editar `displa1` → **Autorizado** → Guardar. Tras un **"Collect Now"**
  (icono refrescar) la columna **CONECTADO pasó a ✅**.
- Regla práctica: si el display **no aparece** en Pantallas → problema de URL/red; si **aparece
  pero pendiente** → solo falta autorizar.

### Problema 4 — "Fuera de plazo" + pantalla negra (RESUELTO — horario/zona horaria)
Se creó un Layout con una imagen y se programó un Evento *Custom* con inicio a las **12:00:00** de
hoy, fin en 2038, y **"¿Ejecutar con la hora del CMS?" DESMARCADO** (→ el evento se evalúa contra
el **reloj local de la TV**). A media mañana el evento aún no había entrado en ventana → estado
**"Fuera de plazo"** y pantalla negra.
- **Fix:** cambiar el horario del evento a **"Always"** (o marcar "Ejecutar con la hora del CMS").
  El estado pasó a **"Hoy"** ✅ y la TV reproduce el layout.
- Aprendizaje: en eventos con horario concreto, la evaluación en **hora local del player** depende
  de que el reloj/zona horaria de la TV (Europe/Madrid, NTP) esté bien. Para contenido permanente,
  **"Always"** evita el problema.

**Estado final de la sesión:** `displa1` — Autorizada ✅, Conectado ✅, Estado **"Hoy"**, reproduciendo.

---

## 7. Player web gratuito (alternativa a Xibo for Android)

El usuario no quiere pagar el player Android ni poner un PC tras cada pantalla. Solución propia:
la Smart TV abre en un **navegador-kiosko gratuito** la URL **`http://IP-DEL-SERVIDOR/displafruit/player`**
(o `?group=<nombre>`) y muestra a pantalla completa lo publicado. Sin licencia Xibo, sin dar de
alta la pantalla.
- Rutas **públicas** en `PlayerController.php`: `/displafruit/player` (Twig), `/displafruit/player/state`
  (JSON del contenido actual), `/displafruit/player/media/{id}` (sirve solo media referenciado en
  `displafruit_now_playing`).
- `PublishController` hace UPSERT en `displafruit_now_playing` tras publicar.
- **Límites (POC):** solo imagen/vídeo/PDF a pantalla completa (sin layouts/widgets/proof-of-play);
  vídeo en `muted`; polling cada 4s; fiabilidad del auto-arranque según marca de TV.

---

## 8. Auditoría: qué NO rompe la conexión del player (para no perder tiempo)

Se auditó de forma adversarial (workflow de 21 agentes, 17 hallazgos verificados) si **alguna
modificación de DisplaFruit** podía romper la conexión del player. **Conclusión: ninguna.** No
vuelvas a sospechar de estos:
- **XMDS es un entrypoint separado** (`web/xmds.php` usa `State::setState`, **no** la pila de
  middleware web). El middleware custom **no se ejecuta** en el path de XMDS (no tiene
  `registerDispatcher`; instanciarlo no tiene efectos secundarios).
- Las **migraciones** de DisplaFruit son tablas aisladas; no tocan `display`/`schedule`/`requiredfile`.
  Que el admin entre confirma que corrieron limpias.
- El redirect de `User::home()` está acotado (excluye Super Admin, solo en la home web) y no toca XMDS.
- `login.twig`, `licence.twig`, `theme.css`, `.gitignore`, el bloque de branding de `entrypoint.sh`
  → cosméticos, no tocan rutas/xmds/Apache/settings.

---

## 9. Problemas conocidos / limitaciones abiertas (no bloqueantes)

1. **XMR (push en tiempo real) NO cableado en dev.** `entrypoint.sh:220` deja
   `XMR_PUB_ADDRESS = tcp://cms.example.org:9505` (placeholder upstream) y `XMR_ADDRESS` solo se
   fija en modo producción. **Impacto:** el "Collect Now" instantáneo y la publicación instantánea
   no llegan al momento; el player igualmente actualiza por **polling** en su intervalo de colección.
   **Para habilitarlo:** Ajustes → Displays → *XMR Public Address* = `tcp://192.168.250.178:9505`;
   setting `XMR_ADDRESS` = `http://xmr:8081`. (El puerto 9505 ya está publicado en `docker-compose.yml`;
   para TVs en otra subred, abrir 9505/tcp en el firewall.)
2. **Grupo "DisplaFruit - Todas" — MITIGADO (2026-07-02).** Antes era estático y solo se
   sincronizaba al publicar. Ahora `syncMembership()` **auto-sana** la pertenencia (añade las
   pantallas que falten) también en cada poll de `/displafruit/dashboard/state`, así que una
   pantalla autorizada nueva entra en el grupo en segundos con el panel abierto. El schedule
   efímero de 30 s también está resuelto: el panel permite **contenido permanente** (`toDt=DATE_MAX`).
   *Residual:* si nadie tiene el panel abierto y no se publica, una pantalla nueva no entra hasta el
   siguiente poll/publish (sin listener porque `registerDispatcher` solo corre en XMDS; sin grupo
   dinámico porque Xibo exige criterio y no admite "todas").
3. **IP del display se ve como `172.18.0.1`** (gateway de Docker) por el proxy de Docker que
   enmascara la IP de origen. Cosmético; el CMS no ve la IP real de la TV.
4. **Botón "Vista Previa" de eventos** (`/campaign/{id}/preview`) devuelve "No es posible ver esta
   página". Probable bug de la build alpha del CMS; no bloquea crear/programar. A investigar aparte.
5. **Logo/branding oficial** aún es placeholder (naranja/marrón). Sustituir `docker/brand/logo.svg`
   y `xibologo.png` por los oficiales (mismos nombres) cuando el usuario los tenga.

---

## 10. Pendiente / próximos pasos sugeridos

- [x] **Documentar los gotchas de §1/§6** en `GUIA_DE_CONFIGURACION_DISPLAFRUIT.md` (2026-07-07):
      nueva **§12 "Solución de problemas comunes"** con los 3 gotchas.
- [x] **Endurecer `web/.htaccess`** (2026-07-07): la regla de la SPA ya **no** captura `.php` bajo
      `/prototype/` → `/prototype/xmds.php` devuelve **404** claro en vez de HTML de React.
      Verificado. Registrado en `CHANGELOG_DISPLAFRUIT.md` (edición de core nº 8).
- [x] **Documentar la regla de firewall aplicada** (2026-07-07): en `GUIA_DE_CONFIGURACION` **§3.1**
      (origen `192.168.100.0/24` → `192.168.250.178:80`, y `:9505` opcional para XMR).
- [~] **Verificar zona horaria/NTP** (2026-07-07, parcial): el contenedor `web` corre en **UTC**
      (correcto). ⚠️ **Hallazgo:** `defaultTimezone` sigue en `Europe/London` y `DEFAULT_LANGUAGE`
      en `en_GB` en la instancia de dev → cambiar a **Europe/Madrid** / **Español** en Ajustes →
      Regional (guía §2). El NTP/zona de cada Smart TV requiere acceso físico a la TV (no verificable
      desde aquí).
- [ ] (Opcional) Grupo "DisplaFruit - Todas" dinámico (ver §9.2). *Requiere decisión de diseño.*
- [ ] Sustituir assets de marca por los oficiales. *Bloqueado: el usuario aún no tiene los assets
      oficiales.*

---

## 11. Documentos de referencia en el repo

- `CLAUDE.md` — guía para IAs sobre el codebase Xibo + sección de handoff DisplaFruit (se carga sola).
- `CHANGELOG_DISPLAFRUIT.md` — lista exacta de cambios vs upstream, con justificación.
- `README_DISPLAFRUIT.md` — despliegue técnico, API, conexión de pantallas.
- `GUIA_DE_USO_DISPLAFRUIT.md` — uso en lenguaje sencillo (no técnico).
- `GUIA_DE_CONFIGURACION_DISPLAFRUIT.md` — qué ajustar para dejarlo listo (incl. player web y
  comparativa player Android de pago vs. player web gratis).

---

## 12. Chuleta rápida (para conectar una TV nueva)

1. (Red) Si la TV está en otra subred: pedir regla de firewall → `SERVIDOR:80` (y `:9505` para push).
2. (Fire OS) Modo desarrollador: 7 taps en *Dispositivo y software → Acerca de*; permitir apps desconocidas; sideload del APK.
3. (Player) **Dirección del CMS = `http://IP-DEL-SERVIDOR`** (raíz, sin `/prototype`). Clave CMS = *SERVER_KEY* (Ajustes → Configuración → "CMS Secret Key").
4. (CMS) Pantallas → autorizar el display nuevo. Compartirlo con "Operador Pantallas" si aplica.
5. (Contenido) Programar evento con **"Always"** o marcando **"Ejecutar con la hora del CMS"**.
6. Login admin: `xibo_admin` / `password`. CMS: http://localhost (o `http://IP-DEL-SERVIDOR`).
