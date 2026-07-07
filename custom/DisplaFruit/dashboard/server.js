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
const TITLE = process.env.DASHBOARD_TITLE || 'Producción diaria';

const MSSQL_HOST = (process.env.MSSQL_HOST || '').trim();
const IS_MOCK = MSSQL_HOST === '';

// ---------------------------------------------------------------------------
// Consultas (ADAPTAR al esquema real cuando se tengan las credenciales).
// ---------------------------------------------------------------------------

// KPIs: debe devolver UNA fila; cada columna es un KPI. Alias = etiqueta mostrada.
const KPI_QUERY = process.env.DASHBOARD_KPI_QUERY || `
    SELECT
        0 AS [Kg producidos hoy],
        0 AS [Cajas confeccionadas],
        0 AS [Pedidos servidos],
        0 AS [Líneas activas]
`;

// Líneas/detalle: filas para la tabla. Columnas: linea, producto, kg, estado.
const LINES_QUERY = process.env.DASHBOARD_LINES_QUERY || `
    SELECT TOP 8
        '' AS linea, '' AS producto, 0 AS kg, '' AS estado
    WHERE 1 = 0
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

async function fetchFromSql() {
    const pool = await getPool();
    const [kpiResult, linesResult] = await Promise.all([
        pool.request().query(KPI_QUERY),
        pool.request().query(LINES_QUERY),
    ]);

    const kpiRow = kpiResult.recordset[0] || {};
    const kpis = Object.keys(kpiRow).map((label) => ({
        label: label,
        value: kpiRow[label],
    }));

    return { kpis: kpis, lines: linesResult.recordset || [] };
}

// --- Datos DEMO: varían un poco en cada lectura para que "se vea vivo" en la TV ---
const mockState = { kg: 18240, cajas: 1520, pedidos: 46 };

function fetchMock() {
    mockState.kg += Math.floor(Math.random() * 180);
    if (Math.random() > 0.4) mockState.cajas += Math.floor(Math.random() * 14);
    if (Math.random() > 0.8) mockState.pedidos += 1;

    const estados = ['En marcha', 'En marcha', 'En marcha', 'Parada'];
    const productos = ['Plátano IGP 1ª', 'Plátano IGP 2ª', 'Plátano bolsa 1kg', 'Plátano granel'];

    return {
        kpis: [
            { label: 'Kg producidos hoy', value: mockState.kg },
            { label: 'Cajas confeccionadas', value: mockState.cajas },
            { label: 'Pedidos servidos', value: mockState.pedidos },
            { label: 'Líneas activas', value: 3 },
        ],
        lines: [1, 2, 3, 4].map(function (n, i) {
            return {
                linea: 'Línea ' + n,
                producto: productos[i % productos.length],
                kg: Math.floor(mockState.kg / 4 + Math.random() * 500),
                estado: estados[i % estados.length],
            };
        }),
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
            kpis: data.kpis,
            lines: data.lines,
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
