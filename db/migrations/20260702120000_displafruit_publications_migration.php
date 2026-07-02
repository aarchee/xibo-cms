<?php
/*
 * DisplaFruit Signage — migración: historial/estado de publicaciones del panel del operador.
 *
 * Registra cada publicación hecha desde el panel (/displafruit/dashboard) para poder:
 *   - mostrar "en antena ahora" por destino (todas / grupo / pantalla),
 *   - listar el historial y republicar con un clic,
 *   - parar (despublicar) una publicación en marcha (borra su schedule).
 *
 * Tabla aislada (sin FKs a tablas core) para no complicar merges de upstream. La escribe
 * PublishController; la lee el panel. Columnas en camelCase por coherencia con
 * displafruit_now_playing. Idempotente: no hace nada si la tabla ya existe.
 */

use Phinx\Migration\AbstractMigration;

class DisplafruitPublicationsMigration extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('displafruit_publication')) {
            return;
        }

        $this->table('displafruit_publication', ['id' => 'publicationId'])
            ->addColumn('userId', 'integer')
            ->addColumn('mediaId', 'integer')
            ->addColumn('mediaType', 'string', ['limit' => 50])
            ->addColumn('name', 'string', ['limit' => 255, 'null' => true])
            // Destino de la publicación: 'all' (todas), 'group' (un grupo), 'display' (una pantalla).
            ->addColumn('targetType', 'string', ['limit' => 20, 'default' => 'all'])
            ->addColumn('targetId', 'integer', ['null' => true])
            ->addColumn('targetName', 'string', ['limit' => 255, 'null' => true])
            // Grupo de pantallas realmente usado para programar (para 'display' es su grupo propio).
            ->addColumn('displayGroupId', 'integer', ['null' => true])
            // Enlaces al objeto de Xibo creado (para despublicar/republicar).
            ->addColumn('eventId', 'integer', ['null' => true])
            ->addColumn('campaignId', 'integer', ['null' => true])
            ->addColumn('layoutId', 'integer', ['null' => true])
            // Programación: 'temporary' (ahora+duración), 'permanent' (hasta que se quite), 'range' (inicio/fin).
            ->addColumn('scheduleMode', 'string', ['limit' => 20, 'default' => 'temporary'])
            ->addColumn('durationSecs', 'integer', ['default' => 0])
            ->addColumn('fromDt', 'integer', ['default' => 0])
            ->addColumn('toDt', 'integer', ['default' => 0])
            // 1 = interrumpe (alta prioridad); 0 = contenido base.
            ->addColumn('isPriority', 'integer', ['default' => 0])
            ->addColumn('publishedAt', 'integer')
            ->addColumn('stoppedAt', 'integer', ['null' => true])
            // 'active' = en marcha; 'stopped' = parada por el operador.
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'active'])
            ->addIndex(['status'])
            ->addIndex(['publishedAt'])
            ->addIndex(['displayGroupId'])
            ->addIndex(['mediaId'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('displafruit_publication')) {
            $this->table('displafruit_publication')->drop()->save();
        }
    }
}
