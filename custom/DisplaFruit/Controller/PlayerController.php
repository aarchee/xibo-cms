<?php
/*
 * DisplaFruit Signage — "player web" (reproductor gratuito basado en navegador).
 *
 * Permite que una Smart TV (o cualquier pantalla con navegador) muestre el contenido publicado
 * SIN el reproductor de pago de Xibo: la TV abre /displafruit/player en un navegador-kiosko y la
 * página sondea el contenido actual y lo muestra a pantalla completa.
 *
 * Rutas (todas PÚBLICAS — la TV no está autenticada; ver DisplaFruitMiddleware):
 *   GET /displafruit/player              -> página del reproductor (HTML a pantalla completa)
 *   GET /displafruit/player/state        -> JSON: qué mostrar ahora (lee displafruit_now_playing)
 *   GET /displafruit/player/media/{id}   -> sirve el fichero (imagen/vídeo/PDF) con soporte de rangos
 *
 * Seguridad: el endpoint de fichero SOLO sirve media referenciado por displafruit_now_playing,
 * de modo que no se puede leer biblioteca arbitraria desde una ruta pública.
 */

namespace Xibo\Custom\DisplaFruit\Controller;

use Carbon\Carbon;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response as Response;
use Slim\Http\ServerRequest as Request;
use Xibo\Controller\Base;
use Xibo\Factory\MediaFactory;
use Xibo\Storage\StorageServiceInterface;
use Xibo\Support\Exception\NotFoundException;
use Xibo\Widget\Render\WidgetDownloader;

class PlayerController extends Base
{
    /** Cada cuántos segundos sondea la página el contenido actual. */
    private const POLL_SECONDS = 4;

    public function __construct(
        private readonly StorageServiceInterface $store,
        private readonly MediaFactory $mediaFactory
    ) {
    }

    /**
     * Página del reproductor (HTML a pantalla completa). Pública.
     */
    public function player(Request $request, Response $response): Response|ResponseInterface
    {
        $params = $this->getSanitizer($request->getParams());
        $group = $params->getString('group', ['default' => 'all']);

        $this->getState()->template = 'DisplaFruit/views/displafruit-player';
        $this->getState()->setData([
            'groupKey'    => $group,
            'pollSeconds' => self::POLL_SECONDS,
        ]);

        return $this->render($request, $response);
    }

    /**
     * Estado actual: qué contenido mostrar ahora mismo para el grupo. Pública (JSON).
     */
    public function state(Request $request, Response $response): Response|ResponseInterface
    {
        $params = $this->getSanitizer($request->getParams());
        $group = $params->getString('group', ['default' => 'all']);
        $now = (int) Carbon::now()->format('U');

        // INNER JOIN con media -> ignora filas huérfanas (media borrado).
        // startAt: un 'range' con inicio futuro no se sirve hasta su fecha "Desde".
        $rows = $this->store->select(
            'SELECT np.nowPlayingId, np.mediaId, np.mediaType, np.name, np.durationSecs, np.publishedAt
               FROM `displafruit_now_playing` np
               INNER JOIN `media` m ON m.mediaId = np.mediaId
              WHERE np.groupKey = :groupKey
                AND (np.startAt = 0 OR np.startAt <= :now)
                AND (np.expiresAt = 0 OR np.expiresAt > :now)
              ORDER BY np.publishedAt DESC
              LIMIT 1',
            ['groupKey' => $group, 'now' => $now]
        );

        if (count($rows) <= 0) {
            return $response->withJson(['playing' => false]);
        }

        $r = $rows[0];

        return $response->withJson([
            'playing'      => true,
            // Cambia cuando cambia el contenido -> el JS sabe que debe refrescar (y no reinicia el vídeo si no cambió).
            'version'      => $r['nowPlayingId'] . '-' . $r['publishedAt'],
            'mediaId'      => (int) $r['mediaId'],
            'type'         => $r['mediaType'],
            'name'         => $r['name'],
            'durationSecs' => (int) $r['durationSecs'],
            'url'          => '/displafruit/player/media/' . (int) $r['mediaId'],
        ]);
    }

    /**
     * Sirve el fichero de un media (imagen/vídeo/PDF). Pública, pero solo si está "en antena".
     */
    public function media(Request $request, Response $response, $id): Response|ResponseInterface
    {
        $mediaId = (int) $id;

        // Solo se sirve contenido referenciado por el player (no biblioteca arbitraria).
        $referenced = $this->store->exists(
            'SELECT nowPlayingId FROM `displafruit_now_playing` WHERE mediaId = :mediaId',
            ['mediaId' => $mediaId]
        );
        if (!$referenced) {
            throw new NotFoundException(__('Contenido no disponible.'));
        }

        // getById sin comprobación de usuario: es contenido público publicado a propósito.
        $media = $this->mediaFactory->getById($mediaId);

        // sendFileMode 'Off' -> PHP sirve el fichero con soporte de rangos (vídeo), sin depender
        // de la configuración de X-Sendfile de Apache. Robusto y autocontenido.
        $downloader = new WidgetDownloader(
            $this->getConfig()->getSetting('LIBRARY_LOCATION'),
            'Off',
            (int) $this->getConfig()->getSetting('DEFAULT_RESIZE_LIMIT', 6000)
        );
        $downloader->useLogger($this->getLog()->getLoggerInterface());

        return $downloader->download($media, $request, $response, $media->getMimeType());
    }
}
