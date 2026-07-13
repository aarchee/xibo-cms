<?php
/*
 * DisplaFruit Signage — middleware personalizado.
 *
 * Registra rutas y controladores de DisplaFruit SIN modificar ficheros core de Xibo:
 *   - State::setMiddleWare() (lib/Middleware/State.php) llama a setApp() y addRoutes()
 *     sobre cada middleware declarado en custom/settings-custom.php, antes de cargar
 *     lib/routes.php / lib/routes-web.php. Aprovechamos ese punto de extensión.
 *   - Los controladores se registran en el contenedor PHP-DI en tiempo de ejecución,
 *     replicando el patrón de lib/Dependencies/Controllers.php (useBaseDependenciesService).
 */

namespace Xibo\Custom\DisplaFruit\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Xibo\Custom\DisplaFruit\Controller\DashboardController;
use Xibo\Custom\DisplaFruit\Controller\PlayerController;
use Xibo\Custom\DisplaFruit\Controller\PublishController;
use Xibo\Middleware\CustomMiddlewareTrait;
use Xibo\Middleware\FeatureAuth;

class DisplaFruitMiddleware implements MiddlewareInterface
{
    use CustomMiddlewareTrait;

    /**
     * Registrar rutas y controladores. Invocado por State::setMiddleWare().
     */
    public function addRoutes(): void
    {
        $app = $this->getApp();
        $container = $app->getContainer();

        // --- Registrar los controladores en el contenedor DI ---
        $container->set(DashboardController::class, function ($c) {
            $controller = new DashboardController(
                $c->get('displayFactory'),
                $c->get('displayGroupFactory')
            );
            $controller->useBaseDependenciesService($c->get('ControllerBaseDependenciesService'));
            return $controller;
        });

        $container->set(PublishController::class, function ($c) {
            $controller = new PublishController(
                $c->get('mediaFactory'),
                $c->get('moduleFactory'),
                $c->get('layoutFactory'),
                $c->get('scheduleFactory'),
                $c->get('dayPartFactory'),
                $c->get('displayGroupFactory'),
                $c->get('displayFactory'),
                $c->get('campaignFactory'),
                $c->get('folderFactory'),
                $c->get('mediaService'),
                $c->get('store')
            );
            $controller->useBaseDependenciesService($c->get('ControllerBaseDependenciesService'));
            return $controller;
        });

        // Player web (reproductor gratuito basado en navegador).
        $container->set(PlayerController::class, function ($c) {
            $controller = new PlayerController(
                $c->get('store'),
                $c->get('mediaFactory')
            );
            $controller->useBaseDependenciesService($c->get('ControllerBaseDependenciesService'));
            return $controller;
        });

        // --- Registrar las rutas según el punto de entrada (web vs API) ---
        // El nombre ('web' / 'api') se fija antes de setMiddleWare() en cada index.php.
        // Otros entrypoints (preview, xmds) pueden no fijarlo: en ese caso no añadimos rutas.
        $name = $container->has('name') ? $container->get('name') : null;

        if ($name === 'web') {
            // Panel simplificado del operador (autenticación por sesión + CSRF).
            // Solo 'displafruit.operator': FeatureAuth es OR, así que añadir 'displays.view'
            // dejaría entrar a cualquier usuario con vista de pantallas. Los super-admins
            // siguen pasando porque User::featureEnabled() devuelve true para ellos.
            $app->get('/displafruit/dashboard', [DashboardController::class, 'dashboard'])
                ->setName('displafruit.dashboard')
                ->add(new FeatureAuth($container, ['displafruit.operator']));

            // Publicación rápida desde el panel web (sesión + CSRF).
            $app->post('/displafruit/publish-all', [PublishController::class, 'publishAll'])
                ->setName('displafruit.publishAll.web')
                ->add(new FeatureAuth($container, ['library.add']));

            // Gestión de contenido del panel (sesión + CSRF).
            $app->post('/displafruit/republish', [PublishController::class, 'republish'])
                ->setName('displafruit.republish.web')
                ->add(new FeatureAuth($container, ['library.add']));
            $app->post('/displafruit/unpublish', [PublishController::class, 'unpublish'])
                ->setName('displafruit.unpublish.web')
                ->add(new FeatureAuth($container, ['library.add']));

            // Lanzar un layout de Xibo (p. ej. el dashboard de producción) a un destino.
            $app->post('/displafruit/publish-layout', [PublishController::class, 'publishLayout'])
                ->setName('displafruit.publishLayout.web')
                ->add(new FeatureAuth($container, ['library.add']));
            // Lista de layouts publicados para el desplegable del panel.
            $app->get('/displafruit/layouts', [PublishController::class, 'layouts'])
                ->setName('displafruit.layouts.web')
                ->add(new FeatureAuth($container, ['displafruit.operator']));

            // Lecturas JSON del panel (estado auto-refrescado, historial, miniaturas).
            $app->get('/displafruit/dashboard/state', [PublishController::class, 'state'])
                ->setName('displafruit.dashboard.state')
                ->add(new FeatureAuth($container, ['displafruit.operator']));
            $app->get('/displafruit/dashboard/history', [PublishController::class, 'history'])
                ->setName('displafruit.dashboard.history')
                ->add(new FeatureAuth($container, ['displafruit.operator']));
            $app->get('/displafruit/dashboard/thumbnail/{id}', [PublishController::class, 'thumbnail'])
                ->setName('displafruit.dashboard.thumbnail')
                ->add(new FeatureAuth($container, ['displafruit.operator']));

            // Player web (reproductor gratuito para Smart TV en navegador-kiosko).
            // SIN FeatureAuth: son rutas PÚBLICAS (la TV no inicia sesión). Se marcan como
            // públicas en process() vía appendPublicRoutes para que WebAuthentication no redirija.
            $app->get('/displafruit/player', [PlayerController::class, 'player'])
                ->setName('displafruit.player');
            $app->get('/displafruit/player/state', [PlayerController::class, 'state'])
                ->setName('displafruit.player.state');
            $app->get('/displafruit/player/media/{id}', [PlayerController::class, 'media'])
                ->setName('displafruit.player.media');
        } elseif ($name === 'api') {
            // Endpoint REST para integraciones (Power Automate, etc.) — OAuth2.
            $app->post('/displafruit/publish-all', [PublishController::class, 'publishAll'])
                ->setName('displafruit.publishAll')
                ->add(new FeatureAuth($container, ['library.add']));
        }
    }

    /**
     * Pass-through. Aprovechamos para registrar la feature personalizada de DisplaFruit
     * de modo que aparezca en la pantalla de edición de Grupos de Usuario.
     */
    public function process(Request $request, Handler $handler): ResponseInterface
    {
        try {
            $this->getApp()->getContainer()->get('userGroupFactory')
                ->registerCustomFeature('displafruit.operator', __('DisplaFruit: Operador de Pantallas'));
        } catch (\Throwable $e) {
            // Nunca bloquear la petición por esto.
        }

        // Marcar como públicas las rutas del player web (la Smart TV no está autenticada).
        // Este middleware corre después de State (que fija publicRoutes) y antes de
        // WebAuthentication (que las lee), por lo que el atributo llega a tiempo.
        $request = $this->appendPublicRoutes($request, [
            '/displafruit/player',
            '/displafruit/player/state',
            '/displafruit/player/media/{id}',
        ]);

        return $handler->handle($request);
    }
}
