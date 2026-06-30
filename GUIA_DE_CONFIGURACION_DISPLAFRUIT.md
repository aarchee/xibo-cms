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

## 5. 🟢 (Escenario B) Conectar una Smart TV (paso a paso)

Repite por cada pantalla:

1. En la Smart TV (Android TV), instala la app **Xibo for Android** (Play Store o APK).
2. Ábrela → ajustes de la app:
   - **CMS Address / Dirección:** `http://IP-SERVIDOR`
   - **CMS Key / Clave:** la del paso 4a.
3. La TV aparecerá en el CMS pidiendo permiso. Ve a **menú → Pantallas (Displays)** y **autorízala**
   (botón *Authorise* / *Autorizar*). Ponle un nombre reconocible (p. ej. "Entrada tienda").
4. **Perfil:** las pantallas Android usan automáticamente el perfil **"Android"** (ya viene creado).
   No necesitas tocar nada salvo que quieras ajustes finos (volumen, horarios de encendido…), que
   están en **Pantallas → Perfiles de Display → Android**.
5. **Comparte la pantalla con el grupo "Operador Pantallas"** (ver paso 6) para que tus operadores
   puedan publicar en ella.

> Cuando la tarjeta de la pantalla esté **verde** en el panel, está lista para recibir contenido.

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
- [ ] (Escenario B) Al menos una Smart TV conectada, **autorizada** y en **verde**.
- [ ] Pantallas **compartidas** con el grupo "Operador Pantallas".
- [ ] Al menos un usuario **Operador** creado y probado (publica y aparece en pantalla).
- [ ] Layout por defecto con marca DisplaFruit.
- [ ] (Recomendado) SMTP configurado.
- [ ] Copia de seguridad hecha (BD + `library/`).

---

*DisplaFruit S.A. — Sistema de Cartelería Digital. ¿Dudas técnicas? `README_DISPLAFRUIT.md`.*
