/*
 * DisplaFruit — dashboard de producción para pantallas.
 *
 * Servicio autocontenido (Node, sin framework):
 *   GET /            -> la página del dashboard (public/index.html)
 *   GET /api/data    -> JSON con los datos de produccion (SQL Server o datos demo)
 *   GET /healthz     -> 200 si el proceso vive
 *
 * Estructura del panel (ver public/index.html):
 *   - Banda: KG CONFECCIONADO (Mercadona/Consum) | KG DESTRIO (cartón). VOLCADO oculto (pendiente).
 *   - Productividad: KG/h en MEDIA DIA / ultima hora / ultimos 30' / ultimos 10', vs objetivo.
 *   - Grafico de lineas: confeccionado por hora (productividad de la linea).
 *
 * Reglas de negocio (BD de línea 'DisplaFruit' en srv-produccion\sqlexpress; viva al minuto),
 * confirmadas 2026-07-09:
 *   - [dbo].[Produccion.CajasConfeccionadas.Info]: 1 fila = una CAJA (pesoNeto) con idConfeccion
 *     y fechaHoraInspeccion. El KG de cada categoría = SUM(pesoNeto) por idConfeccion y día.
 *   - Catálogo [dbo].[Cliente.Confecciones]: idConfeccion 1=CONSUM, 2=MERCADONA (producto de
 *     marca = CONFECCIONADO); 3=Cartón Doniz 17kg, 4=Cartón 16kg, 5=Cartón 10kg, 6=Cartón 9kg
 *     (segunda calidad en cartón genérico = DESTRIO); 7=Banana (no se produce, se ignora).
 *   - PRODUCTIVIDAD/gráfico: cajas de producto bueno (idConfeccion 1,2) con fechaHoraInspeccion.
 *   - VOLCADO (kg) y el desglose de destrío por calidad (dedos/manojo/...) NO están en esta BD
 *     (tablas de recepción sin acceso para el usuario read-only). Volcado va a null -> banda oculta.
 *   - GETDATE() devuelve hora local del servidor (Europe/Madrid); los datetime se guardan en local.
 *
 * Dia de referencia: DASHBOARD_DAY_OFFSET (0=hoy, -1=ayer). En modo pasado, las ventanas
 * "ultimos X min" se calculan respecto a la ultima caja de ese dia (no la hora actual).
 *
 * Robustez: si una consulta falla, se sirve el ultimo dato bueno marcado "stale".
 */

'use strict';

const http = require('http');
const fs = require('fs');
const path = require('path');

const PORT = parseInt(process.env.PORT || '8080', 10);
const REFRESH_SECONDS = Math.max(5, parseInt(process.env.REFRESH_SECONDS || '60', 10));
// Objetivo de LÍNEA (kg/h TOTAL, sin dividir por operarios). Decisión de gerencia (2026-07-10):
// el objetivo por operario no era fiable (el nº de operarios no se actualiza a tiempo), así que se
// fija un objetivo total de línea de 6000 kg/h y se ignora por completo el nº de operarios.
const OBJETIVO = parseInt(process.env.DASHBOARD_OBJETIVO_KGH || '6000', 10);

const MSSQL_HOST = (process.env.MSSQL_HOST || '').trim();
const IS_MOCK = MSSQL_HOST === '';

// Dia de referencia: 0 = hoy, -1 = ayer... (validacion contra PowerBI).
const DAY_OFFSET = parseInt(process.env.DASHBOARD_DAY_OFFSET || '0', 10);
const DAY_SQL = `CAST(DATEADD(DAY, ${DAY_OFFSET}, GETDATE()) AS date)`;
const DAY_WORD = DAY_OFFSET === 0 ? 'hoy' : (DAY_OFFSET === -1 ? 'ayer' : ('día ' + DAY_OFFSET));
const TITLE = process.env.DASHBOARD_TITLE
    || ('Producción ' + (DAY_OFFSET === 0 ? 'diaria' : DAY_WORD + ' (validación)'));

// --- Mapa idConfeccion -> categoría (BD de línea, tabla Cliente.Confecciones) ---
// CONFECCIONADO (producto de marca) = Consum(1) + Mercadona(2).
// DESTRÍO (segunda calidad, encajada en cartón genérico) = idConfeccion 3,4,5,6.
// idConfeccion 7 (Banana) no se produce (0 en 30 días); se ignora.
const CONF_CLIENTE = { 1: 'Consum', 2: 'Mercadona' };
const DESTRIO_LABEL = { 3: 'Doniz 17kg', 4: 'Cartón 16kg', 5: 'Cartón 10kg', 6: 'Cartón 9kg' };
const ORDEN_CONF = ['Mercadona', 'Consum'];
const ORDEN_DES = ['Doniz 17kg', 'Cartón 16kg', 'Cartón 10kg', 'Cartón 9kg'];

// --- Consultas (BD DisplaFruit; nombres de tabla con puntos -> corchetes) ---
const Q = {
    // pesoNeto por idConfeccion del día -> confeccionado (1,2) y destrío (3-6).
    porConfeccion: `SELECT i.idConfeccion, CAST(SUM(i.pesoNeto) AS int) kg
        FROM [dbo].[Produccion.CajasConfeccionadas.Info] i
        WHERE CAST(i.fechaHoraInspeccion AS date) = ${DAY_SQL}
        GROUP BY i.idConfeccion`,
    // Cajas de producto bueno (Merca+Consum) con su instante -> productividad y serie horaria.
    cajas: `SELECT i.fechaHoraInspeccion finish, i.pesoNeto kg
        FROM [dbo].[Produccion.CajasConfeccionadas.Info] i
        WHERE CAST(i.fechaHoraInspeccion AS date) = ${DAY_SQL} AND i.idConfeccion IN (1,2)`,
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
        const instance = process.env.MSSQL_INSTANCE || '';
        const cfg = {
            server: MSSQL_HOST,
            database: process.env.MSSQL_DATABASE || '',
            user: process.env.MSSQL_USER || '',
            password: process.env.MSSQL_PASSWORD || '',
            connectionTimeout: 8000,
            requestTimeout: 15000,
            pool: { max: 3, min: 0, idleTimeoutMillis: 30000 },
            options: {
                encrypt: (process.env.MSSQL_ENCRYPT || 'false') === 'true',
                trustServerCertificate: (process.env.MSSQL_TRUST_CERT || 'true') === 'true',
            },
        };
        // Instancia con nombre (p.ej. SQLEXPRESS) y puerto son mutuamente excluyentes en tedious:
        // con instancia se resuelve el puerto vía SQL Browser (UDP 1434).
        if (instance) {
            cfg.options.instanceName = instance;
        } else {
            cfg.port = parseInt(process.env.MSSQL_PORT || '1433', 10);
        }
        poolPromise = new sql.ConnectionPool(cfg).connect();
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

// Calcula productividad (KG/h TOTAL de la línea) y serie horaria a partir de los pales
// confeccionados. Es el ritmo total de la línea (ya NO se divide por operarios); se compara
// directamente contra el objetivo de línea (OBJETIVO = 6000 kg/h).
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

    // Serie por tramos de 30 min (kg/h de línea). getUTCHours/Minutes = hora local (mssql
    // envuelve en UTC). Cada punto = ritmo del tramo en kg/h: kg del tramo escalado a 1 hora
    // (x2 en tramos completos). El tramo EN CURSO se escala por los minutos ya transcurridos
    // (no x2) para no dibujar una caída falsa en el último punto.
    const buckets = {};
    conTiempo.forEach((p) => {
        const d = new Date(p.t);
        const key = d.getUTCHours() * 60 + (d.getUTCMinutes() < 30 ? 0 : 30);
        buckets[key] = (buckets[key] || 0) + p.kg;
    });
    const refD = new Date(ref);
    const refMin = refD.getUTCHours() * 60 + refD.getUTCMinutes();
    // Eje de tiempo CONTINUO: recorre todos los tramos de 30 min entre el primero y el último con
    // datos, rellenando con 0 los tramos SIN producción (paradas de línea). Así la parada se ve
    // como una caída y las horas del eje quedan alineadas (no se colapsan los huecos).
    const claves = Object.keys(buckets).map(Number);
    const serie = [];
    if (claves.length) {
        const minK = Math.min.apply(null, claves);
        const maxK = Math.max.apply(null, claves);
        for (let k = minK; k <= maxK; k += 30) {
            const enCurso = k <= refMin && refMin < k + 30;
            const mins = enCurso ? Math.max(5, refMin - k) : 30;
            serie.push({
                label: ('0' + Math.floor(k / 60)).slice(-2) + ':' + ('0' + (k % 60)).slice(-2),
                kg: Math.round((buckets[k] || 0) * (60 / mins)),
            });
        }
    }

    return {
        productividad: {
            mediaDia: horas ? Math.round(totalConf / horas) : Math.round(totalConf),
            ultimaHora: rate(60, 1),
            ultimos30: rate(30, 2),
            ultimos10: rate(10, 6),
        },
        serie: serie,
    };
}

async function fetchFromSql() {
    const pool = await getPool();
    const [rConf, rCajas, rNow] = await Promise.all([
        pool.request().query(Q.porConfeccion),
        pool.request().query(Q.cajas),
        pool.request().query(Q.now),
    ]);

    // Reparto por idConfeccion -> confeccionado (marca) y destrío (cartón).
    const porCliente = {};
    let confTotal = 0;
    const porDes = {};
    let desTotal = 0;
    (rConf.recordset || []).forEach((r) => {
        const kg = r.kg || 0;
        if (CONF_CLIENTE[r.idConfeccion]) {
            const cli = CONF_CLIENTE[r.idConfeccion];
            porCliente[cli] = (porCliente[cli] || 0) + kg;
            confTotal += kg;
        } else if (DESTRIO_LABEL[r.idConfeccion]) {
            const t = DESTRIO_LABEL[r.idConfeccion];
            porDes[t] = (porDes[t] || 0) + kg;
            desTotal += kg;
        }
        // idConfeccion 7 (Banana) u otros no catalogados: se ignoran.
    });
    const confPartes = ORDEN_CONF.filter((c) => porCliente[c]).map((c) => ({ label: c, kg: porCliente[c] }));
    const desPartes = ORDEN_DES.filter((t) => porDes[t]).map((t) => ({ label: t, kg: porDes[t] }));

    const serverNow = (rNow.recordset[0] || {}).serverNow || new Date().toISOString();
    const prod = calcularProductividad(rCajas.recordset || [], serverNow);

    return {
        volcado: null, // PENDIENTE: no está en la BD de línea (tablas ocultas sin acceso).
        confeccionado: { total: confTotal, partes: reparto(confPartes, confTotal) },
        destrio: { total: desTotal, partes: reparto(desPartes, desTotal) },
        productividad: prod.productividad,
        serie: prod.serie,
    };
}

// ---------------------------------------------------------------------------
// Datos DEMO (mismo esquema; varian un poco en cada refresco)
// ---------------------------------------------------------------------------
const mockState = { conf: 25000 };

function fetchMock() {
    mockState.conf += Math.floor(Math.random() * 200);
    const conf = mockState.conf;
    const merca = Math.round(conf * 0.68);
    const consum = conf - merca;
    const des = Math.round(conf * 0.12);
    const tramos = ['06:00', '06:30', '07:00', '07:30', '08:00', '08:30', '09:00', '09:30', '10:00'];
    const serie = tramos.map((label) => ({
        label: label, kg: Math.round(3000 + Math.random() * 2500),
    }));
    return {
        volcado: null, // banda oculta (igual que en producción)
        confeccionado: { total: conf, partes: reparto([
            { label: 'Mercadona', kg: merca }, { label: 'Consum', kg: consum },
        ], conf) },
        destrio: { total: des, partes: reparto([
            { label: 'Cartón 16kg', kg: Math.round(des * 0.7) }, { label: 'Cartón 10kg', kg: Math.round(des * 0.3) },
        ], des) },
        productividad: { mediaDia: 4200, ultimaHora: 5100, ultimos30: 4800, ultimos10: 3900 },
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
                volcado: null, confeccionado: { total: 0, partes: [] },
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
