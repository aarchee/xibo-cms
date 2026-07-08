/*
 * DisplaFruit — dashboard de producción para pantallas.
 *
 * Servicio autocontenido (Node, sin framework):
 *   GET /            -> la página del dashboard (public/index.html)
 *   GET /api/data    -> JSON con los datos de produccion (SQL Server o datos demo)
 *   GET /healthz     -> 200 si el proceso vive
 *
 * Estructura del panel (ver public/index.html):
 *   - Banda: KG VOLCADO | KG CONFECCIONADO (Mercadona/Consum) | KG DESTRIO (dedos/manojo/maduro)
 *   - Productividad: KG/h en MEDIA DIA / ultima hora / ultimos 30' / ultimos 10', vs objetivo.
 *   - Grafico de lineas: confeccionado por hora (productividad de la linea).
 *
 * Reglas de negocio (BD ReportingData de Hispatec), confirmadas 2026-07-07/08:
 *   - dbo.ProduccionLineal: 1 fila = una PARTIDA de origen de un pale. El PESO del pale se
 *     obtiene sumando CantidadOrigen de sus partidas (NO la columna Cantidad, denormalizada
 *     y a veces mal). Se deduplica por DISTINCT (Pale, PartidaOrigen) por si hay filas
 *     repetidas por pedido/albaran.
 *   - PRODUCCION = solo Tipo='Fabricado' (lo producido por DisplaFruit; 'Recepcionado' es
 *     producto recibido ya confeccionado, NO cuenta) y familia 'PLATANO DE CANARIAS IGP'.
 *   - CONFECCIONADO = producto bueno (NombreProducto NO contiene 'DESTRIO'); se reparte por
 *     NombreEnvase -> cliente (Mercadona = CAJA LOGIFRUIT CODIGO 624; Consum = CAJA PLATANO
 *     PLASTICO CONSUM E156; el resto -> Otros).
 *   - DESTRIO = NombreProducto contiene 'DESTRIO' (dedos/manojo/maduro/tirado).
 *   - dbo.MercanciaVolcada: materia prima volcada (kg = PesoNetoVolcado). Aditiva.
 *   - CLI501_FechaHoraFinPalet: instante de fin del pale -> KG/h y grafico horario.
 *   - GETDATE() devuelve hora local del servidor (Europe/Madrid).
 *
 * Dia de referencia: DASHBOARD_DAY_OFFSET (0=hoy, -1=ayer). En modo pasado, las ventanas
 * "ultimos X min" se calculan respecto al ultimo pale fabricado ese dia (no la hora actual).
 *
 * Robustez: si una consulta falla, se sirve el ultimo dato bueno marcado "stale".
 */

'use strict';

const http = require('http');
const fs = require('fs');
const path = require('path');

const PORT = parseInt(process.env.PORT || '8080', 10);
const REFRESH_SECONDS = Math.max(5, parseInt(process.env.REFRESH_SECONDS || '20', 10));
const OBJETIVO = parseInt(process.env.DASHBOARD_OBJETIVO_KGH || '150', 10);

const MSSQL_HOST = (process.env.MSSQL_HOST || '').trim();
const IS_MOCK = MSSQL_HOST === '';

// Dia de referencia: 0 = hoy, -1 = ayer... (validacion contra PowerBI).
const DAY_OFFSET = parseInt(process.env.DASHBOARD_DAY_OFFSET || '0', 10);
const DAY_SQL = `CAST(DATEADD(DAY, ${DAY_OFFSET}, GETDATE()) AS date)`;
const DAY_WORD = DAY_OFFSET === 0 ? 'hoy' : (DAY_OFFSET === -1 ? 'ayer' : ('día ' + DAY_OFFSET));
const TITLE = process.env.DASHBOARD_TITLE
    || ('Producción ' + (DAY_OFFSET === 0 ? 'diaria' : DAY_WORD + ' (validación)'));

// Mapa envase -> cliente (sin nombres de empresa en pantalla si se prefiere; de momento si).
const ENVASE_CLIENTE = {
    'CAJA LOGIFRUIT CODIGO 624': 'Mercadona',
    'CAJA PLATANO PLASTICO CONSUM E156': 'Consum',
};
function clienteDeEnvase(envase) {
    return ENVASE_CLIENTE[(envase || '').trim()] || 'Otros';
}
function nombreDestrio(prod) {
    const s = (prod || '').replace('PLATANO IGP DESTRIO ', '').trim();
    return s ? (s.charAt(0) + s.slice(1).toLowerCase()) : 'Destrío';
}

// --- Filtros SQL reutilizables ---
const FAB = `CAST(FechaFabricacion AS date) = ${DAY_SQL} AND Tipo='Fabricado' AND NombreFamilia='PLATANO DE CANARIAS IGP'`;
const CONF = `${FAB} AND NombreProducto NOT LIKE '%DESTRIO%'`;
const DES = `${FAB} AND NombreProducto LIKE '%DESTRIO%'`;
const VOLC = `CAST(Fecha AS date) = ${DAY_SQL}`;

// --- Consultas ---
const Q = {
    volcado: `SELECT CAST(ISNULL(SUM(PesoNetoVolcado),0) AS int) kg FROM dbo.MercanciaVolcada WHERE ${VOLC}`,
    confEnvase: `SELECT NombreEnvase, CAST(SUM(co) AS int) kg
        FROM (SELECT DISTINCT Pale, PartidaOrigen, NombreEnvase, CantidadOrigen co
              FROM dbo.ProduccionLineal WHERE ${CONF}) d
        GROUP BY NombreEnvase`,
    destrio: `SELECT NombreProducto, CAST(SUM(co) AS int) kg
        FROM (SELECT DISTINCT Pale, PartidaOrigen, NombreProducto, CantidadOrigen co
              FROM dbo.ProduccionLineal WHERE ${DES}) d
        GROUP BY NombreProducto`,
    // Pales confeccionados con su instante de fin y su peso (suma de partidas).
    pales: `SELECT Pale, MAX(CLI501_FechaHoraFinPalet) finish, SUM(co) kg
        FROM (SELECT DISTINCT Pale, PartidaOrigen, CLI501_FechaHoraFinPalet, CantidadOrigen co
              FROM dbo.ProduccionLineal WHERE ${CONF}) d
        GROUP BY Pale`,
    now: `SELECT GETDATE() serverNow`,
};

// ---------------------------------------------------------------------------
// Acceso a datos
// ---------------------------------------------------------------------------
let sql = null;
let poolPromise = null;

function getPool() {
    if (!sql) { sql = require('mssql'); }
    if (!poolPromise) {
        poolPromise = new sql.ConnectionPool({
            server: MSSQL_HOST,
            port: parseInt(process.env.MSSQL_PORT || '1433', 10),
            database: process.env.MSSQL_DATABASE || '',
            user: process.env.MSSQL_USER || '',
            password: process.env.MSSQL_PASSWORD || '',
            connectionTimeout: 8000,
            requestTimeout: 15000,
            pool: { max: 3, min: 0, idleTimeoutMillis: 30000 },
            options: {
                encrypt: (process.env.MSSQL_ENCRYPT || 'false') === 'true',
                trustServerCertificate: (process.env.MSSQL_TRUST_CERT || 'true') === 'true',
                instanceName: process.env.MSSQL_INSTANCE || undefined,
            },
        }).connect();
        poolPromise.catch(() => { poolPromise = null; });
    }
    return poolPromise;
}

// Construye el reparto (partes con kg y %) a partir de filas {clave, kg}.
function reparto(filas, total) {
    return filas.map((f) => ({
        label: f.label,
        kg: f.kg,
        pct: total > 0 ? Math.round((f.kg / total) * 100) : 0,
    }));
}

// Calcula productividad (KG/h) y serie horaria a partir de los pales confeccionados.
function calcularProductividad(pales, serverNow) {
    const conTiempo = pales
        .filter((p) => p.finish)
        .map((p) => ({ kg: p.kg || 0, t: new Date(p.finish).getTime() }));
    const totalConf = pales.reduce((s, p) => s + (p.kg || 0), 0);

    // Instante de referencia: hoy = ahora del servidor; dias pasados = ultimo pale del dia.
    let ref;
    if (DAY_OFFSET === 0) {
        ref = new Date(serverNow).getTime();
    } else if (conTiempo.length) {
        ref = Math.max.apply(null, conTiempo.map((p) => p.t));
    } else {
        ref = new Date(serverNow).getTime();
    }

    const minT = conTiempo.length ? Math.min.apply(null, conTiempo.map((p) => p.t)) : null;
    const maxT = conTiempo.length ? Math.max.apply(null, conTiempo.map((p) => p.t)) : null;
    const horas = (minT !== null && maxT !== null && maxT > minT) ? (maxT - minT) / 3600000 : null;

    const rate = (mins, mult) => {
        const cut = ref - mins * 60000;
        const kg = conTiempo.filter((p) => p.t > cut).reduce((s, p) => s + p.kg, 0);
        return Math.round(kg * mult);
    };

    // Serie por hora (hora local del servidor = getUTCHours porque mssql envuelve en UTC).
    const buckets = {};
    conTiempo.forEach((p) => {
        const h = new Date(p.t).getUTCHours();
        buckets[h] = (buckets[h] || 0) + p.kg;
    });
    const serie = Object.keys(buckets)
        .map(Number).sort((a, b) => a - b)
        .map((h) => ({ hora: h, kg: Math.round(buckets[h]) }));

    return {
        productividad: {
            mediaDia: horas ? Math.round(totalConf / horas) : totalConf,
            ultimaHora: rate(60, 1),
            ultimos30: rate(30, 2),
            ultimos10: rate(10, 6),
        },
        serie: serie,
    };
}

async function fetchFromSql() {
    const pool = await getPool();
    const [rVolc, rEnv, rDes, rPales, rNow] = await Promise.all([
        pool.request().query(Q.volcado),
        pool.request().query(Q.confEnvase),
        pool.request().query(Q.destrio),
        pool.request().query(Q.pales),
        pool.request().query(Q.now),
    ]);

    // Volcado
    const volcado = (rVolc.recordset[0] || {}).kg || 0;

    // Confeccionado -> agrupar envases por cliente
    const porCliente = {};
    let confTotal = 0;
    (rEnv.recordset || []).forEach((r) => {
        const cli = clienteDeEnvase(r.NombreEnvase);
        porCliente[cli] = (porCliente[cli] || 0) + (r.kg || 0);
        confTotal += r.kg || 0;
    });
    const ordenCli = ['Mercadona', 'Consum', 'Otros'];
    const confPartes = ordenCli
        .filter((c) => porCliente[c])
        .map((c) => ({ label: c, kg: porCliente[c] }));

    // Destrio -> por tipo
    const porTipo = {};
    let desTotal = 0;
    (rDes.recordset || []).forEach((r) => {
        const t = nombreDestrio(r.NombreProducto);
        porTipo[t] = (porTipo[t] || 0) + (r.kg || 0);
        desTotal += r.kg || 0;
    });
    const ordenDes = ['Dedos', 'Manojo', 'Maduro', 'Tirado'];
    const desPartes = ordenDes
        .filter((t) => porTipo[t])
        .map((t) => ({ label: t, kg: porTipo[t] }))
        .concat(Object.keys(porTipo).filter((t) => ordenDes.indexOf(t) === -1)
            .map((t) => ({ label: t, kg: porTipo[t] })));

    const serverNow = (rNow.recordset[0] || {}).serverNow || new Date().toISOString();
    const prod = calcularProductividad(rPales.recordset || [], serverNow);

    return {
        volcado: volcado,
        confeccionado: { total: confTotal, partes: reparto(confPartes, confTotal) },
        destrio: { total: desTotal, partes: reparto(desPartes, desTotal) },
        productividad: prod.productividad,
        serie: prod.serie,
    };
}

// ---------------------------------------------------------------------------
// Datos DEMO (mismo esquema; varian un poco en cada refresco)
// ---------------------------------------------------------------------------
const mockState = { conf: 25000, volc: 28000 };

function fetchMock() {
    mockState.conf += Math.floor(Math.random() * 200);
    mockState.volc += Math.floor(Math.random() * 220);
    const conf = mockState.conf;
    const merca = Math.round(conf * 0.67);
    const consum = Math.round(conf * 0.31);
    const otros = conf - merca - consum;
    const des = Math.round(conf * 0.12);
    const serie = [6, 7, 8, 9, 10, 11, 12].map((h) => ({
        hora: h, kg: Math.round(3000 + Math.random() * 2500),
    }));
    const ref = new Date();
    ref.setDate(ref.getDate() + DAY_OFFSET);
    return {
        volcado: mockState.volc,
        confeccionado: { total: conf, partes: reparto([
            { label: 'Mercadona', kg: merca }, { label: 'Consum', kg: consum }, { label: 'Otros', kg: otros },
        ], conf) },
        destrio: { total: des, partes: reparto([
            { label: 'Dedos', kg: Math.round(des * 0.4) }, { label: 'Manojo', kg: Math.round(des * 0.3) },
            { label: 'Maduro', kg: Math.round(des * 0.2) }, { label: 'Tirado', kg: Math.round(des * 0.1) },
        ], des) },
        productividad: { mediaDia: 4200, ultimaHora: 3800, ultimos30: 4600, ultimos10: 5200 },
        serie: serie,
    };
}

// ---------------------------------------------------------------------------
// Servidor HTTP
// ---------------------------------------------------------------------------
let lastGood = null;

function refDateFor() {
    const ref = new Date();
    ref.setDate(ref.getDate() + DAY_OFFSET);
    return ('0' + ref.getDate()).slice(-2) + '/' +
        ('0' + (ref.getMonth() + 1)).slice(-2) + '/' + ref.getFullYear();
}

async function handleData(res) {
    let payload;
    try {
        const data = IS_MOCK ? fetchMock() : await fetchFromSql();
        lastGood = Object.assign({
            ok: true,
            mock: IS_MOCK,
            stale: false,
            title: TITLE,
            refreshSeconds: REFRESH_SECONDS,
            refDate: refDateFor(),
            objetivo: OBJETIVO,
            updatedAt: new Date().toISOString(),
        }, data);
        payload = lastGood;
    } catch (err) {
        console.error('[dashboard] error consultando SQL: ' + err.message);
        payload = lastGood
            ? Object.assign({}, lastGood, { stale: true })
            : {
                ok: false, mock: IS_MOCK, stale: true, title: TITLE,
                refreshSeconds: REFRESH_SECONDS, refDate: refDateFor(), objetivo: OBJETIVO,
                updatedAt: null, error: 'Sin conexión con la base de datos',
                volcado: 0, confeccionado: { total: 0, partes: [] },
                destrio: { total: 0, partes: [] },
                productividad: { mediaDia: 0, ultimaHora: 0, ultimos30: 0, ultimos10: 0 },
                serie: [],
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
    if (url === '/api/data') { handleData(res); return; }
    if (url === '/healthz') {
        res.writeHead(200, { 'Content-Type': 'text/plain' });
        res.end('ok');
        return;
    }
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
    res.end(indexHtml);
});

server.listen(PORT, function () {
    console.log('[dashboard] escuchando en :' + PORT
        + (IS_MOCK ? ' (MODO DEMO, sin MSSQL_HOST)' : ' (SQL Server: ' + MSSQL_HOST + ')')
        + ' | dia offset=' + DAY_OFFSET + ' | objetivo=' + OBJETIVO + ' kg/h');
});
