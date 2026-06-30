# DisplaFruit Signage — Guía de despliegue y uso

Sistema interno de cartelería digital de **DisplaFruit S.A.**, construido sobre
[Xibo CMS](https://github.com/xibosignage/xibo-cms) (código abierto, AGPLv3).
Este documento describe cómo arrancar el entorno desde cero, las personalizaciones
de DisplaFruit y cómo publicar contenido en las pantallas.

> **Responsable IT:** Raúl — rvizcaino@displafruit.com
> **Rama de trabajo:** `displafruit/main`

---

## 0. Aclaraciones importantes sobre Xibo (no es lo que parece)

El brief original asumía algunas cosas que **no** son ciertas en Xibo. Se han corregido:

| Suposición del brief | Realidad de Xibo |
|---|---|
| Framework **Laravel** | **Slim 4** + PHP-DI + Phinx + Symfony EventDispatcher |
| CMS en `http://localhost:8080` | CMS en **`http://localhost`** (puerto **80**). El 8080 es la **Swagger UI** |
| Código PHP en `app/DisplaFruit/` | Código en **`custom/DisplaFruit/`** (namespace `Xibo\Custom\DisplaFruit\`) |
| Rol vía **seeder de Laravel** | Rol vía **migración Phinx** (`db/migrations/`) |
| CSS en `assets/displafruit/` | Marca en **`docker/brand/`** → se sirve desde `/brand` |

Puertos del entorno de desarrollo (`docker-compose.yml`):

| Servicio | Puerto |
|---|---|
| CMS (web) | **80** |
| Swagger UI (documentación API) | 8080 |
| MySQL | 3315 |
| XMR (router de mensajes a players) | 9505 |

---

## 1. Requisitos

- **Docker Desktop** para Windows 11 (backend WSL2).
- **Git**.
- Navegador moderno.

No necesitas instalar PHP, Composer ni Node en el PC. **Pero ojo:** el contenedor de
desarrollo (`Dockerfile.dev`) **no incluye Composer ni Node** y monta el código del host tal
cual, así que la **primera vez** hay que generar las dependencias (`vendor/` y `web/dist`) y la
carpeta `cache/` con contenedores auxiliares (paso 2.4 más abajo). Es un único arranque; el día
a día es solo `docker compose up -d`.

---

## 2. Arranque del entorno de desarrollo (Windows)

```powershell
# 1. Clonar el fork y situarse en la rama de trabajo
git clone <URL-del-fork> xibo-cms
cd xibo-cms
git checkout displafruit/main

# 2. (Opcional) Variables de entorno. Copia el ejemplo a .env y ajústalo.
copy .env.displafruit.example .env

# 3. Levantar los contenedores (la primera vez compila la imagen de desarrollo)
docker compose up --build -d

# 4. PREPARAR DEPENDENCIAS — SOLO LA PRIMERA VEZ (el contenedor dev no trae Composer/Node)
#    a) Librerías PHP -> genera vendor/ (sin esto, no hay migraciones ni arranque del CMS)
docker run --rm -v "${PWD}:/app" composer:2 install --ignore-platform-reqs --no-interaction
#    b) Recursos web -> genera web/dist (node_modules en un volumen para que sea rápido en Windows)
docker run --rm -v "${PWD}:/app" -v xibo_node_modules:/app/node_modules -w /app node:20 `
  sh -c "npm install --no-audit --no-fund && npm run build"
#    c) Carpeta cache/ con permisos (si no, da "Installation Error: Cannot write... Cache Folder")
docker compose exec -T web sh -c "mkdir -p /var/www/cms/cache /var/www/cms/library/temp; chmod -R 777 /var/www/cms/cache /var/www/cms/library; chown -R www-data:www-data /var/www/cms/cache /var/www/cms/library"
#    d) Aplicar BD + rol de operador (reinicia: el entrypoint instala solo al ver la BD vacía)
docker compose restart web
```

- El fichero **`docker-compose.override.yml`** se fusiona automáticamente y, en Windows,
  guarda los datos de MySQL en un **volumen gestionado por Docker** (evita problemas de
  bloqueo de ficheros del bind-mount `./containers/db`).
- La primera vez, el contenedor crea la base de datos, ejecuta las migraciones, genera
  las claves RSA de la API y aprovisiona la marca de DisplaFruit. Puede tardar 1-2 minutos.

Sigue el arranque con:

```powershell
docker compose logs -f web
```

Cuando veas `Starting webserver`, abre:

- **CMS:** http://localhost
- **Login:** `xibo_admin` / `password`  *(cámbiala tras el primer acceso)*
- **Swagger UI (API):** http://localhost:8080
- **API REST:** http://localhost/api  (p. ej. `GET http://localhost/api/about` con token)

### Desarrollo en vivo

El proyecto se monta en el contenedor (`./:/var/www/cms`) con `CMS_DEV_MODE=true`.
Los cambios en **PHP** y **Twig** se reflejan sin reconstruir la imagen ni reiniciar.

Si tocas `custom/settings-custom.php` no hace falta reiniciar (se lee en cada petición).
Si algo no refresca, limpia la caché:

```powershell
docker compose exec web rm -rf cache/
```

### Parar / reiniciar

```powershell
docker compose down            # parar (conserva datos)
docker compose down -v         # parar y BORRAR datos (empezar de cero)
docker compose up -d           # arrancar de nuevo
```

---

## 3. Aplicar la migración del rol "Operador Pantallas"

- En una **instalación nueva** (BD vacía), la migración se ejecuta **sola** al arrancar.
- En una **BD existente en desarrollo** (`CMS_DEV_MODE=true`), las migraciones **no** se
  ejecutan automáticamente. Lánzala a mano:

```powershell
docker compose exec web php vendor/bin/phinx migrate -c phinx.php
```

Esto crea el grupo de usuario **"Operador Pantallas"**.

---

## 4. Branding (marca DisplaFruit)

La marca vive en **`docker/brand/`** (versionada) y se copia a `library/brand/` en el
primer arranque, sirviéndose vía el alias Apache `/brand`.

| Elemento | Fichero / sitio |
|---|---|
| Título del navegador / nombre de app | `docker/brand/config.json` (`productName`, `appName`) |
| Colores corporativos | `docker/brand/theme.css` (variables CSS + overrides Bootstrap) |
| Logo (login y frontend React) | `docker/brand/logo.svg` *(placeholder — sustituir)* |
| Logo de login alternativo | `docker/brand/xibologo.png` *(sustituir por el oficial)* |
| Favicon | `docker/brand/favicon.ico` |
| Iconos PWA | `docker/brand/192x192.png`, `512x512.png`, `logo-icon.svg` |
| Texto del login (título/subtítulo/footer) | `views/login.twig` |
| Aviso de copyright / Acerca de | `views/licence.twig` |

**Colores corporativos aplicados:** naranja `#F47920`, marrón `#3D2B1F`, blanco `#FFFFFF`,
gris claro de fondo `#F5F5F5`.

> ⚠️ **Logo provisional.** `docker/brand/logo.svg` es un placeholder con el wordmark
> "DisplaFruit". Sustituye `logo.svg` y `xibologo.png` (y opcionalmente favicon / iconos)
> por los oficiales — mismos nombres de fichero. Tras cambiarlos en una instalación ya
> arrancada, copia los nuevos a `library/brand/` o borra `library/brand/` y reinicia.

> ℹ️ **Cumplimiento AGPLv3.** En la página *Acerca de* se conservan el copyright, la
> licencia y el aviso de acceso al código fuente de Xibo, añadiendo la marca de DisplaFruit
> encima. No se eliminan dichos avisos.

---

## 5. Crear un usuario "Operador Pantallas"

1. Entra como administrador (`xibo_admin`).
2. **Administración → Usuarios → Añadir Usuario**.
3. Tipo de usuario: **User** (no Super Admin).
4. En **Grupos**, asígnalo al grupo **"Operador Pantallas"**.
5. Guarda.

Al iniciar sesión, ese usuario es redirigido automáticamente a su panel simplificado
**`/displafruit/dashboard`**:

- Tarjetas con el estado de cada pantalla (verde = online, rojo = offline).
- Botón grande **"Publicar en todas las pantallas"** (sube imagen/vídeo/PDF y lo
  programa al instante en todas las pantallas).
- Iconos: **Pantallas**, **Contenido**, **Cerrar sesión**.
- Responsive: usable desde iPad/tablet en almacén.

El operador **no** ve: layouts avanzados, datasets, informes, módulos/widgets, gestión
de usuarios ni configuración del sistema (ocultos por su conjunto de *features*).

> **Permisos sobre las pantallas (importante).** En Xibo, un usuario normal solo ve los
> objetos que posee o que se le han compartido. Para que el operador vea las pantallas en
> el panel y pueda publicar en ellas, el administrador debe **compartir las pantallas /
> grupos de display con el grupo "Operador Pantallas"** (Pantallas → Permisos), o bien
> activar **Configuración → Displays → "Schedule with view permission"** y conceder *vista*.
> Para automatizaciones (Power Automate) usa una **Aplicación OAuth cuyo propietario sea un
> administrador**: el token tendrá acceso completo y la publicación funcionará sin fricción.

---

## 6. Endpoint API: publicar en todas las pantallas

```
POST /api/displafruit/publish-all
Content-Type: multipart/form-data
Authorization: Bearer {token}

Campos:
  file            (obligatorio)  archivo imagen/vídeo/PDF
  duration        (opcional)     segundos en antena (por defecto 30)
  name            (opcional)     nombre descriptivo del contenido
  displayGroupId  (opcional)     destino alternativo (por defecto: todas las pantallas)

Respuesta:
  { "success": true, "screens_updated": N, "layout_id": X }
```

**Qué hace:** sube el archivo a la biblioteca → crea un layout a pantalla completa
(publicado) → lo programa como **evento de alta prioridad** (ahora → ahora + `duration`)
sobre el grupo **"DisplaFruit - Todas"** (que sincroniza con todas las pantallas).

### Obtener un token (OAuth2, para Power Automate)

1. **Administración → Aplicaciones → Añadir Aplicación**.
2. Tipo *Client Credentials*. Anota `client_id` y `client_secret`.
3. Solicita el token:

```bash
curl -X POST http://APP_SERVER_IP/api/authorize/access_token \
  -d "grant_type=client_credentials" \
  -d "client_id=CLIENT_ID" \
  -d "client_secret=CLIENT_SECRET"
```

4. Publica:

```bash
curl -X POST http://APP_SERVER_IP/api/displafruit/publish-all \
  -H "Authorization: Bearer TOKEN" \
  -F "file=@oferta-platano.jpg" \
  -F "duration=30" \
  -F "name=Oferta plátano IGP"
```

> El mismo controlador atiende también la ruta web `/displafruit/publish-all`
> (sesión + CSRF), que es la que usa el botón del panel del operador.

---

## 7. Conectar las pantallas (Smart TV con Android TV)

1. Instala **Xibo for Android** (APK) en cada Smart TV.
2. En la app, configura la dirección del CMS: **`http://APP_SERVER_IP`**
   (en desarrollo, la IP del PC en la LAN; en producción, la IP fija del servidor).
3. **CMS Key:** el valor de `SERVER_KEY` (Configuración → ... o consulta con IT).
4. En el CMS, **Pantallas (Displays)**: autoriza/da de alta la pantalla que aparece.
5. **XMR (cambios en tiempo real):** en **Configuración → Displays → XMR Public Address**
   pon **`tcp://APP_SERVER_IP:9505`** para que las publicaciones lleguen al instante.

`APP_SERVER_IP` se define en `.env` (ver `.env.displafruit.example`).

---

## 8. Verificación rápida (checklist)

- [ ] `docker compose up -d` y `http://localhost` muestra el login con marca DisplaFruit.
- [ ] La pestaña del navegador dice **"DisplaFruit Signage"**.
- [ ] Migración aplicada: existe el grupo **"Operador Pantallas"** en Administración → Grupos.
- [ ] Un usuario de ese grupo, al loguearse, aterriza en `/displafruit/dashboard`.
- [ ] El botón "Publicar en todas las pantallas" sube un archivo y devuelve `success: true`.
- [ ] (Con una TV dada de alta) el contenido aparece en la pantalla.

### Validar el código PHP (en el contenedor)

```powershell
docker compose exec web php -l custom/DisplaFruit/Controller/PublishController.php
docker compose exec web php -l custom/DisplaFruit/Controller/DashboardController.php
docker compose exec web php -l custom/DisplaFruit/Middleware/DisplaFruitMiddleware.php
docker compose exec web composer phpcs   # estilo de código (xibo_ruleset)
```

---

## 9. Mantenimiento y actualizaciones de Xibo

Todo el código de DisplaFruit vive, siempre que es posible, fuera del core para que
`git diff` con upstream sea mínimo. Ver **`CHANGELOG_DISPLAFRUIT.md`** para la lista exacta
de ficheros nuevos y ediciones de core, con su justificación.

Al actualizar Xibo (merge de upstream en `displafruit/main`), revisa los pocos ficheros
core editados que aparecen marcados en el changelog.
