# DisplaFruit — Dashboard de producción: estado y pendientes

> Documento de continuidad (2026-07-08). Resume qué está hecho, el **conocimiento de negocio**
> descubierto (lo más valioso), las consultas clave y las **decisiones pendientes**. Para retomar:
> "continúa con el dashboard de producción de DisplaFruit".

## Qué es
Micro-servicio Node (sin framework) en `custom/DisplaFruit/dashboard/` que muestra en una TV la
producción diaria de la línea de plátano, leyendo **SQL Server** (BD `ReportingData` de Hispatec).
Contenedor `displafruit-dashboard` en `docker-compose.override.yml`, puerto **8090**
(http://localhost:8090). Arranca con `docker compose up -d`.

- Credenciales y config en **`.env`** de la raíz (gitignored, en disco): `MSSQL_HOST=192.168.250.248`,
  `MSSQL_DATABASE=ReportingData`, `MSSQL_USER=general` (solo lectura), `DASHBOARD_DAY_OFFSET=-1`.
- Estructura del panel (según mockup del usuario): banda **KG VOLCADO | KG CONFECCIONADO
  (Mercadona/Consum/Otros) | KG DESTRÍO (dedos/manojo/maduro/tirado)**, fila de **productividad
  por operario** (media día / última hora / 30' / 10' vs objetivo 150) y **gráfico de líneas** SVG.

## Estado actual
- ✅ Panel rediseñado y funcionando con datos reales (commits `77861429c` … `3554f6819`).
- ⚙️ **Modo validación**: `DASHBOARD_DAY_OFFSET=-1` (muestra AYER, para contrastar con PowerBI, que
  cierra el día anterior). Poner a `0` para "hoy" cuando se valide.
- Ejemplo verificado (07/07): volcado 28.941, confeccionado 25.645 (Merca 17.193/67%, Consum
  7.911/31%, Otros 541/2%), destrío 3.271, productividad por operario 127/119/152/235 kg/h (÷35).

## 🔑 Reglas de negocio descubiertas (NO re-derivar, costó mucho)
Tabla `dbo.ProduccionLineal` (1 fila = una **partida de origen** de un palé):
1. **KG = SUM(CantidadOrigen)**, NO la columna `Cantidad`. `Cantidad` es el total del palé
   denormalizado y **a veces está mal** (11 palés descuadrados en 30 días). El peso del palé se
   obtiene sumando `CantidadOrigen` de sus partidas. Ej.: palé `PT2600017156` = 54+108+90 = 252 kg.
   Se deduplica por `DISTINCT (Pale, PartidaOrigen)` (la tabla repite filas por pedido/albarán).
2. **PRODUCCIÓN = solo `Tipo='Fabricado'`** (lo que produce DisplaFruit). `Tipo='Recepcionado'` es
   producto **recibido ya confeccionado** (p.ej. el PREMIUM) y **NO cuenta** (ese era el gran error:
   inflaba de ~30k a ~51k). Además `NombreFamilia='PLATANO DE CANARIAS IGP'` ("solo plátano").
3. **CONFECCIONADO** = `NombreProducto NOT LIKE '%DESTRIO%'`; se reparte por `NombreEnvase` → cliente:
   `CAJA LOGIFRUIT CODIGO 624`=**Mercadona**, `CAJA PLATANO PLASTICO CONSUM E156`=**Consum**, resto=Otros.
4. **DESTRÍO** = `NombreProducto LIKE '%DESTRIO%'` (dedos/manojo/maduro/tirado).
5. **Cajas** = `MAX(NroEnvases)` por palé (atributo del palé; `CajasOrigen` es fraccional, NO son cajas).
6. **Hora de fin de palé** = `CLI501_FechaHoraFinPalet` → base del KG/h y del gráfico horario.
7. `GETDATE()` del servidor = **hora local Europe/Madrid** → `CAST(... AS date)=CAST(GETDATE() AS date)`
   acota "hoy" correctamente.

Otras fuentes (verificadas, NO duplican):
- `dbo.MercanciaVolcada`: kg volcados (`PesoNetoVolcado`), cada fila un evento real. Al día, a minuto.
- `dbo.InformePresencia`: fichajes (entrada/salida/tiempo), **al día**. Centro `Central`=producción.
- Tablas OBSOLETAS (terminan en 2023, NO usar): `InformePedidosVentaUL`.

## ✅ nº de operarios de línea (para KG/h por operario) — IMPLEMENTADO (2026-07-08)
El objetivo 150 kg/h es **por operario**; se divide la productividad de línea entre los operarios de
confección. La BD NO tiene la asignación a línea, así que la cifra la lleva RRHH en un Excel de SharePoint.

**Decisiones cerradas con el usuario (2026-07-08):**
- **Fuente = Excel** (no la regla SQL) con **respaldo**.
- **Columna = `ASIS PROD`** (índice **2**), NO `PERSONAS PROD` ni `+ETT`. (`PERSONAS PROD` cuenta cabezas;
  hay categorías separadas ETT/ALM/MANT — la línea propia es `ASIS PROD`.) Verificado: 15/06→**27**
  (= el real que dio el usuario), 06/07→**28**. Cuadra.

**Implementación** (`custom/DisplaFruit/dashboard/`):
- `operarios.js` (NUEVO): lee el `.xlsx` (SheetJS), hoja `H TRABAJO DIARIO`, columna `ASIS PROD`. Cache
  por mtime. **Respaldo**: si el día pedido está a 0/vacío → **último día anterior con dato** (RRHH
  rellena con 1-2 días de retraso). Devuelve `{n, fuente:'excel'|'excel-previo', fecha}` o `null`.
- `server.js`: `resolverOperarios(sqlOper)` con prioridad **1) override manual `.env DASHBOARD_OPERARIOS`
  → 2) Excel → 3) conteo SQL `InformePresencia` → 4) sin dato**. Expone `operariosFuente`/`operariosFecha`
  en el JSON.
- `index.html`: muestra el nº y, entre paréntesis, la procedencia si no es el día exacto (`Excel 06/07`,
  `estimado`, `manual`).
- `package.json`: +`xlsx`. `docker-compose.override.yml`: monta el DIRECTORIO del Excel (env
  `OPERARIOS_XLSX_DIR`, def. `custom/DisplaFruit/dashboard/data`) en `/data/operarios` y pasa
  `OPERARIOS_XLSX_PATH/SHEET/COL` + `DASHBOARD_OPERARIOS`.
- **Probado** (Node host, contra la copia real del Excel): 6/6 tests OK (día con dato, respaldo a día
  previo, domingo→previo, sin fichero→null). Falta probar **con Docker arriba en modo SQL** (Docker
  estaba caído esta sesión).

### ⏳ Lo único que queda: alimentar el Excel automáticamente
El fichero **NO está sincronizado** localmente (la biblioteca SharePoint `DATOS BI` no aparece bajo
`OneDrive - Displafruit S.A`). Hay una **copia manual** en `data/HORAS TRABAJO ALM PLATANO.xlsx`
(gitignored) para que funcione ya. Para que sea **automático** (elegir vía con el usuario):
- **A (recomendada, sin Azure):** pulsar **"Sincronizar"** en la carpeta `DATOS BI` de SharePoint →
  carpeta local auto-actualizada → poner `OPERARIOS_XLSX_DIR=<esa carpeta>` en `.env` y `docker compose
  up -d displafruit-dashboard`. Sin más código.
- **B:** Microsoft Graph (registro de app Azure AD, `Files.Read.All`) → requiere IT; automático total.
- Override manual `DASHBOARD_OPERARIOS=NN` en `.env` por si un día falla (ver `data/README.md`).

## Otros pendientes / cosméticos (a confirmar con el usuario)
- ¿Incluir **Tirado** en el destrío? (el mockup solo tenía dedos/manojo/maduro; ahora se muestra Tirado).
- ¿Mantener **"Otros"** en confeccionado (cajas 16kg/GOLD) o solo Mercadona/Consum?
- Nombres **Mercadona/Consum** puestos (el usuario dijo que quizá cambiarlos por el código de caja).
- Pasar `DASHBOARD_DAY_OFFSET` a **0** (hoy) cuando la validación esté cerrada.
- Cómo mostrar la TV en pantalla (kiosko a `http://IP-SERVIDOR:8090`) y abrir puerto 8090 a la subred.
- **Push**: hay ~19 commits locales en `displafruit/main` sin subir a origin.

## Cómo probar rápido
```powershell
docker compose up -d --build displafruit-dashboard
curl http://localhost:8090/api/data      # JSON
# Captura (puppeteer): docker run --rm --network host --add-host=host.docker.internal:host-gateway ghcr.io/puppeteer/puppeteer ...
```
Exploración SQL ad-hoc: `docker compose exec -T displafruit-dashboard node -` + script con `require('mssql')`
(el driver ya está en el contenedor; credenciales del `.env`).
