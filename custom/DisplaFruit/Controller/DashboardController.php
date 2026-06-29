<?php
/*
 * DisplaFruit Signage — controlador del panel simplificado del operador.
 *
 * Renderiza /displafruit/dashboard: tarjetas con el estado de cada pantalla
 * (online/offline, último acceso) y el botón "Publicar en todas las pantallas".
 */

namespace Xibo\Custom\DisplaFruit\Controller;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response as Response;
use Slim\Http\ServerRequest as Request;
use Xibo\Controller\Base;
use Xibo\Factory\DisplayFactory;
use Xibo\Factory\DisplayGroupFactory;

class DashboardController extends Base
{
    public function __construct(
        private readonly DisplayFactory $displayFactory,
        private readonly DisplayGroupFactory $displayGroupFactory
    ) {
    }

    /**
     * Panel del operador.
     */
    public function dashboard(Request $request, Response $response): Response|ResponseInterface
    {
        // Pantallas que el usuario puede ver (respeta permisos via ACL del factory).
        $displays = $this->displayFactory->query(['display'], []);

        $cards = [];
        $online = 0;
        foreach ($displays as $display) {
            if ($display->loggedIn == 1) {
                $online++;
            }

            $cards[] = [
                'displayId'    => $display->displayId,
                'name'         => $display->display,
                'loggedIn'     => (int) $display->loggedIn,
                'lastAccessed' => $display->lastAccessed,
            ];
        }

        $this->getState()->template = 'DisplaFruit/views/displafruit-dashboard';
        $this->getState()->setData([
            'displays'     => $cards,
            'displayCount' => count($cards),
            'onlineCount'  => $online,
            'offlineCount' => count($cards) - $online,
        ]);

        return $this->render($request, $response);
    }
}
