<?php
/*
 * DisplaFruit Signage — migración: tabla del "player web".
 *
 * Guarda, por grupo de pantallas, qué contenido se está mostrando ahora mismo. La escribe
 * PublishController::publishAll() al publicar, y la lee PlayerController (página /displafruit/player)
 * para que las Smart TV — abriendo esa URL en un navegador-kiosko gratuito — muestren el contenido
 * sin necesidad del reproductor de pago de Xibo.
 *
 * Idempotente: no hace nada si la tabla ya existe.
 */

use Phinx\Migration\AbstractMigration;

class DisplafruitNowPlayingMigration extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('displafruit_now_playing')) {
            return;
        }

        $this->table('displafruit_now_playing', ['id' => 'nowPlayingId'])
            // 'all' = todas las pantallas (grupo "DisplaFruit - Todas"); o el nombre de un grupo.
            ->addColumn('groupKey', 'string', ['limit' => 190, 'default' => 'all'])
            ->addColumn('mediaId', 'integer')
            ->addColumn('mediaType', 'string', ['limit' => 50])
            ->addColumn('name', 'string', ['limit' => 255, 'null' => true])
            // Segundos que el contenido debe estar en antena (0 = hasta que se publique otra cosa).
            ->addColumn('durationSecs', 'integer', ['default' => 0])
            ->addColumn('publishedAt', 'integer')
            // Momento (unix) en que deja de mostrarse (0 = nunca). Replica la ventana del schedule.
            ->addColumn('expiresAt', 'integer', ['default' => 0])
            // Un único "ahora reproduciendo" por grupo: el publish hace UPSERT sobre esta clave.
            ->addIndex(['groupKey'], ['unique' => true])
            ->addIndex(['mediaId'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('displafruit_now_playing')) {
            $this->table('displafruit_now_playing')->drop()->save();
        }
    }
}
