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

## Las dos consultas a adaptar (en `server.js` o por entorno)

1. **KPI_QUERY** — debe devolver **una fila**; cada columna es una tarjeta KPI y su
   **alias es la etiqueta** que se ve en pantalla. Ejemplo:
   ```sql
   SELECT SUM(kg) AS [Kg producidos hoy], COUNT(DISTINCT pedido) AS [Pedidos servidos]
   FROM produccion WHERE fecha = CAST(GETDATE() AS date)
   ```
2. **LINES_QUERY** — filas para la tabla, con columnas `linea`, `producto`, `kg`, `estado`.

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
