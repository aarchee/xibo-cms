# 🍌 Manual del Panel del Operador — DisplaFruit

Guía práctica, en lenguaje sencillo, para publicar y gestionar el contenido de las pantallas
desde el **panel del operador** de DisplaFruit. No necesitas saber nada técnico.

> Para arrancar el sistema (Docker) o conectar una Smart TV nueva, mira
> `GUIA_DE_USO_DISPLAFRUIT.md` y `GUIA_DE_CONFIGURACION_DISPLAFRUIT.md`.

---

## 1. Entrar al panel

1. Abre el navegador en **http://localhost** (o `http://LA-IP-DEL-SERVIDOR` desde otro equipo).
2. Inicia sesión:
   - **Operador** (empleado): al entrar aterriza **directamente** en el panel.
   - **Administrador** (`xibo_admin`): ve a **http://localhost/displafruit/dashboard**.

El panel se **actualiza solo cada pocos segundos**: no hace falta recargar la página.

---

## 2. Las cuatro zonas del panel

```
┌───────────────────────────────────────────────────────────┐
│  🍌 DisplaFruit          [Pantallas] [Contenido] [Salir]   │  ← barra superior
├───────────────────────────────────────────────────────────┤
│  [ online ]  [ offline ]  [ totales ]                      │  ← resumen
│                                                            │
│           📢  PUBLICAR CONTENIDO   (botón grande)          │
│                                                            │
│  📺 En antena ahora                                        │  ← qué se está emitiendo
│     [miniatura] Oferta plátano · Todas · Permanente [Parar]│
│                                                            │
│  🖥️ Pantallas                                              │  ← estado de cada TV
│     ● displa1  Online · Autorizada   [miniatura] en antena │
│                                                            │
│  🕑 Historial                                              │  ← lo ya publicado
│     [min] Oferta plátano · Todas · 12:30   [Republicar]    │
└───────────────────────────────────────────────────────────┘
```

- **Resumen:** cuántas pantallas hay encendidas (online), apagadas (offline) y en total.
- **📺 En antena ahora:** lo que se está mostrando en este momento, con botón **Parar**.
- **🖥️ Pantallas:** una tarjeta por pantalla (verde = encendida, roja = apagada), si está
  **autorizada**, y una **miniatura** de lo que se ve en ella ahora.
- **🕑 Historial:** las últimas publicaciones, cada una con botón **Republicar**.

---

## 3. ⭐ Publicar contenido (lo más habitual)

Pulsa el botón grande **📢 Publicar contenido**. Se abre una ventana con estos campos:

### a) Archivo
Elige una **imagen, vídeo o PDF** de tu ordenador. *(Obligatorio.)*

### b) Nombre *(opcional)*
Un texto para reconocerlo luego en el historial. Ej.: *"Oferta plátano IGP"*.

### c) ¿Dónde? — a qué pantallas
| Opción | Qué hace |
|---|---|
| **Todas** | Lo muestra en **todas** las pantallas. *(Lo normal.)* |
| **Un grupo** | Solo en un **grupo** de pantallas (elígelo en la lista). |
| **Una pantalla** | Solo en **una** pantalla concreta (elígela en la lista). |

### d) ¿Cuánto tiempo?
| Opción | Qué hace |
|---|---|
| **Temporal** | Se muestra los **segundos** que indiques (por defecto 30) **interrumpiendo** lo que hubiera, y luego las pantallas vuelven a su contenido. Ideal para un aviso puntual. |
| **Permanente** | Se queda **hasta que lo pares** tú (botón Parar). Es el "contenido de fondo" de las pantallas. |
| **Fechas** | Se muestra en una **ventana concreta**: eliges **Desde** y **Hasta**. Ej.: una promo del 1 al 7 de julio. |

### e) Urgente (interrumpe lo que haya)
Casilla opcional. Márcala para que el contenido **corte** lo que se esté viendo (prioridad alta).
- "Temporal" ya interrumpe siempre.
- "Permanente" y "Fechas" son contenido base **salvo** que marques "Urgente".

### f) Publicar
Pulsa **Publicar**. Verás *"✅ Publicado en N pantalla(s) online"*. Aparecerá en **"En antena ahora"**
y en el **Historial**.

> Si pone *"Publicado en 0 pantallas"*, es que **ninguna TV está encendida y conectada** en ese
> momento. El contenido igualmente queda programado y saldrá cuando se enciendan.

---

## 4. Ver qué se está emitiendo y **pararlo**

En **📺 En antena ahora** ves cada publicación activa: miniatura, destino (Todas / grupo / pantalla),
si es **permanente** o cuánto **tiempo le queda**, y si es **urgente**.

- Para **quitarla**, pulsa **Parar**. Te pide confirmación. Al parar, las pantallas **vuelven a su
  contenido base** (o quedan en espera si no había otro).

---

## 5. Republicar algo del **Historial**

En **🕑 Historial** están tus últimas publicaciones (activas y paradas). Para volver a poner una:

- Pulsa **Republicar**. Se vuelve a emitir el **mismo contenido** con la misma configuración.
- Si el botón está **gris**, es que ese archivo ya se borró de la biblioteca (no se puede republicar).

---

## 6. Estado de las pantallas

Cada tarjeta de **🖥️ Pantallas** te dice:
- 🟢 **Online** / 🔴 **Offline**: si la TV está encendida y conectada.
- **Autorizada** / **Sin autorizar**: si el administrador ya le dio permiso para conectarse.
- **Miniatura + "en antena"**: qué se ve ahora en esa pantalla (o "Sin contenido").

> Si no ves ninguna pantalla, pide al administrador que **comparta las pantallas** con tu grupo
> **"Operador Pantallas"** (ver `GUIA_DE_USO_DISPLAFRUIT.md`, apartado 6).

---

## 7. Preguntas frecuentes

**¿El contenido aparece al instante en las TVs?**
Normalmente hay unos segundos de margen: las pantallas consultan el servidor cada cierto tiempo
(sondeo). Para entrega **instantánea** hace falta configurar XMR (tarea del administrador; ver
`handoff.md` §9). El **panel** sí se actualiza al momento.

**¿Por qué una publicación con "Fechas" de inicio futuro no la veo ya en el player web?**
El **player web gratuito** (navegador-kiosko) muestra una sola pieza por grupo: la **vigente**. Una
publicación programada para **empezar más tarde** no aparece hasta que le toque (o hasta que sea el
contenido vigente). Las **pantallas con Xibo for Android** sí respetan la fecha exacta. En el panel,
una publicación de inicio futuro **no** figura como "En antena ahora" hasta que empieza.

**¿Por qué unas tarjetas muestran miniatura y otras un icono?**
Las **imágenes** muestran miniatura. Los **vídeos** (🎬) y **PDF** (📄) muestran un icono.

**Diferencia entre "Parar" y que expire solo:**
- "Temporal" y "Fechas" **expiran solos** al acabar su tiempo.
- "Permanente" **no expira**: se queda hasta que pulses **Parar**.

**¿Puedo publicar desde el móvil o una tablet?**
Sí, el panel es responsive. Entra desde el navegador de la tablet a `http://LA-IP-DEL-SERVIDOR`.

---

## 8. Resumen de un vistazo

| Quiero… | Hago… |
|---|---|
| Poner un cartel en todas las pantallas para siempre | Publicar → Todas → **Permanente** |
| Un aviso puntual 20 s que interrumpa | Publicar → Todas → **Temporal** 20 → (o marca Urgente) |
| Una promo del 1 al 7 de julio | Publicar → Todas → **Fechas** (Desde/Hasta) |
| Algo solo en la pantalla de caja | Publicar → **Una pantalla** → elige la pantalla |
| Quitar lo que hay puesto | **📺 En antena ahora** → **Parar** |
| Volver a poner algo de ayer | **🕑 Historial** → **Republicar** |
| Ver si una TV está encendida y qué muestra | Mirar su tarjeta en **🖥️ Pantallas** |

---

*DisplaFruit S.A. — Sistema de Cartelería Digital. Soporte técnico: `README_DISPLAFRUIT.md`.*
