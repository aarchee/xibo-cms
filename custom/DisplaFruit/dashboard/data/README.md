# Carpeta de datos del dashboard — Excel de operarios (RRHH)

El dashboard divide la productividad de la línea entre el **nº de operarios de línea** para
compararlo con el objetivo de 150 kg/h **por operario**. Ese número lo lleva RRHH en un Excel
de SharePoint (biblioteca **DATOS BI**):

- Fichero: **`HORAS TRABAJO ALM PLATANO.xlsx`**
- Hoja: **`H TRABAJO DIARIO`**
- Columna usada: **`ASIS PROD`** (asistencia de producción propia)

## Cómo alimentarlo (dos vías)

**A) Automático (recomendado):** en el explorador de SharePoint pulsa **"Sincronizar"** sobre la
carpeta `DATOS BI`. Windows crea una carpeta local que se auto-actualiza. Luego, en el `.env` de
la raíz del repo, apunta `OPERARIOS_XLSX_DIR` a esa carpeta local, p. ej.:

```
OPERARIOS_XLSX_DIR=C:\Users\<tu-usuario>\Displafruit S.A\DATOS BI
```

Reinicia el contenedor (`docker compose up -d displafruit-dashboard`) y listo: leerá el fichero
sincronizado por fecha, sin tocar nada más.

**B) Manual (rápido / pruebas):** deja una copia del `.xlsx` **en esta carpeta**
(`custom/DisplaFruit/dashboard/data/`). Es la ruta por defecto que monta
`docker-compose.override.yml`. Tendrás que reemplazar la copia cuando quieras datos frescos.

## Respaldo (el Excel llega tarde)

RRHH lo rellena con 1-2 días de retraso. Por eso, si el día pedido está a 0/vacío, el dashboard
usa el **último día anterior con dato**. Si el Excel no está disponible, cae al conteo SQL de
`InformePresencia`. Y siempre puedes fijar el número a mano con `DASHBOARD_OPERARIOS=NN` en `.env`
(gana sobre todo). El panel muestra entre paréntesis de dónde salió el número cuando no es el del
día exacto.

> El `.xlsx` está en `.gitignore`: contiene datos de RRHH y no se versiona.
