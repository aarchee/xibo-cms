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
                $c->get('mediaService')
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
            $app->get('/displafruit/dashboard', [DashboardController::class, 'dashboard'])
                ->setName('displafruit.dashboard')
                ->add(new FeatureAuth($container, ['displafruit.operator', 'displays.view']));

            // Publicación rápida desde el panel web (sesión + CSRF).
            $app->post('/displafruit/publish-all', [PublishController::class, 'publishAll'])
                ->setName('displafruit.publishAll.web')
                ->add(new FeatureAuth($container, ['library.add']));
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

        return $handler->handle($request);
    }
}
