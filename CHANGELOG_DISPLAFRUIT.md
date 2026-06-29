# CHANGELOG — DisplaFruit Signage

Registro de todos los cambios de DisplaFruit respecto al upstream de Xibo CMS.
Estrategia: **overlay + ediciones de core mínimas**. La mayoría del código vive en
`custom/DisplaFruit/`, en la marca (`docker/brand/`) y en una migración Phinx, para que
`git diff` con upstream sea lo más pequeño posible y los merges futuros sean sencillos.

Rama: `displafruit/main`.

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
