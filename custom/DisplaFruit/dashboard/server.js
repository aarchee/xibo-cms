/*
 * DisplaFruit — dashboard de producción para pantallas.
 *
 * Servicio autocontenido (Node, sin framework):
 *   GET /            -> la página del dashboard (public/index.html)
 *   GET /api/data    -> JSON con los KPIs y las líneas (SQL Server o datos demo)
 *   GET /healthz     -> 200 si el proceso vive
 *
 * Modos:
 *   - DEMO (por defecto): si no hay MSSQL_HOST configurado, sirve datos de ejemplo que
 *     varían ligeramente en cada refresco, para validar el concepto en la TV.
 *   - SQL: con MSSQL_HOST/DATABASE/USER/PASSWORD definidos, ejecuta las consultas de
 *     abajo (KPI_QUERY / LINES_QUERY) contra SQL Server.
 *
 * Las consultas son el ÚNICO punto a adaptar cuando se conozca el esquema real:
 * deben devolver las columnas descritas en cada bloque. También pueden inyectarse por
 * entorno (DASHBOARD_KPI_QUERY / DASHBOARD_LINES_QUERY) sin reconstruir la imagen.
 *
 * Robustez: si una consulta falla, se sirve el último dato bueno marcado como "stale"
 * (la pantalla nunca se queda en blanco por un microcorte de red/BD).
 */

'use strict';

const http = require('http');
const fs = require('fs');
const path = require('path');

const PORT = parseInt(process.env.PORT || '8080', 10);
const REFRESH_SECONDS = Math.max(5, parseInt(process.env.REFRESH_SECONDS || '20', 10));

const MSSQL_HOST = (process.env.MSSQL_HOST || '').trim();
const IS_MOCK = MSSQL_HOST === '';

// Día de referencia: 0 = hoy, -1 = ayer, -2 = anteayer... Util para VALIDAR contra
// PowerBI (que cierra el dia anterior). Se cambia con la variable DASHBOARD_DAY_OFFSET.
const DAY_OFFSET = parseInt(process.env.DASHBOARD_DAY_OFFSET || '0', 10);
// Expresion SQL de la fecha objetivo (en hora local del servidor, Europe/Madrid).
const DAY_SQL = `CAST(DATEADD(DAY, ${DAY_OFFSET}, GETDATE()) AS date)`;
// Palabra para las etiquetas de los KPIs.
const DAY_WORD = DAY_OFFSET === 0 ? 'hoy' : (DAY_OFFSET === -1 ? 'ayer' : ('día ' + DAY_OFFSET));
const TITLE = process.env.DASHBOARD_TITLE
    || ('Producción ' + (DAY_OFFSET === 0 ? 'diaria' : DAY_WORD + ' (validación)'));

// ---------------------------------------------------------------------------
// Consultas contra SQL Server (BD ReportingData de Hispatec).
//
// Ambas pueden sobreescribirse por entorno (DASHBOARD_KPI_QUERY /
// DASHBOARD_LINES_QUERY) sin reconstruir la imagen. Las de aquí son el diseño
// validado el 2026-07-07 contra el esquema real (ver README, "Origen de datos").
//
// Notas de esquema:
//   - dbo.ProduccionLineal : produccion confeccionada. NroEnvases = cajas del pale,
//     FechaFabricacion = dia de fabricacion. Datos al dia. GRANO: 1 fila = una PARTIDA
//     de origen (PartidaOrigen) que entra en un pale; un pale se compone de varias partidas.
//     *** KG: el peso del pale se obtiene SUMANDO CantidadOrigen de sus partidas (regla de
//     negocio confirmada por el usuario 2026-07-07). Ej.: pale PT2600017156 = 54+108+90 =
//     252 kg. La columna Cantidad es un total denormalizado del pale que A VECES NO CUADRA
//     con la suma real (11 pales descuadrados en 30 dias) -> NO usar Cantidad, usar
//     CantidadOrigen. Ademas la tabla puede repetir filas por linea de pedido/albaran, asi
//     que se suma CantidadOrigen sobre DISTINCT (Pale, PartidaOrigen) para no duplicar.
//     Las cajas del pale (NroEnvases) se repiten en cada fila -> MAX(NroEnvases) por pale.
//   - dbo.MercanciaVolcada  : materia prima volcada en linea (tiempo real, al minuto).
//     PesoNetoVolcado = kg. Cada fila es un evento de volcado real -> es aditiva (no duplica).
//   - dbo.ExistenciasMercancia : stock actual en camara (snapshot). 1 fila = 1 UL (no duplica).
//     Palets = nº de palets (columna decimal).
//   - GETDATE() devuelve la hora LOCAL del servidor SQL (Europe/Madrid), asi que
//     "CAST(... AS date) = CAST(GETDATE() AS date)" filtra correctamente "hoy".
// ---------------------------------------------------------------------------

// KPIs: UNA fila; cada columna es una tarjeta y su alias es la etiqueta mostrada.
// Kg = SUM(CantidadOrigen) por partida distinta del pale; cajas = NroEnvases por pale.
// El dia de referencia (${DAY_WORD}) lo fija DASHBOARD_DAY_OFFSET.
const KPI_QUERY = process.env.DASHBOARD_KPI_QUERY || `
    SELECT
        (SELECT CAST(ISNULL(SUM(co),0) AS int) FROM (
            SELECT DISTINCT Pale, PartidaOrigen, CantidadOrigen co
              FROM dbo.ProduccionLineal
             WHERE CAST(FechaFabricacion AS date) = ${DAY_SQL}) t) AS [Kg producidos ${DAY_WORD}],
        (SELECT CAST(ISNULL(SUM(env),0) AS int) FROM (
            SELECT Pale, MAX(NroEnvases) env FROM dbo.ProduccionLineal
             WHERE CAST(FechaFabricacion AS date) = ${DAY_SQL}
             GROUP BY Pale) t) AS [Cajas ${DAY_WORD}],
        (SELECT CAST(ISNULL(SUM(PesoNetoVolcado),0) AS int)
           FROM dbo.MercanciaVolcada
          WHERE CAST(Fecha AS date) = ${DAY_SQL}) AS [Kg volcados ${DAY_WORD}],
        (SELECT CAST(ISNULL(SUM(Palets),0) AS int)
           FROM dbo.ExistenciasMercancia) AS [Palets en cámara]
`;

// Detalle por producto: kg = SUM(CantidadOrigen) por partida distinta; cajas = NroEnvases
// por pale. Se juntan por producto. Columnas dinamicas (el alias = cabecera).
const LINES_QUERY = process.env.DASHBOARD_LINES_QUERY || `
    SELECT kg.NombreProducto AS Producto,
           CAST(kg.kg AS int) AS Kg,
           CAST(ISNULL(ca.cajas,0) AS int) AS Cajas
    FROM (
        SELECT NombreProducto, SUM(co) kg FROM (
            SELECT DISTINCT Pale, PartidaOrigen, NombreProducto, CantidadOrigen co
              FROM dbo.ProduccionLineal
             WHERE CAST(FechaFabricacion AS date) = ${DAY_SQL}) d
        GROUP BY NombreProducto
    ) kg
    LEFT JOIN (
        SELECT NombreProducto, SUM(env) cajas FROM (
            SELECT Pale, NombreProducto, MAX(NroEnvases) env
              FROM dbo.ProduccionLineal
             WHERE CAST(FechaFabricacion AS date) = ${DAY_SQL}
             GROUP BY Pale, NombreProducto) d
        GROUP BY NombreProducto
    ) ca ON kg.NombreProducto = ca.NombreProducto
    ORDER BY kg.kg DESC
`;

// ---------------------------------------------------------------------------
// Acceso a datos
// ---------------------------------------------------------------------------

let sql = null;
let poolPromise = null;

function getPool() {
    if (!sql) {
        sql = require('mssql'); // solo se carga en modo SQL
    }
    if (!poolPromise) {
        poolPromise = new sql.ConnectionPool({
            server: MSSQL_HOST,
            port: parseInt(process.env.MSSQL_PORT || '1433', 10),
            database: process.env.MSSQL_DATABASE || '',
            user: process.env.MSSQL_USER || '',
            password: process.env.MSSQL_PASSWORD || '',
            connectionTimeout: 8000,
            requestTimeout: 8000,
            pool: { max: 2, min: 0, idleTimeoutMillis: 30000 },
            options: {
                encrypt: (process.env.MSSQL_ENCRYPT || 'false') === 'true',
                trustServerCertificate: (process.env.MSSQL_TRUST_CERT || 'true') === 'true',
                instanceName: process.env.MSSQL_INSTANCE || undefined,
            },
        }).connect();
        // Si la conexión inicial falla, permitir reintento en la siguiente petición.
        poolPromise.catch(() => { poolPromise = null; });
    }
    return poolPromise;
}

// Deriva la descripcion de columnas de la tabla a partir de las filas devueltas:
// el nombre/alias de columna es la cabecera; se alinea a la derecha si es numerica;
// una columna llamada "estado" se pinta como badge verde/rojo.
function deriveColumns(rows) {
    if (!rows.length) { return []; }
    return Object.keys(rows[0]).map((key) => {
        const sample = rows.find((r) => r[key] !== null && r[key] !== undefined) || {};
        return {
            key: key,
            label: key,
            num: typeof sample[key] === 'number',
            estado: key.toLowerCase() === 'estado',
        };
    });
}

async function fetchFromSql() {
    const pool = await getPool();
    const [kpiResult, linesResult, dateResult] = await Promise.all([
        pool.request().query(KPI_QUERY),
        pool.request().query(LINES_QUERY),
        // Fecha de referencia (dd/mm/aaaa) segun la hora local del servidor SQL.
        pool.request().query(`SELECT CONVERT(varchar(10), ${DAY_SQL}, 103) AS d`),
    ]);

    const kpiRow = kpiResult.recordset[0] || {};
    const kpis = Object.keys(kpiRow).map((label) => ({
        label: label,
        value: kpiRow[label],
    }));

    const lines = linesResult.recordset || [];
    const refDate = (dateResult.recordset[0] || {}).d || null;
    return { kpis: kpis, lines: lines, columns: deriveColumns(lines), refDate: refDate };
}

// --- Datos DEMO: mismo esquema que produccion, varian un poco en cada lectura ---
const mockState = { kg: 19000, cajas: 1280, volcado: 20000 };

function fetchMock() {
    mockState.kg += Math.floor(Math.random() * 160);
    if (Math.random() > 0.4) mockState.cajas += Math.floor(Math.random() * 10);
    if (Math.random() > 0.6) mockState.volcado += Math.floor(Math.random() * 120);

    const productos = [
        'PLATANO IGP GRANEL CONFECCIONADO', 'PLATANO IGP PREMIUM',
        'PLATANO IGP PREMIUM MJ', 'PLATANO IGP EXTRA A', 'PLATANO IGP EXTRA A MJ',
    ];
    // Reparto ficticio de la produccion del dia entre productos (decreciente).
    const pesos = [0.62, 0.14, 0.10, 0.09, 0.05];
    const lines = productos.map(function (p, i) {
        return {
            Producto: p,
            Kg: Math.floor(mockState.kg * pesos[i]),
            Cajas: Math.floor(mockState.cajas * pesos[i]),
        };
    });

    // Fecha de referencia demo (dd/mm/aaaa) segun DAY_OFFSET.
    const ref = new Date();
    ref.setDate(ref.getDate() + DAY_OFFSET);
    const refDate = ('0' + ref.getDate()).slice(-2) + '/' +
        ('0' + (ref.getMonth() + 1)).slice(-2) + '/' + ref.getFullYear();

    return {
        kpis: [
            { label: 'Kg producidos ' + DAY_WORD, value: mockState.kg },
            { label: 'Cajas ' + DAY_WORD, value: mockState.cajas },
            { label: 'Kg volcados ' + DAY_WORD, value: mockState.volcado },
            { label: 'Palets en cámara', value: 259 },
        ],
        lines: lines,
        columns: deriveColumns(lines),
        refDate: refDate,
    };
}

// ---------------------------------------------------------------------------
// Servidor HTTP
// ---------------------------------------------------------------------------

let lastGood = null;

async function handleData(res) {
    let payload;
    try {
        const data = IS_MOCK ? fetchMock() : await fetchFromSql();
        lastGood = {
            ok: true,
            mock: IS_MOCK,
            stale: false,
            title: TITLE,
            refreshSeconds: REFRESH_SECONDS,
            updatedAt: new Date().toISOString(),
            refDate: data.refDate || null,
            kpis: data.kpis,
            lines: data.lines,
            columns: data.columns || [],
        };
        payload = lastGood;
    } catch (err) {
        console.error('[dashboard] error consultando SQL: ' + err.message);
        payload = lastGood
            ? Object.assign({}, lastGood, { stale: true })
            : {
                ok: false,
                mock: IS_MOCK,
                stale: true,
                title: TITLE,
                refreshSeconds: REFRESH_SECONDS,
                updatedAt: null,
                kpis: [],
                lines: [],
                columns: [],
                error: 'Sin conexión con la base de datos',
            };
    }
    res.writeHead(200, {
        'Content-Type': 'application/json; charset=utf-8',
        'Cache-Control': 'no-store',
    });
    res.end(JSON.stringify(payload));
}

const indexHtml = fs.readFileSync(path.join(__dirname, 'public', 'index.html'));

const server = http.createServer(function (req, res) {
    const url = (req.url || '/').split('?')[0];

    if (url === '/api/data') {
        handleData(res);
        return;
    }
    if (url === '/healthz') {
        res.writeHead(200, { 'Content-Type': 'text/plain' });
        res.end('ok');
        return;
    }
    // Cualquier otra ruta -> la página del dashboard.
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
    res.end(indexHtml);
});

server.listen(PORT, function () {
    console.log('[dashboard] escuchando en :' + PORT
        + (IS_MOCK ? ' (MODO DEMO, sin MSSQL_HOST)' : ' (SQL Server: ' + MSSQL_HOST + ')'));
});
