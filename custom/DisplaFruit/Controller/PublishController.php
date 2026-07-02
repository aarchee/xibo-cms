<?php
/*
 * DisplaFruit Signage — publicación y gestión de contenido del panel del operador.
 *
 * Endpoints (todos registrados en DisplaFruitMiddleware):
 *   POST /api/displafruit/publish-all      (API, OAuth2)  -> publishAll()
 *   POST /displafruit/publish-all          (web, CSRF)    -> publishAll()
 *   POST /displafruit/republish            (web, CSRF)    -> republish()
 *   POST /displafruit/unpublish            (web, CSRF)    -> unpublish()
 *   GET  /displafruit/dashboard/state      (web)          -> state()   (JSON, auto-refresco)
 *   GET  /displafruit/dashboard/history    (web)          -> history() (JSON)
 *   GET  /displafruit/dashboard/thumbnail/{id} (web)      -> thumbnail() (imagen, con ACL)
 *
 * Publicar: sube/crea un Media -> layout a pantalla completa (lógica nativa de Xibo) ->
 * evento programado sobre el grupo de pantallas destino. Cada publicación se registra en
 * displafruit_publication (historial/estado) y, para destinos 'all'/'group', se refleja en
 * displafruit_now_playing (para el player web gratuito).
 *
 * Programación:
 *   - temporary: ahora -> ahora + duración (alta prioridad por defecto; interrumpe).
 *   - permanent: ahora -> Schedule::$DATE_MAX (contenido base, hasta que se pare).
 *   - range:     inicio/fin indicados.
 * En todos los casos syncTimezone=1 (correr en hora del CMS) para evitar el problema de
 * "Fuera de plazo" por desajuste del reloj local de la pantalla.
 */

namespace Xibo\Custom\DisplaFruit\Controller;

use Carbon\Carbon;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Http\Response as Response;
use Slim\Http\ServerRequest as Request;
use Xibo\Controller\Base;
use Xibo\Entity\DisplayGroup;
use Xibo\Entity\Schedule;
use Xibo\Factory\CampaignFactory;
use Xibo\Factory\DayPartFactory;
use Xibo\Factory\DisplayFactory;
use Xibo\Factory\DisplayGroupFactory;
use Xibo\Factory\FolderFactory;
use Xibo\Factory\LayoutFactory;
use Xibo\Factory\MediaFactory;
use Xibo\Factory\ModuleFactory;
use Xibo\Factory\ScheduleFactory;
use Xibo\Service\MediaService;
use Xibo\Storage\StorageServiceInterface;
use Xibo\Support\Exception\InvalidArgumentException;
use Xibo\Support\Exception\LibraryFullException;
use Xibo\Support\Exception\NotFoundException;
use Xibo\Widget\Render\WidgetDownloader;

class PublishController extends Base
{
    /** Nombre del grupo de pantallas que agrupa TODAS las pantallas. */
    private const ALL_DISPLAYS_GROUP = 'DisplaFruit - Todas';

    /** Cuántas publicaciones recientes devuelve el historial. */
    private const HISTORY_LIMIT = 30;

    public function __construct(
        private readonly MediaFactory $mediaFactory,
        private readonly ModuleFactory $moduleFactory,
        private readonly LayoutFactory $layoutFactory,
        private readonly ScheduleFactory $scheduleFactory,
        private readonly DayPartFactory $dayPartFactory,
        private readonly DisplayGroupFactory $displayGroupFactory,
        private readonly DisplayFactory $displayFactory,
        private readonly CampaignFactory $campaignFactory,
        private readonly FolderFactory $folderFactory,
        private readonly MediaService $mediaService,
        private readonly StorageServiceInterface $store
    ) {
    }

    // =====================================================================================
    // Acciones (mutaciones)
    // =====================================================================================

    /**
     * Publicar: subir un archivo y programarlo en el destino elegido.
     */
    public function publishAll(Request $request, Response $response): Response|ResponseInterface
    {
        try {
            $params = $this->getSanitizer($request->getParams());
            $user = $this->getUser();

            // --- 1. Validar el archivo ---
            $uploadedFiles = $request->getUploadedFiles();
            if (empty($uploadedFiles['file'])) {
                return $response->withJson([
                    'success' => false,
                    'message' => __('No se ha proporcionado ningún archivo (campo "file").'),
                ], 400);
            }

            /** @var UploadedFileInterface $uploaded */
            $uploaded = $uploadedFiles['file'];
            if ($uploaded->getError() !== UPLOAD_ERR_OK) {
                return $response->withJson([
                    'success' => false,
                    'message' => __('Error al recibir el archivo subido.'),
                ], 400);
            }

            $sched = $this->parseSchedule($params);
            $name = $params->getString('name');

            // --- 1b. Comprobar cuota de biblioteca (igual que el flujo nativo de subida) ---
            $libraryLimit = ((int) $this->getConfig()->getSetting('LIBRARY_SIZE_LIMIT_KB')) * 1024;
            if ($libraryLimit > 0 && $this->mediaService->setUser($user)->libraryUsage() > $libraryLimit) {
                throw new LibraryFullException(sprintf(
                    __('La biblioteca está llena. Límite: %s K'),
                    $this->getConfig()->getSetting('LIBRARY_SIZE_LIMIT_KB')
                ));
            }
            $user->isQuotaFullByUser(true);

            $libraryFolder = $this->getConfig()->getSetting('LIBRARY_LOCATION');
            MediaService::ensureLibraryExists($libraryFolder);

            // --- 2. Persistir el archivo en LIBRARY_LOCATION/temp/{fileName} (saneado) ---
            $fileName = trim(basename(stripslashes((string) $uploaded->getClientFilename())), ".\x00..\x20");
            if ($fileName === '') {
                $fileName = 'upload';
            }
            $tempPath = rtrim($libraryFolder, '/') . '/temp/' . $fileName;
            $uploaded->moveTo($tempPath);

            // --- 3. Resolver el módulo por extensión y crear el Media ---
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $module = $this->moduleFactory->getByExtension($ext);

            $mediaName = !empty($name) ? $name : pathinfo($fileName, PATHINFO_FILENAME);
            $media = $this->mediaFactory->create($mediaName, $fileName, $module->type, $user->userId);
            $media->duration = $module->fetchDurationOrDefaultFromFile($tempPath);
            $media->enableStat = $this->getConfig()->getSetting('MEDIA_STATS_ENABLED_DEFAULT');
            $media->expires = 0;

            $folderId = $user->homeFolderId;
            $folder = $this->folderFactory->getById($folderId, 0);
            $media->folderId = $folderId;
            $media->permissionsFolderId = $folder->getPermissionFolderIdOrThis();
            $media->save(['isMediaReassigned' => true]);

            // A partir de aquí el media ya está persistido. Si algo falla después, se borra.
            try {
                $target = $this->resolveTarget($params);
                $result = $this->doPublish($media, $module->type, $mediaName, $target, $sched);
            } catch (\Throwable $inner) {
                try {
                    $media->delete();
                } catch (\Throwable $cleanup) {
                    $this->getLog()->error('DisplaFruit publish: fallo al limpiar media huérfano: '
                        . $cleanup->getMessage());
                }
                throw $inner;
            }

            $screensUpdated = $this->countOnlineScreens($target['group']);

            $this->getLog()->audit('Schedule', $result['eventId'], 'DisplaFruit: publish', [
                'mediaId'        => $media->mediaId,
                'layoutId'       => $result['layoutId'],
                'displayGroupId' => $target['group']->displayGroupId,
                'targetType'     => $target['targetType'],
                'scheduleMode'   => $sched['mode'],
            ]);

            return $response->withJson([
                'success'         => true,
                'screens_updated' => $screensUpdated,
                'layout_id'       => $result['layoutId'],
                'publication_id'  => $result['publicationId'],
            ]);
        } catch (\Throwable $e) {
            $this->getLog()->error('DisplaFruit publish error: ' . $e->getMessage());
            return $response->withJson([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Republicar: volver a poner en antena un contenido ya publicado (mismo media).
     */
    public function republish(Request $request, Response $response): Response|ResponseInterface
    {
        try {
            $params = $this->getSanitizer($request->getParams());
            $user = $this->getUser();

            $publicationId = $params->getInt('publicationId');
            if (empty($publicationId)) {
                return $response->withJson(['success' => false, 'message' => __('Falta publicationId.')], 400);
            }

            $pub = $this->getPublication($publicationId);
            if ($pub === null) {
                return $response->withJson(['success' => false, 'message' => __('Publicación no encontrada.')], 404);
            }
            if (!$user->isSuperAdmin() && (int) $pub['userId'] !== $user->userId) {
                return $response->withJson(['success' => false, 'message' => __('No autorizado.')], 403);
            }

            // El media debe seguir existiendo en la biblioteca.
            try {
                $media = $this->mediaFactory->getById((int) $pub['mediaId']);
            } catch (\Throwable $e) {
                return $response->withJson([
                    'success' => false,
                    'message' => __('El contenido ya no existe en la biblioteca.'),
                ], 404);
            }

            $target = $this->resolveTargetFromPublication($pub);
            $sched = $this->scheduleFromPublication($pub);
            $result = $this->doPublish($media, (string) $pub['mediaType'], (string) $pub['name'], $target, $sched);

            $screensUpdated = $this->countOnlineScreens($target['group']);

            return $response->withJson([
                'success'         => true,
                'screens_updated' => $screensUpdated,
                'publication_id'  => $result['publicationId'],
            ]);
        } catch (\Throwable $e) {
            $this->getLog()->error('DisplaFruit republish error: ' . $e->getMessage());
            return $response->withJson(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Parar / quitar una publicación: borra su schedule y limpia el player web.
     */
    public function unpublish(Request $request, Response $response): Response|ResponseInterface
    {
        try {
            $params = $this->getSanitizer($request->getParams());
            $user = $this->getUser();

            $publicationId = $params->getInt('publicationId');
            if (empty($publicationId)) {
                return $response->withJson(['success' => false, 'message' => __('Falta publicationId.')], 400);
            }

            $pub = $this->getPublication($publicationId);
            if ($pub === null) {
                return $response->withJson(['success' => false, 'message' => __('Publicación no encontrada.')], 404);
            }
            if (!$user->isSuperAdmin() && (int) $pub['userId'] !== $user->userId) {
                return $response->withJson(['success' => false, 'message' => __('No autorizado.')], 403);
            }

            // Borrar el schedule (si sigue existiendo).
            if (!empty($pub['eventId'])) {
                try {
                    $schedule = $this->scheduleFactory->getById((int) $pub['eventId']);
                    $schedule->setDisplayNotifyService($this->displayFactory->getDisplayNotifyService());
                    $schedule->setCampaignFactory($this->campaignFactory);
                    $schedule->delete();
                } catch (NotFoundException $e) {
                    // El evento ya no existe: nada que borrar.
                }
            }

            // Marcar la publicación como parada.
            $this->store->update(
                'UPDATE `displafruit_publication` SET status = :status, stoppedAt = :stoppedAt
                 WHERE publicationId = :id',
                ['status' => 'stopped', 'stoppedAt' => (int) Carbon::now()->format('U'), 'id' => $publicationId]
            );

            // Reconciliar el player web para ese grupo: re-apuntar a la publicación activa más
            // reciente (o vaciar si no queda ninguna). Se ejecuta DESPUÉS de marcar 'stopped' esta
            // publicación, así queda excluida del cálculo.
            $groupKey = $this->groupKeyFromPublication($pub);
            if ($groupKey !== null) {
                $this->reconcileNowPlaying($groupKey);
            }

            return $response->withJson(['success' => true]);
        } catch (\Throwable $e) {
            $this->getLog()->error('DisplaFruit unpublish error: ' . $e->getMessage());
            return $response->withJson(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // =====================================================================================
    // Lecturas (JSON para el panel)
    // =====================================================================================

    /**
     * Estado del panel para auto-refresco: pantallas + contenido actual + "en antena ahora".
     */
    public function state(Request $request, Response $response): Response|ResponseInterface
    {
        $now = (int) Carbon::now()->format('U');
        $user = $this->getUser();

        // Auto-sanar la pertenencia del grupo "Todas" (solo si ya existe y falta alguna pantalla).
        try {
            $this->syncAllDisplaysGroupMembership();
        } catch (\Throwable $e) {
            $this->getLog()->error('DisplaFruit state: fallo al sincronizar el grupo: ' . $e->getMessage());
        }

        // Pantallas visibles para el usuario (respeta ACL).
        $displays = $this->displayFactory->query(['display'], []);

        // Publicaciones activas (no paradas, ya empezadas y dentro de ventana).
        // fromDt <= now excluye un 'range' con inicio futuro (aún no está en antena de verdad).
        $pubs = $this->store->select(
            'SELECT p.publicationId, p.userId, p.mediaId, p.mediaType, p.name, p.targetType, p.targetId,
                    p.targetName, p.displayGroupId, p.scheduleMode, p.durationSecs, p.fromDt, p.toDt,
                    p.isPriority, p.publishedAt
               FROM `displafruit_publication` p
               INNER JOIN `media` m ON m.mediaId = p.mediaId
              WHERE p.status = :status
                AND (p.toDt = 0 OR p.toDt > :now)
                AND (p.fromDt = 0 OR p.fromDt <= :now)
              ORDER BY p.isPriority DESC, p.publishedAt DESC',
            ['status' => 'active', 'now' => $now]
        );

        $contentByDisplay = $this->mapDisplaysToContent($pubs);

        $cards = [];
        $online = 0;
        foreach ($displays as $display) {
            if ((int) $display->loggedIn === 1) {
                $online++;
            }
            $cards[] = [
                'displayId'    => (int) $display->displayId,
                'name'         => $display->display,
                'loggedIn'     => (int) $display->loggedIn,
                'authorised'   => (int) $display->licensed,
                'lastAccessed' => !empty($display->lastAccessed) ? (int) $display->lastAccessed : null,
                'current'      => $contentByDisplay[$display->displayId] ?? null,
            ];
        }

        // La lista de gestión "En antena ahora" solo enumera las publicaciones propias (coherente
        // con history()); el mapeo pantalla->contenido de arriba sí usa todas, porque refleja lo que
        // realmente se ve en cada pantalla visible por ACL.
        $nowPlaying = [];
        foreach ($pubs as $p) {
            if (!$user->isSuperAdmin() && (int) $p['userId'] !== $user->userId) {
                continue;
            }
            $nowPlaying[] = [
                'publicationId' => (int) $p['publicationId'],
                'name'          => $p['name'],
                'mediaId'       => (int) $p['mediaId'],
                'type'          => $p['mediaType'],
                'thumbUrl'      => $this->thumbUrl($p['mediaType'], (int) $p['mediaId']),
                'target'        => $this->targetLabel($p),
                'mode'          => $p['scheduleMode'],
                'publishedAt'   => (int) $p['publishedAt'],
                'toDt'          => (int) $p['toDt'],
                'isPriority'    => (int) $p['isPriority'],
            ];
        }

        return $response->withJson([
            'displays'     => $cards,
            'displayCount' => count($cards),
            'onlineCount'  => $online,
            'offlineCount' => count($cards) - $online,
            'nowPlaying'   => $nowPlaying,
            'now'          => $now,
        ]);
    }

    /**
     * Historial de publicaciones recientes (para republicar).
     */
    public function history(Request $request, Response $response): Response|ResponseInterface
    {
        $user = $this->getUser();

        $where = '';
        $paramsSql = [];
        if (!$user->isSuperAdmin()) {
            $where = ' WHERE p.userId = :userId ';
            $paramsSql['userId'] = $user->userId;
        }

        $rows = $this->store->select(
            'SELECT p.publicationId, p.userId, p.mediaId, p.mediaType, p.name, p.targetType,
                    p.targetName, p.scheduleMode, p.durationSecs, p.publishedAt, p.status,
                    CASE WHEN m.mediaId IS NULL THEN 0 ELSE 1 END AS mediaExists
               FROM `displafruit_publication` p
               LEFT JOIN `media` m ON m.mediaId = p.mediaId'
            . $where .
            ' ORDER BY p.publishedAt DESC
              LIMIT ' . self::HISTORY_LIMIT,
            $paramsSql
        );

        $items = [];
        foreach ($rows as $r) {
            $exists = ((int) $r['mediaExists'] === 1);
            $items[] = [
                'publicationId' => (int) $r['publicationId'],
                'name'          => $r['name'],
                'mediaId'       => (int) $r['mediaId'],
                'type'          => $r['mediaType'],
                'thumbUrl'      => $exists ? $this->thumbUrl($r['mediaType'], (int) $r['mediaId']) : null,
                'target'        => $this->targetLabel($r),
                'mode'          => $r['scheduleMode'],
                'publishedAt'   => (int) $r['publishedAt'],
                'status'        => $r['status'],
                'canRepublish'  => $exists,
            ];
        }

        return $response->withJson(['history' => $items]);
    }

    /**
     * Miniatura de un media (solo imágenes; con ACL: solo media publicado por DisplaFruit).
     */
    public function thumbnail(Request $request, Response $response, $id): Response|ResponseInterface
    {
        $mediaId = (int) $id;

        // ACL: un operador solo obtiene miniaturas de media de SUS publicaciones; el super admin, todas.
        $user = $this->getUser();
        if ($user->isSuperAdmin()) {
            $referenced = $this->store->exists(
                'SELECT publicationId FROM `displafruit_publication` WHERE mediaId = :mediaId',
                ['mediaId' => $mediaId]
            );
        } else {
            $referenced = $this->store->exists(
                'SELECT publicationId FROM `displafruit_publication` WHERE mediaId = :mediaId AND userId = :userId',
                ['mediaId' => $mediaId, 'userId' => $user->userId]
            );
        }
        if (!$referenced) {
            throw new NotFoundException(__('Miniatura no disponible.'));
        }

        $media = $this->mediaFactory->getById($mediaId);
        if ($media->mediaType !== 'image') {
            throw new NotFoundException(__('Sin miniatura para este tipo de contenido.'));
        }

        $downloader = new WidgetDownloader(
            $this->getConfig()->getSetting('LIBRARY_LOCATION'),
            'Off',
            (int) $this->getConfig()->getSetting('DEFAULT_RESIZE_LIMIT', 6000)
        );
        $downloader->useLogger($this->getLog()->getLoggerInterface());

        return $downloader->download($media, $request, $response, $media->getMimeType());
    }

    // =====================================================================================
    // Publicación (lógica compartida por publishAll / republish)
    // =====================================================================================

    /**
     * Crear el layout a pantalla completa + el schedule + registrar la publicación.
     * NO borra el media en caso de fallo (lo decide quien llama); sí limpia layout/schedule.
     *
     * @return array{layoutId:int, campaignId:int, eventId:int, publicationId:int}
     */
    private function doPublish($media, string $mediaType, string $mediaName, array $target, array $sched): array
    {
        $user = $this->getUser();
        $fsLayout = null;
        $schedule = null;

        try {
            // Layout a pantalla completa (ya publicado) a partir del Media.
            $fsLayout = $this->layoutFactory->createFullScreenLayout('media', $media->mediaId, 0, '', 0);
            $campaignId = $this->layoutFactory->getCampaignIdFromLayoutHistory($fsLayout->layoutId);

            // Evento de media programado sobre el grupo destino.
            $customDayPart = $this->dayPartFactory->getCustomDayPart();

            $schedule = $this->scheduleFactory->createEmpty();
            $schedule->userId = $user->userId;
            $schedule->eventTypeId = Schedule::$MEDIA_EVENT;
            $schedule->campaignId = $campaignId;
            $schedule->parentCampaignId = $campaignId;
            $schedule->dayPartId = $customDayPart->dayPartId;
            $schedule->isPriority = $sched['isPriority'];
            $schedule->displayOrder = 0;
            // Correr en hora del CMS: evita el problema de "Fuera de plazo" por reloj local de la TV.
            $schedule->syncTimezone = 1;
            $schedule->syncEvent = 0;
            $schedule->isGeoAware = 0;
            $schedule->maxPlaysPerHour = 0;
            $schedule->fromDt = $sched['fromDt'];
            $schedule->toDt = $sched['toDt'];

            $schedule->assignDisplayGroup($target['group']);
            $schedule->setDisplayNotifyService($this->displayFactory->getDisplayNotifyService());
            $schedule->setCampaignFactory($this->campaignFactory);
            $schedule->save();

            $publicationId = $this->recordPublication(
                $media,
                $mediaType,
                $mediaName,
                $target,
                $sched,
                (int) $fsLayout->layoutId,
                (int) $campaignId,
                (int) $schedule->eventId
            );
        } catch (\Throwable $inner) {
            if ($schedule !== null && !empty($schedule->eventId)) {
                try {
                    $schedule->delete();
                } catch (\Throwable $cleanup) {
                    $this->getLog()->error('DisplaFruit publish: fallo al limpiar schedule huérfano: '
                        . $cleanup->getMessage());
                }
            }
            if ($fsLayout !== null) {
                try {
                    $fsLayout->delete();
                } catch (\Throwable $cleanup) {
                    $this->getLog()->error('DisplaFruit publish: fallo al limpiar layout huérfano: '
                        . $cleanup->getMessage());
                }
            }
            throw $inner;
        }

        // Reflejar en el player web (best-effort, solo destinos con groupKey).
        if ($target['groupKey'] !== null) {
            try {
                $this->upsertNowPlaying($target['groupKey'], $media, $mediaType, $mediaName, $sched);
            } catch (\Throwable $e) {
                $this->getLog()->error('DisplaFruit publish: no se pudo actualizar el player web: '
                    . $e->getMessage());
            }
        }

        return [
            'layoutId'      => (int) $fsLayout->layoutId,
            'campaignId'    => (int) $campaignId,
            'eventId'       => (int) $schedule->eventId,
            'publicationId' => $publicationId,
        ];
    }

    /**
     * Traducir los parámetros de programación a fromDt/toDt/isPriority.
     *
     * @return array{mode:string, fromDt:int, toDt:int, durationSecs:int, isPriority:int}
     */
    private function parseSchedule($params): array
    {
        $mode = $params->getString('scheduleMode', ['default' => 'temporary']);
        $urgent = $params->getInt('urgent', ['default' => 0]) === 1;
        $now = (int) Carbon::now()->format('U');

        if ($mode === 'permanent') {
            $fromDt = $now;
            $toDt = Schedule::$DATE_MAX;
            $durationSecs = 0;
        } elseif ($mode === 'range') {
            $fromDt = $this->parseDateTime($params->getString('fromDt'), $now);
            $toDt = $this->parseDateTime($params->getString('toDt'), $now + 3600);
            if ($toDt <= $fromDt) {
                throw new InvalidArgumentException(
                    __('La fecha de fin debe ser posterior a la de inicio.'),
                    'toDt'
                );
            }
            $durationSecs = 0;
        } else {
            $mode = 'temporary';
            $durationSecs = $params->getInt('duration', ['default' => 30]);
            if ($durationSecs <= 0) {
                $durationSecs = 30;
            }
            $fromDt = $now;
            $toDt = $now + $durationSecs;
        }

        // Temporal = interrumpe (prioridad); permanente/rango = base, salvo "urgente".
        $isPriority = ($urgent || $mode === 'temporary') ? 1 : 0;

        return [
            'mode'         => $mode,
            'fromDt'       => $fromDt,
            'toDt'         => $toDt,
            'durationSecs' => $durationSecs,
            'isPriority'   => $isPriority,
        ];
    }

    /**
     * Reconstruir la programación al republicar (reutiliza modo/duración de la publicación).
     *
     * @return array{mode:string, fromDt:int, toDt:int, durationSecs:int, isPriority:int}
     */
    private function scheduleFromPublication(array $pub): array
    {
        $now = (int) Carbon::now()->format('U');
        $mode = ($pub['scheduleMode'] === 'permanent') ? 'permanent' : 'temporary';

        if ($mode === 'permanent') {
            return ['mode' => 'permanent', 'fromDt' => $now, 'toDt' => Schedule::$DATE_MAX,
                'durationSecs' => 0, 'isPriority' => (int) $pub['isPriority']];
        }

        $durationSecs = (int) $pub['durationSecs'] > 0 ? (int) $pub['durationSecs'] : 30;
        return [
            'mode'         => 'temporary',
            'fromDt'       => $now,
            'toDt'         => $now + $durationSecs,
            'durationSecs' => $durationSecs,
            'isPriority'   => 1,
        ];
    }

    private function parseDateTime(?string $value, int $default): int
    {
        if (empty($value)) {
            return $default;
        }
        try {
            return (int) Carbon::parse($value)->format('U');
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * Insertar la fila de historial/estado y devolver su id.
     */
    private function recordPublication(
        $media,
        string $mediaType,
        string $name,
        array $target,
        array $sched,
        int $layoutId,
        int $campaignId,
        int $eventId
    ): int {
        return (int) $this->store->insert(
            'INSERT INTO `displafruit_publication`
                (userId, mediaId, mediaType, name, targetType, targetId, targetName, displayGroupId,
                 eventId, campaignId, layoutId, scheduleMode, durationSecs, fromDt, toDt, isPriority,
                 publishedAt, status)
             VALUES
                (:userId, :mediaId, :mediaType, :name, :targetType, :targetId, :targetName, :displayGroupId,
                 :eventId, :campaignId, :layoutId, :scheduleMode, :durationSecs, :fromDt, :toDt, :isPriority,
                 :publishedAt, :status)',
            [
                'userId'         => $this->getUser()->userId,
                'mediaId'        => $media->mediaId,
                'mediaType'      => $mediaType,
                'name'           => $name,
                'targetType'     => $target['targetType'],
                'targetId'       => $target['targetId'],
                'targetName'     => $target['targetName'],
                'displayGroupId' => $target['group']->displayGroupId,
                'eventId'        => $eventId,
                'campaignId'     => $campaignId,
                'layoutId'       => $layoutId,
                'scheduleMode'   => $sched['mode'],
                'durationSecs'   => $sched['durationSecs'],
                'fromDt'         => $sched['fromDt'],
                'toDt'           => $sched['toDt'],
                'isPriority'     => $sched['isPriority'],
                'publishedAt'    => (int) Carbon::now()->format('U'),
                'status'         => 'active',
            ]
        );
    }

    // =====================================================================================
    // Resolución de destino y grupo "Todas"
    // =====================================================================================

    /**
     * Resolver el destino a partir de los parámetros de la petición.
     *
     * @return array{group:DisplayGroup, targetType:string, targetId:?int, targetName:string, groupKey:?string}
     */
    private function resolveTarget($params): array
    {
        $targetType = $params->getString('targetType', ['default' => 'all']);

        // Compatibilidad con la API antigua (usaba displayGroupId).
        $legacyGroupId = $params->getInt('displayGroupId');
        if ($targetType === 'all' && !empty($legacyGroupId)) {
            $targetType = 'group';
        }

        if ($targetType === 'group') {
            $groupId = !empty($legacyGroupId) ? $legacyGroupId : $params->getInt('targetId');
            // disableUserCheck=false -> ACL: solo grupos sobre los que el usuario tiene permiso.
            $group = $this->displayGroupFactory->getById($groupId, false);
            return [
                'group'      => $group,
                'targetType' => 'group',
                'targetId'   => (int) $group->displayGroupId,
                'targetName' => $group->displayGroup,
                'groupKey'   => $group->displayGroup,
            ];
        }

        if ($targetType === 'display') {
            $display = $this->getDisplayWithAcl($params->getInt('targetId'));
            // Grupo propio de la pantalla (ACL ya comprobada sobre la pantalla).
            $group = $this->displayGroupFactory->getById((int) $display->displayGroupId, true);
            return [
                'group'      => $group,
                'targetType' => 'display',
                'targetId'   => (int) $display->displayId,
                'targetName' => $display->display,
                'groupKey'   => null,
            ];
        }

        // Por defecto: todas las pantallas.
        return [
            'group'      => $this->syncAllDisplaysGroup(),
            'targetType' => 'all',
            'targetId'   => null,
            'targetName' => __('Todas las pantallas'),
            'groupKey'   => 'all',
        ];
    }

    /**
     * Igual que resolveTarget pero a partir de una fila de displafruit_publication (republicar).
     *
     * @return array{group:DisplayGroup, targetType:string, targetId:?int, targetName:string, groupKey:?string}
     */
    private function resolveTargetFromPublication(array $pub): array
    {
        $targetType = $pub['targetType'];

        if ($targetType === 'group') {
            $group = $this->displayGroupFactory->getById((int) $pub['targetId'], false);
            return [
                'group'      => $group,
                'targetType' => 'group',
                'targetId'   => (int) $group->displayGroupId,
                'targetName' => $group->displayGroup,
                'groupKey'   => $group->displayGroup,
            ];
        }

        if ($targetType === 'display') {
            $display = $this->getDisplayWithAcl((int) $pub['targetId']);
            $group = $this->displayGroupFactory->getById((int) $display->displayGroupId, true);
            return [
                'group'      => $group,
                'targetType' => 'display',
                'targetId'   => (int) $display->displayId,
                'targetName' => $display->display,
                'groupKey'   => null,
            ];
        }

        return [
            'group'      => $this->syncAllDisplaysGroup(),
            'targetType' => 'all',
            'targetId'   => null,
            'targetName' => __('Todas las pantallas'),
            'groupKey'   => 'all',
        ];
    }

    /**
     * Obtener una pantalla comprobando el permiso del usuario (ACL). Lanza si no tiene acceso.
     */
    private function getDisplayWithAcl(int $displayId)
    {
        $displays = $this->displayFactory->query(null, ['displayId' => $displayId]);
        if (count($displays) <= 0) {
            throw new NotFoundException(__('No tienes acceso a esa pantalla.'));
        }
        return $displays[0];
    }

    /**
     * Obtener (o crear) el grupo "DisplaFruit - Todas" y asegurar que contiene todas las pantallas.
     */
    private function syncAllDisplaysGroup(): DisplayGroup
    {
        $group = $this->findAllDisplaysGroup();

        if ($group === null) {
            $folder = $this->folderFactory->getById($this->getUser()->homeFolderId, 0);
            $group = $this->displayGroupFactory->create($this->getUser()->userId);
            $group->displayGroup = self::ALL_DISPLAYS_GROUP;
            $group->description = __('Grupo gestionado por DisplaFruit: todas las pantallas.');
            $group->isDynamic = 0;
            $group->folderId = $this->getUser()->homeFolderId;
            $group->permissionsFolderId = $folder->getPermissionFolderIdOrThis();
            $group->save();
        }

        $this->syncMembership($group);
        return $group;
    }

    /**
     * Auto-sanar la pertenencia solo si el grupo ya existe (usado en el poll del panel).
     */
    private function syncAllDisplaysGroupMembership(): void
    {
        $group = $this->findAllDisplaysGroup();
        if ($group !== null) {
            $this->syncMembership($group);
        }
    }

    private function findAllDisplaysGroup(): ?DisplayGroup
    {
        foreach ($this->displayGroupFactory->query(null, ['disableUserCheck' => 1, 'isDisplaySpecific' => 0]) as $g) {
            if ($g->displayGroup === self::ALL_DISPLAYS_GROUP) {
                return $g;
            }
        }
        return null;
    }

    /**
     * Añadir al grupo las pantallas que falten (guarda solo si hubo cambios). No quita ninguna.
     */
    private function syncMembership(DisplayGroup $group): void
    {
        $memberIds = [];
        foreach ($this->displayFactory->query(null, [
            'disableUserCheck' => 1,
            'displayGroupId'   => $group->displayGroupId,
        ]) as $member) {
            $memberIds[(int) $member->displayId] = true;
        }

        $group->load();
        $changed = false;
        foreach ($this->displayFactory->query(null, ['disableUserCheck' => 1]) as $display) {
            if (!isset($memberIds[(int) $display->displayId])) {
                $group->assignDisplay($display);
                $changed = true;
            }
        }

        if ($changed) {
            $group->save([
                'validate'           => false,
                'manageLinks'        => false,
                'manageDisplayLinks' => true,
                'allowNotify'        => false,
                'saveTags'           => false,
            ]);
        }
    }

    // =====================================================================================
    // Player web (displafruit_now_playing) y utilidades
    // =====================================================================================

    /**
     * UPSERT del contenido actual por grupo (lo lee el player web).
     */
    private function upsertNowPlaying(string $groupKey, $media, string $mediaType, string $name, array $sched): void
    {
        $now = (int) Carbon::now()->format('U');

        // El player web muestra una sola pieza por grupo: la vigente. Una publicación en modo 'range'
        // con inicio futuro NO está en antena todavía y no debe sobrescribir (dejar en blanco) el
        // contenido vigente. Los players Xibo reales sí la respetan por su schedule; en el player web
        // se reflejará cuando pase a ser el contenido vigente (o al republicarla).
        if ($sched['mode'] === 'range' && $sched['fromDt'] > $now) {
            return;
        }

        if ($sched['mode'] === 'permanent') {
            $expiresAt = 0;
        } elseif ($sched['mode'] === 'range') {
            $expiresAt = $sched['toDt'];
        } else {
            $expiresAt = $now + $sched['durationSecs'];
        }
        // Vigente ya -> startAt 0. La columna startAt es una salvaguarda de lectura en PlayerController.
        $startAt = 0;

        $this->store->update(
            'INSERT INTO `displafruit_now_playing`
                (groupKey, mediaId, mediaType, name, durationSecs, publishedAt, startAt, expiresAt)
             VALUES (:groupKey, :mediaId, :mediaType, :name, :durationSecs, :publishedAt, :startAt, :expiresAt)
             ON DUPLICATE KEY UPDATE
                mediaId = :u_mediaId, mediaType = :u_mediaType, name = :u_name,
                durationSecs = :u_durationSecs, publishedAt = :u_publishedAt, startAt = :u_startAt,
                expiresAt = :u_expiresAt',
            [
                'groupKey'       => $groupKey,
                'mediaId'        => $media->mediaId,
                'mediaType'      => $mediaType,
                'name'           => $name,
                'durationSecs'   => $sched['durationSecs'],
                'publishedAt'    => $now,
                'startAt'        => $startAt,
                'expiresAt'      => $expiresAt,
                'u_mediaId'      => $media->mediaId,
                'u_mediaType'    => $mediaType,
                'u_name'         => $name,
                'u_durationSecs' => $sched['durationSecs'],
                'u_publishedAt'  => $now,
                'u_startAt'      => $startAt,
                'u_expiresAt'    => $expiresAt,
            ]
        );
    }

    /**
     * Reconciliar la fila del player web tras despublicar: apuntar a la publicación activa más
     * reciente del grupo (o vaciar si no queda ninguna). Evita que parar una publicación borre el
     * contenido de otra publicación activa del mismo media/grupo. Debe llamarse tras marcar la
     * publicación parada como 'stopped'.
     */
    private function reconcileNowPlaying(string $groupKey): void
    {
        $now = (int) Carbon::now()->format('U');

        if ($groupKey === 'all') {
            $rows = $this->store->select(
                'SELECT mediaId, mediaType, name, scheduleMode, durationSecs, fromDt, toDt, publishedAt
                   FROM `displafruit_publication`
                  WHERE status = :status AND targetType = :targetType
                    AND (toDt = 0 OR toDt > :now)
                    AND (fromDt = 0 OR fromDt <= :now)
                  ORDER BY publishedAt DESC
                  LIMIT 1',
                ['status' => 'active', 'targetType' => 'all', 'now' => $now]
            );
        } else {
            $rows = $this->store->select(
                'SELECT mediaId, mediaType, name, scheduleMode, durationSecs, fromDt, toDt, publishedAt
                   FROM `displafruit_publication`
                  WHERE status = :status AND targetType = :targetType AND targetName = :targetName
                    AND (toDt = 0 OR toDt > :now)
                    AND (fromDt = 0 OR fromDt <= :now)
                  ORDER BY publishedAt DESC
                  LIMIT 1',
                ['status' => 'active', 'targetType' => 'group', 'targetName' => $groupKey, 'now' => $now]
            );
        }

        if (count($rows) <= 0) {
            $this->store->update(
                'DELETE FROM `displafruit_now_playing` WHERE groupKey = :groupKey',
                ['groupKey' => $groupKey]
            );
            return;
        }

        $r = $rows[0];
        // La consulta ya solo devuelve contenido vigente (fromDt <= now) -> startAt 0.
        $startAt = 0;
        $expiresAt = ($r['scheduleMode'] === 'permanent') ? 0 : (int) $r['toDt'];

        $this->store->update(
            'INSERT INTO `displafruit_now_playing`
                (groupKey, mediaId, mediaType, name, durationSecs, publishedAt, startAt, expiresAt)
             VALUES (:groupKey, :mediaId, :mediaType, :name, :durationSecs, :publishedAt, :startAt, :expiresAt)
             ON DUPLICATE KEY UPDATE
                mediaId = :u_mediaId, mediaType = :u_mediaType, name = :u_name,
                durationSecs = :u_durationSecs, publishedAt = :u_publishedAt, startAt = :u_startAt,
                expiresAt = :u_expiresAt',
            [
                'groupKey'       => $groupKey,
                'mediaId'        => (int) $r['mediaId'],
                'mediaType'      => $r['mediaType'],
                'name'           => $r['name'],
                'durationSecs'   => (int) $r['durationSecs'],
                'publishedAt'    => (int) $r['publishedAt'],
                'startAt'        => $startAt,
                'expiresAt'      => $expiresAt,
                'u_mediaId'      => (int) $r['mediaId'],
                'u_mediaType'    => $r['mediaType'],
                'u_name'         => $r['name'],
                'u_durationSecs' => (int) $r['durationSecs'],
                'u_publishedAt'  => (int) $r['publishedAt'],
                'u_startAt'      => $startAt,
                'u_expiresAt'    => $expiresAt,
            ]
        );
    }

    private function getPublication(int $publicationId): ?array
    {
        $rows = $this->store->select(
            'SELECT * FROM `displafruit_publication` WHERE publicationId = :id',
            ['id' => $publicationId]
        );
        return count($rows) > 0 ? $rows[0] : null;
    }

    private function groupKeyFromPublication(array $pub): ?string
    {
        if ($pub['targetType'] === 'all') {
            return 'all';
        }
        if ($pub['targetType'] === 'group') {
            return $pub['targetName'];
        }
        return null;
    }

    private function countOnlineScreens(DisplayGroup $group): int
    {
        return count($this->displayFactory->query(null, [
            'displayGroupId'   => $group->displayGroupId,
            'loggedIn'         => 1,
            'authorised'       => 1,
            'disableUserCheck' => 1,
        ]));
    }

    /**
     * Mapear cada pantalla al contenido que ve ahora (prioridad, luego más reciente).
     *
     * @param array $pubs Publicaciones activas ya ordenadas por isPriority DESC, publishedAt DESC.
     * @return array<int, array{name:?string, type:string, mediaId:int, thumbUrl:?string}>
     */
    private function mapDisplaysToContent(array $pubs): array
    {
        $result = [];
        foreach ($pubs as $p) {
            $content = [
                'name'     => $p['name'],
                'type'     => $p['mediaType'],
                'mediaId'  => (int) $p['mediaId'],
                'thumbUrl' => $this->thumbUrl($p['mediaType'], (int) $p['mediaId']),
            ];
            foreach ($this->expandTargetToDisplayIds($p) as $displayId) {
                if (!isset($result[$displayId])) {
                    $result[$displayId] = $content;
                }
            }
        }
        return $result;
    }

    /**
     * IDs de pantalla a las que aplica una publicación.
     *
     * @return int[]
     */
    private function expandTargetToDisplayIds(array $pub): array
    {
        if ($pub['targetType'] === 'display') {
            return [(int) $pub['targetId']];
        }

        $filter = ['disableUserCheck' => 1];
        if ($pub['targetType'] === 'group') {
            $filter['displayGroupId'] = (int) $pub['targetId'];
        }

        $ids = [];
        foreach ($this->displayFactory->query(null, $filter) as $display) {
            $ids[] = (int) $display->displayId;
        }
        return $ids;
    }

    private function thumbUrl(string $mediaType, int $mediaId): ?string
    {
        return $mediaType === 'image' ? ('/displafruit/dashboard/thumbnail/' . $mediaId) : null;
    }

    private function targetLabel(array $pub): string
    {
        if ($pub['targetType'] === 'all') {
            return __('Todas las pantallas');
        }
        if ($pub['targetType'] === 'group') {
            return sprintf(__('Grupo: %s'), $pub['targetName']);
        }
        return sprintf(__('Pantalla: %s'), $pub['targetName']);
    }
}
