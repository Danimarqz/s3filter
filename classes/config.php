<?php
/**
 * Ajustes del plugin (filter_impronta/...).
 *
 * @package   filter_impronta
 */

namespace filter_impronta;

defined('MOODLE_INTERNAL') || die();

/**
 * Lectura de la configuración de administración de Moodle.
 */
class config {

    /**
     * Lee un ajuste del plugin.
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $name, $default = null) {
        $value = get_config('filter_impronta', $name);
        return ($value === false) ? $default : $value;
    }

    /**
     * Lee un ajuste obligatorio; revienta si falta.
     *
     * @param string $name
     * @return string
     * @throws \coding_exception
     */
    public static function required(string $name): string {
        $value = self::get($name);
        if ($value === null || $value === '') {
            throw new \coding_exception('Missing required Impronta configuration: filter_impronta/' . $name);
        }
        return (string) $value;
    }

    /**
     * Si los tokens internos se atan a la IP de quien los pide.
     *
     * @return bool
     */
    public static function bind_ip(): bool {
        $raw = self::get('bindip', '0');
        return filter_var((string) $raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
    }

    /**
     * Si un vídeo sin contexto de curso puede reproducirse igualmente.
     *
     * @return bool
     */
    public static function require_course(): bool {
        $raw = self::get('requirecourse', '1');
        return filter_var((string) $raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
    }

    /**
     * Duración de los tokens del plugin, en segundos.
     *
     * Default de 7 h (25200 s): debe sobrevivir a la firma de Impronta (TTL =
     * duración de la clase + 30 min, techo de 6 h), porque la recuperación
     * ante 403 recarga playlist.php con el token ORIGINAL del render. Un
     * único sitio con este cálculo: si el player y las renditions del master
     * usaran TTLs distintos, la recuperación fallaría a mitad de clase y solo
     * en las clases con varias calidades.
     *
     * @return int
     */
    public static function token_ttl(): int {
        return max(60, (int) self::get('tokenttl', 25200));
    }
}
