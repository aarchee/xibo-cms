# CHANGELOG — DisplaFruit Signage

Registro de todos los cambios de DisplaFruit respecto al upstream de Xibo CMS.
Estrategia: **overlay + ediciones de core mínimas**. La mayoría del código vive en
`custom/DisplaFruit/`, en la marca (`docker/brand/`) y en una migración Phinx, para que
`git diff` con upstream sea lo más pequeño posible y los merges futuros sean sencillos.

Rama: `displafruit/main`.

---

## [2026-07-07] Endurecimiento de `web/.htaccess` + documentación de pendientes

Cierre de varios pendientes del `handoff.md` §10 que no requerían decisiones del usuario.

### ✏️ Edición de CORE (mínima, nueva — revisar al hacer merge de upstream)
8. **`web/.htaccess`** — la regla de la SPA de React reescribía **cualquier** `/prototype/*` que no
   fuese fichero real a `/prototype/index.html`. Si por error se configuraba la Dirección del CMS
   como `http://servidor/prototype`, el player pedía `/prototype/xmds.php` y recibía **HTML de React
   en vez de un fallo SOAP** → spinner de "conectando" infinito (era la causa raíz del Problema 2 de
   la sesión del 2026-07-01). Se añade una condición `RewriteCond %{REQUEST_URI} !\.php$` a la regla
   de la SPA: ahora cualquier `.php` bajo `/prototype/` cae a `index.php` y devuelve un **404 claro**
   en lugar de HTML de React. *Verificado:* `/prototype/xmds.php?wsdl` → **404** (antes 200
   `text/html` React); `/xmds.php?what` (raíz) → 200; `/prototype/welcome` (SPA) → 200 intacto.

### 📄 Documentación
- `GUIA_DE_CONFIGURACION_DISPLAFRUIT.md` — nueva sección de **solución de problemas** (los 3 gotchas
  del handoff §1/§6: dirección del CMS sin `/prototype`, horario "Ejecutar con la hora del CMS" /
  "Always", firewall entre subredes) + documentación de la **regla de firewall** aplicada en la
  primera instalación (origen `192.168.100.0/24` → `192.168.250.178:80`).

### 🔎 Verificación de zona horaria (handoff §10)
- Contenedor `web`: corre en **UTC** (`php date.timezone=UTC`), correcto — Xibo gestiona la zona en
  la capa de aplicación vía `defaultTimezone`.
- ⚠️ **Hallazgo:** el ajuste `defaultTimezone` seguía en `Europe/London` y `DEFAULT_LANGUAGE` en
  `en_GB` en la instancia de dev (no se habían cambiado a Europe/Madrid como indica la guía §2). No
  es un cambio de código: debe hacerse en **Ajustes → Regional** del CMS (documentado ya en §2).

---

## [2026-07-02] Panel del operador — mejora significativa (gestión de contenido)

El panel del operador pasa de "estado + publicar en todas" a una herramienta de gestión real.
Todo en `custom/DisplaFruit/` + una migración; **sin ediciones de core nuevas**.

### ✅ Nuevo
- **Migración** `db/migrations/20260702120000_displafruit_publications_migration.php` → tabla
  `displafruit_publication` (historial/estado de cada publicación: media, destino, programación,
  enlaces a schedule/campaign/layout, estado active/stopped). Tabla aislada, sin FKs a core.
- **Publicación segmentada:** además de "Todas", ahora se puede publicar en un **grupo** concreto
  o en una **pantalla** concreta (respetando la ACL del usuario).
- **Programación flexible:** **Temporal** (duración en segundos, interrumpe), **Permanente**
  (hasta que se pare; `toDt = Schedule::$DATE_MAX`, contenido base) y **Rango** (inicio/fin).
  Checkbox **"Urgente"** para forzar prioridad. En todos los casos `syncTimezone = 1` (corre en
  hora del CMS) → evita el estado "Fuera de plazo" por el reloj local de la TV.
- **"En antena ahora":** tarjetas con miniatura, destino, tiempo restante/permanente y botón
  **Parar** (borra el schedule, marca la publicación parada y limpia el player web).
- **Republicar** con un clic desde el **Historial** (reutiliza el mismo media).
- **Estado con auto-refresco:** endpoint JSON `GET /displafruit/dashboard/state` (poll cada 8 s):
  online/offline, **autorizada sí/no** y **miniatura del contenido actual** por pantalla.
- **Miniaturas** `GET /displafruit/dashboard/thumbnail/{id}` (con ACL: solo imágenes referenciadas
  por una publicación DisplaFruit; reutiliza `WidgetDownloader('Off')`).
- **Grupo "DisplaFruit - Todas" auto-sanado:** `syncMembership()` añade las pantallas que falten
  (guarda solo si cambió) al publicar y en cada poll de estado → una pantalla autorizada nueva
  entra en el grupo sin re-publicar. (Grupo dinámico nativo descartado: Xibo exige criterio y no
  admite "todas"; listener descartado: `registerDispatcher` solo corre en XMDS.)

### ✏️ Editado (solo `custom/DisplaFruit/`)
- `Controller/PublishController.php` — refactor: destino (all/group/display) + modos de
  programación + `republish`/`unpublish`/`state`/`history`/`thumbnail` + helpers
  (`resolveTarget`, `syncAllDisplaysGroup`, `recordPublication`, `mapDisplaysToContent`, …).
- `Controller/DashboardController.php` — alimenta selectores (grupos con ACL) e intervalo de poll.
- `Middleware/DisplaFruitMiddleware.php` — registra 5 rutas nuevas (lecturas `displafruit.operator`;
  mutaciones `library.add`).
- `views/displafruit-dashboard.twig` — UI nueva (secciones "En antena ahora", pantallas con
  miniatura/estado, historial) + JS vanilla con auto-refresco (escape de HTML en cliente).

### 🔎 Validación
- `php -l` OK; `phpcs` (ruleset xibo) limpio en `custom/DisplaFruit/` (la migración solo muestra el
  aviso "sin namespace", inherente a Phinx, igual que las migraciones existentes).
- E2E autenticado (admin) verificado: publicar permanente en Todas → `screens_updated`, schedule con
  `toDt=2147483647`, `now_playing` y "current" del display OK; miniatura 200 `image/png`; republicar;
  parar → schedule borrado, publicación `stopped`, `now_playing` limpio (0 schedules vivos al final).
- Compatibilidad: la API `publish-all` antigua (`displayGroupId`/`duration`) sigue funcionando; el
  player web (`/displafruit/player`) intacto.

### 🛡️ Correcciones tras revisión adversarial (mismo día)

Revisión de 3 dimensiones (seguridad/correctitud/regresiones, 10 agentes): 5 hallazgos confirmados,
ninguno bloqueante, todos arreglados en `custom/DisplaFruit/`:

1. **[MED seguridad]** `state()` no acotaba "En antena ahora" por usuario (fuga entre operadores).
   → la lista `nowPlaying` se filtra por `userId` salvo super-admin (coherente con `history()`); el
   mapeo pantalla→contenido sigue completo (refleja lo que se ve en pantallas visibles por ACL).
2. **[MED correctitud]** `state()` ignoraba `fromDt` → un "rango" de inicio futuro salía como activo.
   → añadido `AND (fromDt = 0 OR fromDt <= now)`.
3. **[MED correctitud]** El player web reproducía un "rango" futuro al instante. → `now_playing` solo
   refleja contenido **vigente**: `upsertNowPlaying` no sobrescribe con rangos futuros y `PlayerController`
   filtra por `startAt` (nueva migración `20260702130000`, columna `startAt`).
4. **[LOW correctitud]** `unpublish` podía dejar el player web en blanco si otra publicación activa
   usaba el mismo media. → `reconcileNowPlaying()` re-apunta a la publicación vigente más reciente del
   grupo (o vacía si no queda ninguna).
5. **[LOW seguridad]** `thumbnail()` servía imágenes de otros operadores (IDOR). → comprobación de
   referencia acotada por `userId` salvo super-admin.

Re-validado E2E: publicar permanente (aparece), rango futuro (NO aparece en panel ni player web),
republicar mismo media + parar la vieja (el player NO queda en blanco), limpieza → BD a cero.

---

## [2026-06-29] Correcciones tras revisión de código

Revisión adversarial del código custom contra el core de Xibo (5 hallazgos confirmados,
ninguno bloqueante). Todos los arreglos viven en `custom/DisplaFruit/` y en la migración:

- **[HIGH] `PublishController` — path traversal + cuota.** El nombre de fichero del cliente
  se usaba sin sanear para construir la ruta temporal y `Media->fileName`; un nombre con
  `../` podía escribir fuera de `LIBRARY_LOCATION/temp` (la escritura ocurría antes de
  validar la extensión). Ahora se sanea con `basename(stripslashes(...))` + `trim` (mismo
  saneado que `BlueImpUploadHandler` del core) y se comprueban las cuotas global
  (`LIBRARY_SIZE_LIMIT_KB` vs `MediaService::libraryUsage()`) y por usuario
  (`User::isQuotaFullByUser`). Se inyecta `MediaService` en el controlador (y en el middleware).
- **[MED] `PublishController` — republicar mismo nombre daba 500.** `Media->save()` lanzaba
  `DuplicateEntityException` si el operador reutilizaba un nombre de archivo (caso habitual en
  publicaciones rápidas / Power Automate). Ahora se guarda con `['isMediaReassigned' => true]`
  para que el core renombre automáticamente (patrón de `MediaListener`).
- **[MED] Migración `down()` — error de FK.** El `DELETE` directo sobre `group` fallaba por
  integridad referencial si ya había usuarios/notificaciones asignados (FK RESTRICT). Ahora
  `down()` resuelve el `groupId` y desvincula `lkusergroup`, `lknotificationgroup` y
  `permission` antes de borrar el grupo.
- **[LOW] `PublishController` — `screens_updated` contaba 0 para el operador.** El recuento
  aplicaba la ACL del usuario; ahora pasa `disableUserCheck => 1` para contar todas las
  pantallas del grupo destino (la programación ya cubría todo el grupo; solo era la respuesta).
- **[INFO] Migración — `schedule.now` permiso muerto.** No existe como feature comprobable en
  4.4 (la capacidad "programar ahora" la gobierna `schedule.add`, ya incluida). Eliminado.

> Pendiente de validar en contenedor (Docker requiere WSL2, no instalado en el equipo actual):
> `php -l` de los ficheros custom, `composer phpcs`, migración y prueba end-to-end de `publish-all`.

---

## [2026-06-29] Personalización inicial DisplaFruit

### ✅ Ficheros NUEVOS (no tocan core)

**Entorno de desarrollo / documentación**
- `docker-compose.override.yml` — override para desarrollo en Windows: volumen gestionado
  para MySQL (evita problemas del bind-mount en Windows) y variable `APP_SERVER_IP`.
- `.env.displafruit.example` — plantilla de variables de entorno (incluye `APP_SERVER_IP`).
- `README_DISPLAFRUIT.md` — guía de arranque, branding, rol, API y conexión de pantallas.
- `CHANGELOG_DISPLAFRUIT.md` — este fichero.

**Branding**
- `docker/brand/config.json` — `productName` = "DisplaFruit Signage", `appName` = "DisplaFruit".
  Define el título del navegador y el nombre de la app **sin tocar código**.

**Rol "Operador Pantallas"**
- `db/migrations/20260629120000_displafruit_operator_role_migration.php` — migración Phinx
  que crea el grupo de usuario con un conjunto reducido de *features* (subir contenido,
  publicar, ver estado). Idempotente; `down()` elimina el grupo.

**Código de DisplaFruit (`custom/DisplaFruit/`, namespace `Xibo\Custom\DisplaFruit\`)**
- `custom/settings-custom.php` — registra el middleware de DisplaFruit en `$middleware`.
  Lo incluye automáticamente `web/settings.php` (ver `docker/tmp/settings.php-template`).
- `custom/DisplaFruit/Middleware/DisplaFruitMiddleware.php` — punto de extensión clave:
  - Registra las rutas `/displafruit/dashboard` (web) y `/displafruit/publish-all` (web + API)
    vía `addRoutes()` (lo invoca `State::setMiddleWare()`), **sin editar `lib/routes*.php`**.
  - Registra los controladores en el contenedor PHP-DI en tiempo de ejecución,
    **sin editar `lib/Dependencies/Controllers.php`**.
  - Registra la *feature* personalizada `displafruit.operator`.
- `custom/DisplaFruit/Controller/DashboardController.php` — panel simplificado del operador.
- `custom/DisplaFruit/Controller/PublishController.php` — endpoint `publish-all` (subida →
  layout a pantalla completa → evento de programación de alta prioridad sobre el grupo
  "DisplaFruit - Todas").
- `custom/DisplaFruit/views/displafruit-dashboard.twig` — vista del panel (responsive,
  tarjetas de estado + modal de publicación). Se sirve desde el path Twig `/custom`.
- `custom/DisplaFruit/README.md` — notas del módulo.

### ✏️ Ediciones de CORE (mínimas, justificadas)

> Estas son las **únicas** ediciones de ficheros de Xibo. Revísalas al hacer merge de upstream.

1. **`.gitignore`** — `custom` estaba ignorado por completo. Se cambia a `custom/*` con
   excepciones para versionar `custom/DisplaFruit/`, `custom/settings-custom.php` y
   `custom/README.md`. *Necesario:* el código del fork debe estar en git.

2. **`docker/brand/theme.css`** — se rellena con la paleta DisplaFruit (variables CSS +
   overrides de la UI clásica Bootstrap: navbar, botones primarios, enlaces, menú lateral).
   *El fichero ya existía vacío y es el punto previsto para personalizar colores.*

3. **`docker/brand/logo.svg`** — se sustituye el logo de Xibo por un **wordmark placeholder**
   de DisplaFruit. *Sustituir por el logo oficial (mismo nombre).*

4. **`docker/entrypoint.sh`** — se añade el aprovisionamiento de `library/brand/config.json`
   (3 líneas), después del bloque de layouts, para que el título/nombre de marca se desplieguen.

5. **`views/login.twig`** — branding del login:
   - Logo del login: `brand/xibologo.png` → `brand/logo.svg`.
   - Se añade título (`theme_title` = "DisplaFruit Signage") y subtítulo "Gestión de pantallas".
   - Se añade pie de copyright "© DisplaFruit S.A. — Sistema de Cartelería Digital".
   *Motivo:* estos textos están "hardcodeados" en Twig (`{% trans %}`); no hay override por
   configuración en Xibo.

6. **`views/licence.twig`** (página *Acerca de*) — se añade encabezado de marca DisplaFruit
   **conservando** el copyright, la licencia AGPLv3 y el aviso de código fuente de Xibo
   (requisito de la AGPLv3). *Motivo:* idéntico al anterior (texto hardcodeado).

7. **`lib/Controller/User.php`** (método `home()`) — 6 líneas: si el usuario no es Super Admin
   y tiene la *feature* `displafruit.operator`, se le redirige a `/displafruit/dashboard`.
   *Motivo:* `home()` redirige por defecto al dashboard React (`/prototype/dashboard`); este
   es el único punto limpio para que el operador aterrice en su panel.

### ℹ️ Decisiones de diseño

- **Publicación "a todas las pantallas":** se usa un evento de programación de **media** de
  **alta prioridad** (no se borra la programación existente; convive y expira solo), sobre un
  grupo de display dedicado **"DisplaFruit - Todas"** que el controlador crea y sincroniza
  con todas las pantallas. Cubre también las pantallas offline (lo recogen al reconectar).
- **Rutas sin editar el core:** se aprovecha que `State::setMiddleWare()` llama a `addRoutes()`
  sobre los middleware declarados en `settings-custom.php`, antes de cargar las rutas del core.
- **Ubicaciones idiomáticas de Xibo:** el brief pedía `app/DisplaFruit/` y `assets/displafruit/`,
  que no existen en Xibo (Slim, no Laravel). Se usan `custom/DisplaFruit/` (PSR-4) y
  `docker/brand/` (marca servida vía `/brand`).

### ⚠️ Pendiente / a verificar en el contenedor
- Sustituir el logo placeholder por el oficial (`docker/brand/logo.svg`, `xibologo.png`).
- `php -l` de los ficheros de `custom/DisplaFruit/` y `composer phpcs` (ver README, §8).
- Prueba funcional end-to-end del botón "Publicar en todas las pantallas" con una TV real.
