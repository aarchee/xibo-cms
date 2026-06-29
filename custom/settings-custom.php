<?php
/*
 * DisplaFruit Signage — configuración personalizada del CMS.
 *
 * web/settings.php (generado por docker/entrypoint.sh) incluye automáticamente este
 * fichero si existe (ver docker/tmp/settings.php-template). Aquí registramos el
 * middleware de DisplaFruit, que:
 *   - Añade las rutas /displafruit/dashboard (web) y /displafruit/publish-all (web + API).
 *   - Registra los controladores en el contenedor DI sin tocar lib/Dependencies/Controllers.php.
 *   - Registra la feature personalizada "displafruit.operator".
 *
 * No es necesario añadir el namespace a composer.json: Xibo\Custom\ ya está mapeado
 * por PSR-4 a custom/ (ver composer.json), por lo que la clase se autocarga.
 */

global $middleware;

if (!isset($middleware) || !is_array($middleware)) {
    $middleware = [];
}

$middleware[] = new \Xibo\Custom\DisplaFruit\Middleware\DisplaFruitMiddleware();
