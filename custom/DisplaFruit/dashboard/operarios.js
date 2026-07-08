/*
 * DisplaFruit — nº de operarios de línea desde el Excel de RRHH (SharePoint).
 *
 * Decisión de negocio (2026-07-08): el objetivo de 150 kg/h es POR OPERARIO, así que
 * la productividad de la línea se divide entre los operarios de confección presentes.
 * La BD (InformePresencia) no distingue línea de almacén, así que la cifra fiable la
 * lleva RRHH en un Excel de SharePoint.
 *
 *   Fichero : HORAS TRABAJO ALM PLATANO.xlsx  (biblioteca SharePoint "DATOS BI")
 *   Hoja    : "H TRABAJO DIARIO"
 *   Columna : ASIS PROD (índice 2) = asistencia de producción propia. Elegida por el
 *             usuario frente a PERSONAS PROD (cabezas) y frente a incluir ETT.
 *   Fecha   : columna 0 (fecha serie de Excel).
 *
 * Respaldo: RRHH rellena el Excel con 1-2 días de RETRASO (el día en curso y a veces el
 * anterior están a 0). Si el día pedido no tiene dato, se usa el ÚLTIMO día anterior con
 * dato. Si el fichero no existe/ilegible, se devuelve null y el llamador cae al override
 * .env (DASHBOARD_OPERARIOS) o al conteo SQL de InformePresencia.
 *
 * Config (env):
 *   OPERARIOS_XLSX_PATH      ruta del .xlsx dentro del contenedor (vacío = desactivado)
 *   OPERARIOS_XLSX_SHEET     nombre de hoja (def. "H TRABAJO DIARIO")
 *   OPERARIOS_XLSX_COL       índice de columna del recuento (def. 2 = ASIS PROD)
 *   OPERARIOS_XLSX_DATE_COL  índice de columna de fecha (def. 0)
 *
 * El fichero se re-parsea sólo cuando cambia su mtime (cache), no en cada refresco.
 */

'use strict';

const fs = require('fs');

const XLSX_PATH = (process.env.OPERARIOS_XLSX_PATH || '').trim();
const SHEET = process.env.OPERARIOS_XLSX_SHEET || 'H TRABAJO DIARIO';
const COL = parseInt(process.env.OPERARIOS_XLSX_COL || '2', 10); // 2 = ASIS PROD
const DATE_COL = parseInt(process.env.OPERARIOS_XLSX_DATE_COL || '0', 10);

let XLSX = null;
let cache = { mtimeMs: -1, byDay: null, days: [] };

function ymd(y, m, d) {
    return y + '-' + ('0' + m).slice(-2) + '-' + ('0' + d).slice(-2);
}

// Re-parsea el Excel si su mtime cambió. Devuelve el Map día->valor o null.
function loadIfChanged() {
    if (!XLSX_PATH) return null;
    let st;
    try {
        st = fs.statSync(XLSX_PATH);
    } catch (e) {
        cache = { mtimeMs: -1, byDay: null, days: [] };
        return null;
    }
    if (cache.byDay && st.mtimeMs === cache.mtimeMs) return cache.byDay;
    try {
        if (!XLSX) XLSX = require('xlsx');
        const wb = XLSX.readFile(XLSX_PATH);
        const ws = wb.Sheets[SHEET];
        if (!ws) throw new Error('hoja no encontrada: ' + SHEET);
        const rows = XLSX.utils.sheet_to_json(ws, { header: 1, raw: true });
        const byDay = new Map();
        const days = [];
        rows.forEach((r) => {
            if (!r || r[DATE_COL] == null) return;
            const serial = r[DATE_COL];
            if (typeof serial !== 'number') return; // sólo fechas serie de Excel
            const dc = XLSX.SSF.parse_date_code(serial);
            if (!dc) return;
            const val = r[COL];
            if (typeof val !== 'number' || val <= 0) return; // filas sin rellenar = 0
            const key = ymd(dc.y, dc.m, dc.d);
            byDay.set(key, Math.round(val));
            days.push(key);
        });
        days.sort();
        cache = { mtimeMs: st.mtimeMs, byDay: byDay, days: days };
        console.log('[operarios] Excel cargado: ' + byDay.size + ' días con dato'
            + (days.length ? ' (último ' + days[days.length - 1] + ')' : ''));
        return byDay;
    } catch (e) {
        console.error('[operarios] error leyendo Excel: ' + e.message);
        cache = { mtimeMs: -1, byDay: null, days: [] };
        return null;
    }
}

// Devuelve { n, fuente: 'excel'|'excel-previo', fecha } para el día pedido, o null.
// refDay: { y, m, d } (1-based month/day).
function operariosDeExcel(refDay) {
    const byDay = loadIfChanged();
    if (!byDay) return null;
    const target = ymd(refDay.y, refDay.m, refDay.d);
    if (byDay.has(target)) return { n: byDay.get(target), fuente: 'excel', fecha: target };
    // Respaldo: último día anterior (o igual) con dato.
    const prev = cache.days.filter((k) => k <= target);
    if (prev.length) {
        const k = prev[prev.length - 1];
        return { n: byDay.get(k), fuente: 'excel-previo', fecha: k };
    }
    return null;
}

module.exports = { operariosDeExcel, XLSX_PATH: XLSX_PATH };
