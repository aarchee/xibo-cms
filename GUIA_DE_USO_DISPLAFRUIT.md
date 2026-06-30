# 🍌 DisplaFruit — Guía de uso (para empezar de cero)

Esta guía está escrita en lenguaje sencillo, para usar el sistema **sin saber nada de
programación**. Si buscas la parte técnica (Docker, API, despliegue), está en
`README_DISPLAFRUIT.md`.

---

## 1. ¿Qué es esto, en cristiano?

**DisplaFruit** es tu sistema para **mostrar carteles digitales en pantallas** (Smart TVs,
monitores…) repartidas por la tienda, el almacén, etc. Tú subes una **imagen, un vídeo o un
PDF** desde el ordenador y aparece en las pantallas. Está montado sobre un programa de código
abierto llamado **Xibo**; nosotros le hemos puesto la marca DisplaFruit y un panel simplificado.

Tiene **dos piezas**:

1. **El CMS** (el "cerebro"): la web donde gestionas todo. Corre en tu ordenador dentro de
   *Docker* (una especie de "caja" que tiene todo lo necesario para funcionar).
2. **Las pantallas** (los "players"): cada Smart TV lleva la app **Xibo for Android**, que se
   conecta al CMS y muestra lo que tú publicas.

---

## 2. Encender el sistema (cada día)

> Solo en el ordenador donde está instalado (el "servidor"). Necesitas **Docker Desktop**
> abierto (icono de la ballena, abajo a la derecha, en verde).

1. Abre **Docker Desktop** y espera a que ponga *"Engine running"*.
2. Abre **PowerShell** en la carpeta del proyecto. Lo más fácil: abre la carpeta
   `xibo-cms` en el explorador, escribe `powershell` en la barra de direcciones y pulsa Enter.
3. Escribe esto y pulsa Enter:

   ```powershell
   docker compose up -d
   ```

4. Espera ~30 segundos. Ya está encendido.

**Comprobar que está listo:** abre el navegador en **http://localhost**. Debe salir la
pantalla de acceso con el logo de DisplaFruit.

> 💡 La **primera vez en un ordenador nuevo** hay que hacer una preparación extra (instalar
> dependencias). Está explicado en el apartado **9. Instalar en un PC nuevo**. Una vez hecho,
> el día a día es solo el `docker compose up -d` de arriba.

---

## 3. Entrar al sistema (login)

1. Ve a **http://localhost**.
2. Usuario y contraseña del administrador:
   - Usuario: **`xibo_admin`**
   - Contraseña: **`password`**
3. **Cambia esa contraseña** el primer día: arriba a la derecha, tu nombre → *Editar perfil*.

> ⚠️ `xibo_admin` es el **administrador**: lo ve y lo puede todo. Para el uso diario conviene
> crear usuarios **Operador** (apartado 6), que ven una pantalla simple y solo pueden publicar.

---

## 4. Las dos formas de usar el sistema

| | **Administrador** (`xibo_admin`) | **Operador de Pantallas** |
|---|---|---|
| Para quién | IT / responsable | Empleados de tienda/almacén |
| Qué ve | Todo el CMS de Xibo (complejo) | Un panel simple: estado de pantallas + botón "Publicar" |
| Qué hace | Configura, da de alta pantallas, crea usuarios | Sube contenido y lo manda a las pantallas |
| Dónde aterriza al entrar | Panel de administración de Xibo | **`/displafruit/dashboard`** (el panel simple) |

**Recomendación:** usa el operador para el día a día. El admin, solo para configurar.

---

## 5. ⭐ Publicar contenido en las pantallas (lo más importante)

Esto es lo que harás el 90% del tiempo. Desde el **panel del operador**:

1. Entra con un usuario **Operador** (o, como admin, ve a **http://localhost/displafruit/dashboard**).
2. Verás tarjetas con tus pantallas (🟢 verde = encendida y conectada; 🔴 roja = apagada/sin conexión).
3. Pulsa el botón grande **📢 "Publicar en todas las pantallas"**.
4. En la ventana que se abre:
   - **Archivo:** elige una **imagen, vídeo o PDF** del ordenador.
   - **Nombre** (opcional): por ejemplo *"Oferta plátano IGP"*.
   - **Duración en pantalla:** cuántos **segundos** estará visible (por defecto 30).
5. Pulsa **Publicar**.
6. Saldrá un mensaje verde: *"✅ Publicado en N pantalla(s)"*. El contenido aparece **al
   instante** en todas las pantallas encendidas, **con prioridad alta** (interrumpe lo que
   hubiera). Cuando pasan los segundos indicados, las pantallas vuelven a su contenido normal.

> Si pone *"Publicado en 0 pantallas"*, es que **no hay ninguna TV encendida y conectada** en
> ese momento. El contenido igualmente queda programado.

---

## 6. Crear un usuario Operador (para tus empleados)

Como **administrador**:

1. Menú **Administración → Usuarios → Añadir Usuario**.
2. Rellena nombre de usuario y contraseña.
3. **Tipo de usuario: `User`** (NO "Super Admin").
4. En la pestaña/sección **Grupos**, marca **"Operador Pantallas"**.
5. Guarda.

Cuando esa persona entre, irá directa a su panel simple. **No verá** menús complicados
(layouts, informes, configuración…): solo pantallas y el botón de publicar.

> 🔑 **Paso clave que se olvida:** para que el operador **vea las pantallas** y pueda publicar
> en ellas, el administrador debe **compartirlas con el grupo "Operador Pantallas"**:
> menú **Pantallas (Displays)** → en cada pantalla o grupo, **Permisos** → da acceso al grupo
> "Operador Pantallas". Sin esto, el operador entra pero el panel le saldrá vacío.

---

## 7. Conectar una pantalla nueva (Smart TV)

Esto lo hace el **administrador/IT**. Hay **dos vías** (detalle en `GUIA_DE_CONFIGURACION_DISPLAFRUIT.md`, apartado 5):

> ⭐ **Vía GRATIS (recomendada): "player web".** En la Smart TV instala un navegador-kiosko gratuito
> que arranque solo y abre a pantalla completa la URL **`http://LA-IP-DEL-PC/displafruit/player`**.
> Ya está: muestra lo que publiques, sin licencia ni dar de alta la pantalla. (Solo imagen/vídeo/PDF;
> el vídeo va sin sonido.)

La otra vía (de pago, app oficial **Xibo for Android**, ~28 € por pantalla, con todas las funciones):

1. En la Smart TV (Android TV), instala la app **Xibo for Android**.
2. Ábrela y, en sus ajustes, pon la **dirección del CMS**:
   - Si la TV está en la misma red que el ordenador-servidor: `http://LA-IP-DEL-PC`
     (la IP del ordenador en la red local; pregúntala a IT o mírala con `ipconfig`).
   - `http://localhost` **no** sirve desde la TV (eso solo vale dentro del propio servidor).
3. Pon la **CMS Key** (una clave que da IT; es el valor `SERVER_KEY` del sistema).
4. Vuelve al CMS como admin → **Pantallas (Displays)**: aparecerá la TV pidiendo permiso.
   **Autorízala**.
5. Para que las publicaciones lleguen **al instante**, en **Configuración → Displays → XMR
   Public Address** pon `tcp://LA-IP-DEL-PC:9505`.
6. Comparte la pantalla con el grupo **"Operador Pantallas"** (ver apartado 6).

A partir de ahí, esa TV ya cuenta como una pantalla y recibirá lo que publiques.

---

## 8. Apagar el sistema

```powershell
docker compose down        # apaga y CONSERVA todo (lo normal)
docker compose up -d        # vuelve a encender
```

> ❌ **No uses** `docker compose down -v`: eso **borra todos los datos** (pantallas, usuarios,
> contenido). Solo para empezar de cero a propósito.

---

## 9. Instalar en un PC nuevo (primera vez)

> Solo la primera vez en un ordenador que nunca ha tenido el sistema. Si ya funciona, salta esto.

**Requisitos:** Docker Desktop (con la **virtualización VT-x activada en la BIOS**), Git y un
navegador. *(Si Docker dice "Virtualization support not detected", hay que activar VT-x en la
BIOS — reiniciar → Configuración de firmware UEFI → Advanced/CPU → Intel Virtualization.)*

```powershell
# 1. Descargar el proyecto y situarse en la rama de trabajo
git clone <URL-del-fork> xibo-cms
cd xibo-cms
git checkout displafruit/main

# 2. Construir y levantar los contenedores
docker compose up --build -d

# 3. Preparar dependencias (el contenedor de desarrollo NO las trae; hay que generarlas)
#    a) Librerías PHP (genera la carpeta vendor/)
docker run --rm -v "${PWD}:/app" composer:2 install --ignore-platform-reqs --no-interaction
#    b) Recursos web clásicos (genera web/dist) — node_modules va a un volumen para que sea rápido
docker run --rm -v "${PWD}:/app" -v xibo_node_modules:/app/node_modules -w /app node:20 `
  sh -c "npm install --no-audit --no-fund && npm run build"
#    b2) Frontend moderno React (las páginas /prototype/*) -> web/prototype. SIN ESTE PASO,
#        al iniciar sesión sale "Internal Server Error" (error 500). Es obligatorio.
docker run --rm -v "${PWD}:/app" -v xibo_frontend_nm:/app/frontend/node_modules -w /app/frontend node:22 `
  sh -c "npm install --no-audit --no-fund && npm run build"
docker compose exec -T web sh -c "rm -rf /var/www/cms/web/prototype; cp -r /var/www/cms/frontend/dist /var/www/cms/web/prototype; chown -R www-data:www-data /var/www/cms/web/prototype"
#    c) Carpeta de caché con permisos de escritura
docker compose exec -T web sh -c "mkdir -p /var/www/cms/cache /var/www/cms/library/temp; chmod -R 777 /var/www/cms/cache /var/www/cms/library; chown -R www-data:www-data /var/www/cms/cache /var/www/cms/library"

# 4. Aplicar la base de datos y el rol de operador (reinicia el web: se instala solo)
docker compose restart web
#    espera ~40s; comprueba con:
docker compose logs --tail 5 web   # debe poner "All Done" y "Starting webserver"
```

Luego entra en http://localhost con `xibo_admin` / `password`.

> Si te sale *"Installation Error: Cannot write files into the Cache Folder"*, repite el
> paso **3c** (faltan permisos en la carpeta `cache`).

---

## 10. Si algo va mal (problemas frecuentes)

| Síntoma | Qué hacer |
|---|---|
| La web no carga (http://localhost) | ¿Está Docker Desktop en verde? ¿Hiciste `docker compose up -d`? |
| **Error 500 / "Internal Server Error" tras iniciar sesión** | Falta compilar el frontend React: paso **9.3 b2** (genera `web/prototype`). |
| *"Installation Error: Cannot write... Cache Folder"* | Paso **9.3c** (crear `cache/` y dar permisos). |
| Páginas sin estilos / rotas | Falta compilar recursos: paso **9.3b** (`web/dist`). |
| Errores de "tabla no existe" / login falla | Faltan las dependencias PHP o la base de datos: pasos **9.3a** y **9.4**. |
| El operador entra pero no ve pantallas | Comparte las pantallas con el grupo "Operador Pantallas" (apartado 6). |
| "Publicado en 0 pantallas" | No hay ninguna TV encendida y conectada en ese momento. |
| La TV no aparece para autorizar | Revisa la dirección del CMS y la CMS Key en la app de la TV (apartado 7). |
| Refrescar tras tocar configuración | `docker compose exec web rm -rf cache/` |

**Para empezar completamente de cero** (borra TODO y reinstala limpio):

```powershell
docker compose down
docker compose exec -T db mysql -uroot -proot -e "DROP DATABASE cms; CREATE DATABASE cms;"
docker compose up -d   # se reinstala solo al arrancar
```

---

## 11. Glosario rápido

- **CMS:** la web de gestión (el cerebro). En tu ordenador: http://localhost.
- **Display / Pantalla:** cada Smart TV con la app, conectada al CMS.
- **Layout:** un "diseño" de pantalla. Al publicar, se crea uno automáticamente; no necesitas
  saber de esto para el uso normal.
- **Biblioteca (Library):** donde se guardan tus imágenes/vídeos/PDF subidos.
- **Publicar:** subir un archivo y mandarlo a las pantallas con prioridad.
- **Operador Pantallas:** el rol de usuario simple para el día a día.
- **Docker:** la "caja" que hace funcionar el CMS en tu ordenador.

---

*DisplaFruit S.A. — Sistema de Cartelería Digital. Soporte técnico: ver `README_DISPLAFRUIT.md`.*
