<?php
/**
 * Etiqueta del watermark por usuario.
 *
 * @package   filter_impronta
 */

namespace filter_impronta;

defined('MOODLE_INTERNAL') || die();

/**
 * Construye el texto que el reproductor quema sobre el vídeo.
 */
class watermark {

    /**
     * Campos del usuario que la plantilla del watermark puede usar entre llaves.
     *
     * Es una lista blanca a propósito, NO "cualquier propiedad de $USER": ese
     * objeto lleva también el hash de la contraseña, el sesskey y datos de
     * sesión, y la plantilla la escribe un administrador de Moodle pero el
     * resultado se pinta en el navegador de cada alumno. Los campos de perfil
     * personalizados (profile_field_XXX) se resuelven aparte, ya validados por
     * el propio Moodle.
     */
    const FIELDS = [
        'firstname', 'lastname', 'fullname', 'email', 'username', 'idnumber',
        'alternatename', 'middlename', 'city', 'country', 'institution',
        'department', 'phone1', 'phone2',
    ];

    /**
     * Construye la etiqueta a partir de la plantilla configurada.
     *
     * La plantilla (ajuste `watermarktemplate`) usa tokens entre llaves, y todo
     * lo demás se mantiene literal:
     *
     *   - "{firstname}", "{lastname}", "{email}"... -> self::FIELDS
     *   - "{fullname}"            -> fullname($USER), respeta el formato del sitio
     *   - "{profile_field_XXX}"   -> campo de perfil personalizado XXX
     *
     * Ejemplos:
     *   "{firstname} - {profile_field_dni}" -> "Juan - 12345678A"
     *   "{fullname} · {email}"              -> "Juan Pérez · juan@ejemplo.com"
     *
     * Los tokens sueltos "name" y "dni" (sin llaves) siguen funcionando: es el
     * formato que guardaban las instalaciones anteriores a las llaves, y romperlo
     * dejaría el texto literal "name - dni" de watermark sin avisar. Un token
     * desconocido se deja tal cual, para que el administrador vea la errata.
     *
     * @param \stdClass $user
     * @return string
     */
    public static function label(\stdClass $user): string {
        global $CFG;

        $template = trim((string) config::get('watermarktemplate', '{fullname} - {idnumber}'));
        if ($template === '') {
            return '';
        }

        // Carga los campos de perfil personalizados si la plantilla los usa.
        if (stripos($template, 'profile_field_') !== false) {
            require_once($CFG->dirroot . '/user/profile/lib.php');
            if (function_exists('profile_load_data')) {
                profile_load_data($user);
            }
        }

        // Un token es "{loquesea}" o, por compatibilidad, "name"/"dni" sueltos.
        $result = preg_replace_callback(
            '/\{([A-Za-z0-9_]+)\}|\b(name|dni)\b/',
            function ($m) use ($user) {
                $token = $m[1] !== '' ? $m[1] : $m[2];
                $value = self::field($user, $token);
                // Token desconocido: se deja literal para que se vea la errata.
                return $value ?? $m[0];
            },
            $template
        );

        // Junta los espacios que dejan los tokens vacíos ("name -  " -> "name").
        $result = trim(preg_replace('/\s+/', ' ', (string) $result));
        return $result;
    }

    /**
     * Color del watermark, listo para meter en una hoja de estilos.
     *
     * Valida la forma en vez de confiar en el ajuste: el valor acaba dentro de
     * un bloque <style>, así que un valor libre permitiría cerrar la regla e
     * inyectar CSS arbitrario en la página. Solo hexadecimal (#rgb, #rrggbb o
     * con alfa), que es lo que produce el selector de color de Moodle;
     * cualquier otra cosa cae al blanco de siempre.
     *
     * @return string
     */
    public static function color(): string {
        $color = trim((string) config::get('watermarkcolor', '#ffffff'));
        if (preg_match('/^#(?:[0-9A-Fa-f]{3,4}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})$/', $color)) {
            return $color;
        }
        return '#ffffff';
    }

    /**
     * Resuelve un token de la plantilla contra los datos del usuario.
     *
     * Devuelve null si el token no está permitido — que no es lo mismo que un
     * campo permitido pero vacío (cadena vacía): el primero se deja literal en
     * la plantilla, el segundo desaparece del watermark.
     *
     * No se aplica format_string() a los valores: el watermark los escribe con
     * textContent, así que no hay HTML que filtrar y pasarlos por ahí solo
     * conseguiría que un apellido como "O'Brien & Sons" saliera con entidades.
     * Lo que sí importa es cómo se inyectan en el <script> — ver el json_encode
     * con JSON_HEX_TAG de player::extras().
     *
     * @param \stdClass $user
     * @param string $token nombre del token, ya sin llaves
     * @return string|null null si el token no existe
     */
    public static function field(\stdClass $user, string $token): ?string {
        // Alias heredados de las plantillas sin llaves.
        if ($token === 'name') {
            $token = 'fullname';
        } else if ($token === 'dni') {
            $token = 'idnumber';
        }

        if ($token === 'fullname') {
            return (string) fullname($user);
        }
        if (in_array($token, self::FIELDS, true)) {
            return (string) ($user->{$token} ?? '');
        }
        if (preg_match('/^profile_field_[A-Za-z0-9_]+$/', $token)) {
            return (string) ($user->{$token} ?? '');
        }

        return null;
    }
}
