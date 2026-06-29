<?php
/*
 * DisplaFruit Signage — migración: rol "Operador Pantallas".
 *
 * Crea un grupo de usuario con un conjunto reducido de "features" que limita la UI
 * (menús) y el acceso a la API: solo subir contenido, publicar en pantallas y ver
 * estado. El resto (layouts avanzados, datasets, informes, usuarios, configuración…)
 * queda oculto por omisión. Las pantallas de Configuración/Aplicaciones están
 * protegidas por SuperAdminAuth, por lo que también quedan fuera de este rol.
 *
 * Idempotente: si el grupo ya existe, solo actualiza sus features.
 */

use Phinx\Migration\AbstractMigration;

class DisplafruitOperatorRoleMigration extends AbstractMigration
{
    private string $groupName = 'Operador Pantallas';

    /**
     * @return string[]
     */
    private function features(): array
    {
        return [
            // Acceso al panel DisplaFruit (/displafruit/dashboard) y redirección de inicio.
            'displafruit.operator',
            // Biblioteca: ver, subir y modificar contenido.
            'library.view', 'library.add', 'library.modify',
            // Pantallas: ver estado.
            'displays.view',
            // Grupos de display: necesarios para programar y para el grupo "DisplaFruit - Todas".
            'displaygroup.view', 'displaygroup.modify',
            // Programación: ver, añadir, agenda y "programar ahora".
            'schedule.view', 'schedule.add', 'schedule.agenda', 'schedule.now',
            // Campañas: las publicaciones se apoyan en campañas.
            'campaign.view', 'campaign.add', 'campaign.modify',
            // Panel de estado (homepage por defecto del grupo).
            'dashboard.status',
            // Carpetas: necesario para resolver la carpeta de inicio al subir.
            'folder.view',
        ];
    }

    public function up(): void
    {
        $featuresJson = json_encode($this->features());

        $existing = $this->fetchRow(
            "SELECT groupId FROM `group` WHERE `group` = '" . $this->groupName . "' AND IsUserSpecific = 0"
        );

        if (empty($existing)) {
            $this->execute(
                "INSERT INTO `group` "
                . "(`group`, IsUserSpecific, description, libraryQuota, isSystemNotification, isDisplayNotification, "
                . "isDataSetNotification, isLayoutNotification, isLibraryNotification, isReportNotification, "
                . "isScheduleNotification, isCustomNotification, isShownForAddUser, defaultHomepageId, features) "
                . "VALUES ('" . $this->groupName . "', 0, "
                . "'DisplaFruit: rol simplificado para operadores de pantallas (subir contenido, publicar y ver estado).', "
                . "0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 'statusdashboard.view', '" . $featuresJson . "')"
            );
        } else {
            $this->execute(
                "UPDATE `group` SET features = '" . $featuresJson . "', "
                . "defaultHomepageId = 'statusdashboard.view', isShownForAddUser = 1 "
                . "WHERE groupId = " . (int) $existing['groupId']
            );
        }
    }

    public function down(): void
    {
        $this->execute(
            "DELETE FROM `group` WHERE `group` = '" . $this->groupName . "' AND IsUserSpecific = 0"
        );
    }
}
