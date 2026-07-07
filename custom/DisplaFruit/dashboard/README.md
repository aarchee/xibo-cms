# DisplaFruit — Dashboard de producción para pantallas

Mini-servicio que muestra **datos de producción en vivo** (SQL Server) en las pantallas:
la TV abre una URL y ve KPIs y líneas que se **refrescan solos** cada X segundos, sin
re-publicar nada en Xibo.

- `GET /` — la página del dashboard (pensada para TV 1080p, sin librerías, WebView-friendly).
- `GET /api/data` — JSON con KPIs + líneas (SQL Server, o datos DEMO si no hay credenciales).
- `GET /healthz` — comprobación de vida.

## Modos

| Modo | Cuándo | Qué muestra |
|---|---|---|
| **DEMO** (por defecto) | `MSSQL_HOST` vacío | Datos de ejemplo que varían en cada refresco + badge "MODO DEMO" |
| **SQL** | `MSSQL_HOST` definido | El resultado de las consultas contra SQL Server |

Si la BD deja de responder, se sirve **el último dato bueno** con el aviso
"⚠ Sin conexión…" (la pantalla nunca se queda en blanco).

## Configuración (variables de entorno, ver `.env`)

| Variable | Descripción | Por defecto |
|---|---|---|
| `MSSQL_HOST` | IP/host del SQL Server (vacío = modo DEMO) | *(vacío)* |
| `MSSQL_PORT` | Puerto | `1433` |
| `MSSQL_INSTANCE` | Instancia nombrada (si aplica; entonces ignora el puerto) | *(vacío)* |
| `MSSQL_DATABASE` | Base de datos | *(vacío)* |
| `MSSQL_USER` / `MSSQL_PASSWORD` | Usuario **de solo lectura** (SQL auth) | *(vacío)* |
| `MSSQL_ENCRYPT` | `true` si el servidor exige TLS | `false` |
| `MSSQL_TRUST_CERT` | Confiar en el certificado del servidor | `true` |
| `REFRESH_SECONDS` | Cada cuántos segundos se refresca la pantalla | `20` |
| `DASHBOARD_TITLE` | Título mostrado en la cabecera | `Producción diaria` |
| `DASHBOARD_KPI_QUERY` | (Opcional) Consulta de KPIs, sobreescribe la de `server.js` | — |
| `DASHBOARD_LINES_QUERY` | (Opcional) Consulta de líneas, sobreescribe la de `server.js` | — |

## Origen de datos (configurado — BD `ReportingData` de Hispatec)

El dashboard está **conectado y validado** (2026-07-07) contra la BD de reporting real. Se
exploró el esquema y se eligieron las tablas con datos **al día**:

| Tabla | Qué es | Se usa para |
|---|---|---|
| `dbo.ProduccionLineal` | Producción confeccionada (`Cantidad`=kg, `NroEnvases`=cajas, `FechaFabricacion`) | KPIs "Kg producidos"/"Cajas hoy" + tabla por producto |
| `dbo.MercanciaVolcada` | Materia prima volcada en línea, en tiempo real (`PesoNetoVolcado`=kg, `NombreLinea`, `Fecha`) | KPI "Kg volcados hoy" |
| `dbo.ExistenciasMercancia` | Stock actual en cámara (snapshot; `Palets`) | KPI "Palets en cámara" |

> `dbo.InformePedidosVentaUL` (pedidos de venta) se **descartó**: sus datos terminan en 2023,
> saldría a 0. Si más adelante se rellena, es la fuente natural para KPIs de pedidos/servido.

`GETDATE()` en este servidor devuelve la **hora local (Europe/Madrid)**, por lo que el filtro
`CAST(campo AS date) = CAST(GETDATE() AS date)` acota "hoy" correctamente.

## Las dos consultas (en `server.js`, sobreescribibles por entorno)

La **tabla renderiza columnas dinámicas**: el *alias* de cada columna de `LINES_QUERY` es la
cabecera; las columnas numéricas se alinean a la derecha y una columna llamada `estado` se pinta
como badge verde/rojo ("en marcha" / resto). Así se puede cambiar la consulta sin tocar el HTML.

1. **KPI_QUERY** — devuelve **una fila**; cada columna es una tarjeta KPI y su **alias es la
   etiqueta**. La actual suma la producción/volcado del día y el stock (ver `server.js`).
2. **LINES_QUERY** — filas para la tabla. La actual es **producción de hoy por producto**
   (`Producto`, `Kg`, `Cajas`). Para ver **estado de líneas** en su lugar, una consulta sobre
   `dbo.MercanciaVolcada` agrupando por `NombreLinea` con una columna `estado` (p. ej.
   `CASE WHEN MAX(Fecha) > DATEADD(MINUTE,-30,GETDATE()) THEN 'En marcha' ELSE 'Parada' END`).

Las credenciales reales viven en el **`.env`** de la raíz (no versionado); la plantilla es
`.env.displafruit.example`.

## Arranque

Está integrado en `docker-compose.override.yml` (servicio `displafruit-dashboard`, puerto
**8090** del host):

```powershell
docker compose up -d --build displafruit-dashboard
```

- Desde el PC: http://localhost:8090
- Desde la TV: http://IP-DEL-SERVIDOR:8090  *(la subred de las TVs debe alcanzar el 8090/tcp)*

## Mostrarlo en las pantallas

**Con Xibo for Android:** Diseño → Layout con widget **"Página web"** →
URL `http://IP-DEL-SERVIDOR:8090` → programarlo (o marcar Urgente/Permanente según el caso).

**Nota player web gratuito:** el player web de DisplaFruit (`/displafruit/player`) hoy solo
muestra imagen/vídeo/PDF; para el dashboard, apunta el navegador-kiosko de esa TV
directamente a `http://IP-DEL-SERVIDOR:8090` (o pedir la extensión "publicar URL").

## Seguridad

- Usar SIEMPRE un usuario SQL **de solo lectura** y limitado a las vistas/tablas necesarias.
- Las credenciales van en `.env` (no versionado), nunca en el código.
- El dashboard no expone datos fuera de la LAN salvo que se abra el puerto a propósito.
