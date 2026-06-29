<?php
/*
 * DisplaFruit Signage — publicación rápida en todas las pantallas.
 *
 * Endpoint: POST /api/displafruit/publish-all  (API, OAuth2)
 *           POST /displafruit/publish-all       (web, sesión + CSRF, usado por el panel)
 *
 * Recibe un archivo (imagen / vídeo / PDF), lo sube a la biblioteca, construye un
 * layout a pantalla completa (reutilizando la lógica nativa de Xibo, que ya publica
 * y genera el XLF) y lo programa como evento de ALTA PRIORIDAD, empezando ahora,
 * sobre el grupo de pantallas "DisplaFruit - Todas" (todas las pantallas).
 *
 * Body (multipart/form-data):
 *   - file:            archivo (obligatorio)
 *   - duration:        segundos que el evento estará en antena (opcional, por defecto 30)
 *   - name:            nombre descriptivo del contenido (opcional)
 *   - displayGroupId:  destino alternativo (opcional; por defecto, todas las pantallas)
 *
 * Respuesta: { "success": true, "screens_updated": N, "layout_id": X }
 */

namespace Xibo\Custom\DisplaFruit\Controller;

use Carbon\Carbon;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Http\Response as Response;
use Slim\Http\ServerRequest as Request;
use Xibo\Controller\Base;
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
use Xibo\Support\Exception\LibraryFullException;

class PublishController extends Base
{
    /** Nombre del grupo de pantallas que agrupa TODAS las pantallas. */
    private const ALL_DISPLAYS_GROUP = 'DisplaFruit - Todas';

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
        private readonly MediaService $mediaService
    ) {
    }

    /**
     * Publicar en todas las pantallas.
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

            $durationSecs = $params->getInt('duration', ['default' => 30]);
            if ($durationSecs <= 0) {
                $durationSecs = 30;
            }
            $name = $params->getString('name');

            // --- 1b. Comprobar cuota de biblioteca (igual que el flujo nativo de subida) ---
            // Cuota global del CMS.
            $libraryLimit = ((int) $this->getConfig()->getSetting('LIBRARY_SIZE_LIMIT_KB')) * 1024;
            if ($libraryLimit > 0 && $this->mediaService->setUser($user)->libraryUsage() > $libraryLimit) {
                throw new LibraryFullException(sprintf(
                    __('La biblioteca está llena. Límite: %s K'),
                    $this->getConfig()->getSetting('LIBRARY_SIZE_LIMIT_KB')
                ));
            }
            // Cuota por usuario (lanza LibraryFullException si se supera).
            $user->isQuotaFullByUser(true);

            $libraryFolder = $this->getConfig()->getSetting('LIBRARY_LOCATION');
            MediaService::ensureLibraryExists($libraryFolder);

            // --- 2. Persistir el archivo en LIBRARY_LOCATION/temp/{fileName} ---
            // Sanear el nombre del cliente: quitar info de ruta y caracteres peligrosos
            // (mismo saneado que el core, BlueImpUploadHandler) para evitar path traversal.
            $fileName = trim(basename(stripslashes((string) $uploaded->getClientFilename())), ".\x00..\x20");
            if ($fileName === '') {
                $fileName = 'upload';
            }
            $tempPath = rtrim($libraryFolder, '/') . '/temp/' . $fileName;
            $uploaded->moveTo($tempPath);

            // --- 3. Resolver el módulo por extensión y crear el Media ---
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $module = $this->moduleFactory->getByExtension($ext); // lanza NotFoundException si no hay módulo

            $mediaName = !empty($name) ? $name : pathinfo($fileName, PATHINFO_FILENAME);
            $media = $this->mediaFactory->create($mediaName, $fileName, $module->type, $user->userId);
            $media->duration = $module->fetchDurationOrDefaultFromFile($tempPath);
            $media->enableStat = $this->getConfig()->getSetting('MEDIA_STATS_ENABLED_DEFAULT');
            $media->expires = 0;

            $folderId = $user->homeFolderId;
            $folder = $this->folderFactory->getById($folderId, 0);
            $media->folderId = $folderId;
            $media->permissionsFolderId = $folder->getPermissionFolderIdOrThis();
            // isMediaReassigned: si el usuario ya tiene un media con ese nombre, el core
            // lo renombra automáticamente en vez de lanzar DuplicateEntityException (500).
            $media->save(['isMediaReassigned' => true]); // mueve temp/{fileName} -> {mediaId}.{ext}

            // --- 4. Layout a pantalla completa (ya publicado) a partir del Media ---
            $fsLayout = $this->layoutFactory->createFullScreenLayout(
                'media',
                $media->mediaId,
                0,   // resolutionId 0 => ajuste al tamaño del media
                '',  // backgroundColor '' => #000000
                0    // layoutDuration 0 => duración propia del media / módulo
            );
            $campaignId = $this->layoutFactory->getCampaignIdFromLayoutHistory($fsLayout->layoutId);

            // --- 5. Resolver el grupo de pantallas destino (todas, por defecto) ---
            $displayGroup = $this->resolveTargetGroup($params);

            // --- 6. Programar evento de media de ALTA PRIORIDAD: ahora -> ahora + duración ---
            $customDayPart = $this->dayPartFactory->getCustomDayPart();

            $schedule = $this->scheduleFactory->createEmpty();
            $schedule->userId = $user->userId;
            $schedule->eventTypeId = Schedule::$MEDIA_EVENT;
            $schedule->campaignId = $campaignId;
            $schedule->parentCampaignId = $campaignId;
            $schedule->dayPartId = $customDayPart->dayPartId;
            $schedule->isPriority = 1;
            $schedule->displayOrder = 0;
            $schedule->syncTimezone = 0;
            $schedule->syncEvent = 0;
            $schedule->isGeoAware = 0;
            $schedule->maxPlaysPerHour = 0;
            $schedule->fromDt = Carbon::now()->format('U');
            $schedule->toDt = Carbon::now()->addSeconds($durationSecs)->format('U');

            $schedule->assignDisplayGroup($displayGroup);
            $schedule->setDisplayNotifyService($this->displayFactory->getDisplayNotifyService());
            $schedule->setCampaignFactory($this->campaignFactory);
            $schedule->save();

            // --- 7. Contar pantallas activas (online) en el grupo ---
            $screensUpdated = count($this->displayFactory->query(null, [
                'displayGroupId'  => $displayGroup->displayGroupId,
                'loggedIn'        => 1,
                'authorised'      => 1,
                'disableUserCheck' => 1, // contar TODAS las pantallas del grupo, no solo las visibles por ACL del operador
            ]));

            $this->getLog()->audit('Schedule', $schedule->eventId ?? 0, 'DisplaFruit: publish-all', [
                'mediaId'        => $media->mediaId,
                'layoutId'       => $fsLayout->layoutId,
                'displayGroupId' => $displayGroup->displayGroupId,
                'durationSecs'   => $durationSecs,
            ]);

            return $response->withJson([
                'success'         => true,
                'screens_updated' => $screensUpdated,
                'layout_id'       => (int) $fsLayout->layoutId,
            ]);
        } catch (\Throwable $e) {
            $this->getLog()->error('DisplaFruit publish-all error: ' . $e->getMessage());
            return $response->withJson([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Resolver el grupo de pantallas destino. Si se pasa displayGroupId se usa ese;
     * en caso contrario se obtiene (o se crea) el grupo "DisplaFruit - Todas" y se
     * sincroniza su pertenencia con todas las pantallas existentes.
     */
    private function resolveTargetGroup($params)
    {
        $displayGroupId = $params->getInt('displayGroupId');
        if (!empty($displayGroupId)) {
            return $this->displayGroupFactory->getById($displayGroupId);
        }

        // Buscar el grupo por nombre entre los grupos no específicos de display.
        $group = null;
        foreach ($this->displayGroupFactory->query(null, ['disableUserCheck' => 1, 'isDisplaySpecific' => 0]) as $g) {
            if ($g->displayGroup === self::ALL_DISPLAYS_GROUP) {
                $group = $g;
                break;
            }
        }

        // Crear el grupo si no existe. Se ubica en la carpeta de inicio del usuario
        // (no en la raíz) para evitar comprobaciones de permiso sobre la carpeta raíz.
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

        // Sincronizar la pertenencia: todas las pantallas pasan a estar en el grupo.
        $group->load();
        foreach ($this->displayFactory->query(null, ['disableUserCheck' => 1]) as $display) {
            $group->assignDisplay($display);
        }
        $group->save([
            'validate'           => false,
            'manageLinks'        => false,
            'manageDisplayLinks' => true,
            'allowNotify'        => false,
            'saveTags'           => false,
        ]);

        return $group;
    }
}
