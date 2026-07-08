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

## ⏳ DECISIÓN PENDIENTE PRINCIPAL: nº de operarios de línea (para KG/h por operario)
El objetivo 150 kg/h es **por operario**. Hay que dividir la productividad de línea entre los
operarios de la línea de confección. Problema: **la BD NO tiene la asignación a línea** (los
trabajadores de `Central` son indistinguibles por campo: `UsoEnProduccion=1`, `Categoria=0`,
`ActividadDefecto` vacío para todos). Opciones evaluadas:
- Contar `Central` presentes = 35 (INFLA: incluye encargados/almacén de jornada larga).
- Regla estructural "salida ≈ fin de línea (≤13:00)" = **27** ✅ (coincide con el real que dio el
  usuario: 26 de línea + 1 fichaje suelto). Auto-ajustable, pero sigue siendo heurística.
- **ELEGIDO por el usuario: una fuente diaria en Excel de SharePoint.**

### La fuente: Excel de SharePoint (RRHH, manual)
- URL: `https://displafruit.sharepoint.com/sites/Oficina-DisplaFruit/Documentos%20compartidos/DATOS%20BI/HORAS%20TRABAJO%20ALM%20PLATANO.xlsx`
- Hoja **"H TRABAJO DIARIO"**. Columnas (fila 0 = cabecera; datos desde fila 1):
  `Fecha | H PROD DISPLA | ASIS PROD | PERSONAS PROD | H ETT | ... | PERSONAS ALM | ...`
  → **PERSONAS PROD** = columna índice **3**; `ASIS PROD` = índice 2; `Fecha` = índice 0 (fecha Excel).
- Valores recientes leídos (copia en `Downloads/HORAS TRABAJO ALM PLATANO.xlsx`, del 08/07 09:29):
  01/07→PERSONAS PROD 35 (ASIS 29); 02/07→35(30); 03/07→35(30); 04/07→20(15); 06/07→**32**(ASIS **28**);
  **07/07→0 (aún sin rellenar)**.
- ⚠️ **Se rellena con RETRASO** (RRHH, manual): el día en curso y a veces el anterior están a 0 hasta
  que lo teclean. → Para "hoy" en vivo NO habrá dato; hay que decidir respaldo (regla estructural o
  último día).
- ⚠️ **A confirmar con el usuario**: ¿columna **PERSONAS PROD** (32 el 06/07) o **ASIS PROD** (28,
  más cerca de su "27")? El usuario dijo PERSONAS PROD pero los números no cuadran del todo.

### Cómo leerlo automáticamente (pendiente de montar)
El fichero **NO está sincronizado** localmente (solo copia manual en `Downloads`). La biblioteca
SharePoint `DATOS BI` no aparece bajo `C:\Users\discen08\OneDrive - Displafruit S.A`. Vías:
- **A (recomendada, sin Azure):** el usuario pulsa **"Sincronizar"** en la carpeta `DATOS BI` de
  SharePoint → carpeta local auto-actualizada → montarla en el contenedor y leer el `.xlsx` por fecha
  (parser con SheetJS `xlsx`; ya probado: `docker run node:22-alpine` + `npm i xlsx`).
- **B:** Microsoft Graph (registro de app en Azure AD: tenant/client id/secret, `Files.Read.All`) →
  leer la celda por fecha. Automático total pero requiere IT.
- Dejar override manual del número por si un día falla.

Implementación actual de operarios (a sustituir por el Excel): `server.js` → `Q.operarios` cuenta
`COUNT(DISTINCT Codigo)` de `InformePresencia` para el día, centro `Central` (=35). La productividad
y la serie del gráfico se dividen entre ese número en `calcularProductividad()`.

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
