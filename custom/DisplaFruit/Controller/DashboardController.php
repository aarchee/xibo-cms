<?php
/*
 * DisplaFruit Signage — controlador del panel simplificado del operador.
 *
 * Renderiza /displafruit/dashboard: estado de pantallas, "en antena ahora", historial y
 * publicación segmentada. Los datos dinámicos se cargan por JSON desde PublishController
 * (state/history); aquí solo se prepara el armazón inicial y las listas para los selectores.
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
    /** Cada cuántos segundos refresca el panel su estado. */
    private const POLL_SECONDS = 8;

    /** Nombre del grupo gestionado por DisplaFruit (se excluye del selector manual). */
    private const ALL_DISPLAYS_GROUP = 'DisplaFruit - Todas';

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
        // Pantallas que el usuario puede ver (respeta permisos vía ACL del factory).
        $displays = $this->displayFactory->query(['display'], []);

        $cards = [];
        $online = 0;
        foreach ($displays as $display) {
            if ($display->loggedIn == 1) {
                $online++;
            }

            $cards[] = [
                'displayId'    => (int) $display->displayId,
                'name'         => $display->display,
                'loggedIn'     => (int) $display->loggedIn,
                'authorised'   => (int) $display->licensed,
                'lastAccessed' => $display->lastAccessed,
            ];
        }

        // Grupos sobre los que el usuario puede publicar (ACL), excluido el grupo gestionado.
        $groups = [];
        foreach ($this->displayGroupFactory->query(['displayGroup'], ['isDisplaySpecific' => 0]) as $group) {
            if ($group->displayGroup === self::ALL_DISPLAYS_GROUP) {
                continue;
            }
            $groups[] = [
                'displayGroupId' => (int) $group->displayGroupId,
                'name'           => $group->displayGroup,
            ];
        }

        $this->getState()->template = 'DisplaFruit/views/displafruit-dashboard';
        $this->getState()->setData([
            'displays'     => $cards,
            'groups'       => $groups,
            'displayCount' => count($cards),
            'onlineCount'  => $online,
            'offlineCount' => count($cards) - $online,
            'pollSeconds'  => self::POLL_SECONDS,
        ]);

        return $this->render($request, $response);
    }
}
