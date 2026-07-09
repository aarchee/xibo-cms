# DisplaFruit — Dashboard de producción: estado y pendientes

> Documento de continuidad (2026-07-08). Resume qué está hecho, el **conocimiento de negocio**
> descubierto (lo más valioso), las consultas clave y las **decisiones pendientes**. Para retomar:
> "continúa con el dashboard de producción de DisplaFruit".

## 🔴 CAMBIO DE BASE DE DATOS EN CURSO (2026-07-09) — LEER PRIMERO
ReportingData (Hispatec) **va con retraso** (hoy suele estar a 0). Se va a cambiar la conexión a la
BD de **control de línea (MES)**, que está **viva al minuto**:
- **Servidor:** `srv-produccion\sqlexpress` = **192.168.250.237** (instancia con nombre `sqlexpress`;
  conecta desde Docker vía SQL Browser con `options.instanceName:'sqlexpress'`). Usuario **solo-lectura**
  `usrexterno` (contraseña en el `.env`, gitignored). BD: **`DisplaFruit`** (única BD de usuario del servidor).
- **Esquema (8 tablas, nombres con puntos → citar con corchetes `[dbo].[Produccion.Volcados]`):**
  - `[Cliente.Confecciones]` (7): catálogo. **idConfeccion 2=MERCADONA, 1=CONSUM**, 4=Cartón16, 5=Cartón10,
    6=Cartón9, 3=Cartón Doniz17, 7=Banana. (Merca/Consum tienen `envase`/`palet`/`marca`; los cartones no.)
  - `[Produccion.CajasConfeccionadas.Info]` (1,29M): **`pesoNeto` por caja + `idConfeccion` + `fechaHoraInspeccion`**
    + `codPaleERP`. ← de aquí sale el CONFECCIONADO (SUM(pesoNeto) por idConfeccion y día).
  - `[Produccion.CajasConfeccionadas]` (1,85M): caja→pale/volcado/partida/mesa + `fechaHora`.
  - `[Produccion.PalesConfeccionados]` (49k): palés; **`peso` SIEMPRE 0 (no se usa)**; `fechaHoraFin`, salida, SSCC.
  - `[Produccion.Volcados]` (10,7k): eventos de volcado (~25-41/día); **SIN columna de peso**; `idPale=0`.
  - `[Produccion.Encajado.Pesos]` (6,2M): pesajes del check-weigher (peso/nominal/regalado, idVolcado1/2/3).
  - `[Produccion.Partidas]` (2784) y `[Produccion.CajasConfeccionadas.Partidas]` (4,2M): trazas de partida.
- **Frescura verificada:** MAX(fechaHora) de todas las tablas = ahora mismo (al minuto). Confeccionado de
  HOY se ve subir en vivo. ReportingData hoy = 0.

### ⚠️ Lo que la BD nueva NO tiene (PENDIENTE — el usuario lo investiga en planta)
- **VOLCADO (kg):** `Volcados` no guarda peso. No hay kg de volcado en esta BD.
- **DESTRÍO:** no existe ningún idConfeccion de destrío (en 20 días solo salen 1,2,4,5). No está aquí.
- → **Decisión del usuario (2026-07-09): "lo investigo con producción"** (dónde se registran volcado y
  destrío: ¿otra BD/servidor/scada de recepción?). Retomar cuando dé la fuente. Opciones si no aparece:
  híbrido (volcado/destrío de ReportingData + confeccionado/productividad de la nueva) o rehacer el panel
  sin esos dos KPIs.

### Confeccionado: nueva vs ReportingData (usuario: "ver ambos y decidir luego")
Comparado día a día (últimos 10 días): **Mercadona y Consum casi coinciden (±5%)** entre ambas BD;
**toda la divergencia es "Otros"=cartón genérico**, que ReportingData excluía por su filtro de familia
"PLATANO DE CANARIAS IGP" (a menudo 0) y la nueva sí cuenta (200-6000 kg/día, producción real). Solo
Merca+Consum: total nuevo vs ReportingData dentro de **±4%**. → Decisión abierta: **¿mostrar "Otros"
(cartón) o no?**. Ejemplo 07/07: nueva 18389/7811/2872=29072 vs Reporting 17418/8055/541=26014.
- Consulta de confeccionado nuevo: `SELECT idConfeccion, SUM(pesoNeto) FROM [dbo].[Produccion.CajasConfeccionadas.Info] WHERE CAST(fechaHoraInspeccion AS date)=@dia GROUP BY idConfeccion` (mapear 2→Merca, 1→Consum, resto→Otros).
- **NO se ha cambiado aún la conexión del dashboard** (sigue en ReportingData). Cambiar sólo cuando se
  resuelva volcado/destrío.

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
- ✅ Panel rediseñado y funcionando con datos reales (commits `77861429c` … `e49a28f06`).
- ✅ **Operarios desde Excel VERIFICADO EN VIVO (modo SQL, 2026-07-08)**: para el 07/07 (sin dato en
  Excel) cayó al respaldo 06/07 = **28** (`ASIS PROD`) → productividad media **161** kg/h (÷28). El
  panel muestra "28 operarios en línea (Excel 2026-07-06)". Captura OK.
- ✅ **Decisiones cosméticas cerradas (2026-07-08, el usuario mantiene lo que ya se mostraba)**:
  mantener **Tirado** en destrío, mantener **Otros** en confeccionado, y **nombres** Mercadona/Consum
  (no código de caja). → Sin cambios de código.
- ⚙️ **Modo validación**: `DASHBOARD_DAY_OFFSET=-1` (muestra AYER, para contrastar con PowerBI, que
  cierra el día anterior). Poner a `0` para "hoy" cuando se valide.
- Ejemplo en vivo (07/07, 08/07 10:48): volcado 28.991, confeccionado 25.870 (Merca 17.418/67%,
  Consum 7.911/31%, Otros 541/2%), destrío 3.917, productividad por operario 161/149/190/293 kg/h (÷28).
- Nota tipográfica: `toLocaleString('es-ES')` NO agrupa números de 4 cifras (3917, 7911) — es la norma
  RAE, no un bug; se dejó así.

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

## Pendientes que quedan
- **Validación de cifras contra PowerBI** (acción del usuario): contrastar volcado/confeccionado/
  destrío/KG-h del día -1. Si algo no cuadra, depurar.
- **Fase 2 — automatizar el Excel** (acción del usuario en SharePoint): sincronizar `DATOS BI`,
  poner `OPERARIOS_XLSX_DIR=<carpeta>` en `.env`. Mientras, funciona con la copia manual en `data/`.
- **Fase 3 — producción en TV**: pasar `DASHBOARD_DAY_OFFSET` a **0** (hoy) al cerrar validación;
  kiosko a `http://IP-SERVIDOR:8090`; abrir puerto 8090 a la subred; auto-arranque en la TV.
- ✅ Push al día (origin `displafruit/main`, hasta `e49a28f06`).

## Cómo probar rápido
```powershell
docker compose up -d --build displafruit-dashboard   # OBLIGATORIO --build tras tocar el código Node
curl http://localhost:8090/api/data      # JSON
# Captura (puppeteer, red del compose): docker run --rm --network xibo-cms_default \
#   -v "$(pwd -W)/scratch_shot:/shot" ghcr.io/puppeteer/puppeteer node -e "...goto http://xibo-cms-displafruit-dashboard-1:8080..."
```
Exploración SQL ad-hoc: `docker compose exec -T displafruit-dashboard node -` + script con `require('mssql')`
(el driver ya está en el contenedor; credenciales del `.env`).
