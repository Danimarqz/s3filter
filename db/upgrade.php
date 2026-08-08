<?php
/**
 * Upgrade steps for the Impronta filter.
 *
 * @package   filter_impronta
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Execute filter_impronta upgrades from the given version.
 *
 * @param int $oldversion the version we are upgrading from
 * @return bool
 */
function xmldb_filter_impronta_upgrade($oldversion) {
    if ($oldversion < 2026080502) {
        // Plan TTL corto, fase 0.2 — invariante de tokenttl.
        //
        // La recuperación ante 403 recarga playlist.php con el token
        // ORIGINAL del render: para que se complete, el token debe sobrevivir
        // a la firma de CloudFront de Impronta (TTL = duración de la clase + 30
        // min, techo de 6 h = 21600 s). El default del ajuste subió de 1800 a
        // 25200 (7 h) en settings.php, pero cambiar el default no toca el
        // valor ya guardado en la tabla config: aquí se sube en los sitios
        // instalados. Solo si está estrictamente por debajo del techo de la
        // firma — una configuración manual de 22000 ya cumple la invariante y
        // se respeta.
        $current = get_config('filter_impronta', 'tokenttl');
        if ($current !== false && (int) $current > 0 && (int) $current < 21600) {
            set_config('tokenttl', 25200, 'filter_impronta');
        }

        upgrade_plugin_savepoint(true, 2026080502, 'filter', 'impronta');
    }

    return true;
}
