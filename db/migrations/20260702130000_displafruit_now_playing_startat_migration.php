<?php
/*
 * DisplaFruit Signage — migración: añade startAt a displafruit_now_playing.
 *
 * Necesaria para que el "player web" respete una publicación en modo "rango" con inicio futuro:
 * sin puerta de inicio, el contenido se servía a la Smart TV nada más publicar en vez de esperar
 * a la fecha "Desde". PlayerController filtra ahora por (startAt = 0 OR startAt <= now).
 *
 * Idempotente: no hace nada si la columna ya existe.
 */

use Phinx\Migration\AbstractMigration;

class DisplafruitNowPlayingStartatMigration extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('displafruit_now_playing')) {
            return;
        }
        $table = $this->table('displafruit_now_playing');
        if (!$table->hasColumn('startAt')) {
            // 0 = sin puerta de inicio (mostrar ya); >0 = mostrar solo a partir de ese instante unix.
            $table->addColumn('startAt', 'integer', ['default' => 0, 'after' => 'publishedAt'])->update();
        }
    }

    public function down(): void
    {
        if (!$this->hasTable('displafruit_now_playing')) {
            return;
        }
        $table = $this->table('displafruit_now_playing');
        if ($table->hasColumn('startAt')) {
            $table->removeColumn('startAt')->update();
        }
    }
}
