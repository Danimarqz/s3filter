<?php
/**
 * Impronta filter for Moodle.
 *
 * Replaces [imp:Materia/Clase] (and [impaudio:...]) with a video player with
 * per-user watermark, course-based access control, and analytics.
 *
 * Options after a pipe:
 *   [imp:Fisica/Tema 3|subs=es,en]
 *   [impaudio:Fisica/Tema 3]
 *
 * # [s3:] YA NO, por ahora
 *
 * Este filtro aceptaba [s3:] y [s3audio:] como sinónimos exactos, porque quien
 * escribe los tags en los cursos es el programa de ingesta y emite [s3:]. Ahora
 * solo se reconoce [imp:].
 *
 * Lo que eso significa, para que no sorprenda: los tags [s3:] que ya están
 * publicados dejan de renderizarse y se ven como TEXTO LITERAL en el curso —eran
 * 877 en OpositaTCAE a 2026-08-09—. Volver atrás es devolver 'imp' a un grupo
 * (?:imp|s3) aquí y en el descarte rápido de filter(); nada más.
 *
 * Para que se sigan viendo hay que, o pasar un UPDATE sobre el contenido de los
 * cursos, o hacer que la ingesta emita [imp:] y regenerar. Mientras no pase una
 * de las dos, [s3:] no lo pinta NADIE... salvo que se reactive el filtro viejo
 * filter_s3video, que responde a ese mismo prefijo: eso es la vía de escape si
 * hace falta, y también la forma de acabar con los dos filtros compitiendo por
 * el mismo tag, así que si se reactiva, que sea a propósito.
 *
 * [s3dev:] y [reelo:] no se aceptan: no queda ninguno en ningún sitio.
 *
 * The course is read from the filter's render context and signed into the
 * access token — the browser cannot change it.
 *
 * @package   filter_impronta
 */

namespace filter_impronta;

defined('MOODLE_INTERNAL') || die();

class text_filter extends \moodle_text_filter {
    public function filter($text, array $options = array()) {
        // Descarte rápido: esto corre sobre CADA texto que Moodle renderiza en
        // el sitio, así que la ruta que no encuentra nada tiene que ser barata.

        //         if (strpos($text, '[imp') === false && strpos($text, '[s3') === false) {
        //     return $text;
        // }

        // // El prefijo va en grupo sin captura para que 'audio' siga siendo $1.
        // static $pattern = '/\[(?:imp|s3)(audio)?:([^\]]+)\]/';

        if (strpos($text, '[imp') === false) {
            return $text;
        }

        // 'audio' sigue siendo $1 y el payload $2, como cuando el prefijo era un
        // grupo (?:imp|s3): el resto del callback no se entera del cambio.
        static $pattern = '/\[imp(audio)?:([^\]]+)\]/';

        $courseid = access::courseid_from_context($this->context);

        return preg_replace_callback($pattern, function ($matches) use ($courseid) {
            $isaudio = $matches[1] === 'audio';
            $raw = trim(strip_tags($matches[2]));
            if ($raw === '') {
                return '';
            }

            $parts = explode('|', $raw);
            $filename = trim(array_shift($parts));
            if ($filename === '') {
                return '';
            }

            $subtitles = [];
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part === '' || strpos($part, '=') === false) {
                    continue;
                }
                [$optkey, $optvalue] = array_map('trim', explode('=', $part, 2));
                if (strcasecmp($optkey, 'subs') !== 0) {
                    continue;
                }
                foreach (explode(',', $optvalue) as $lang) {
                    $lang = strtolower(trim($lang));
                    if (preg_match('/^[a-z0-9\-]{2,5}$/', $lang)) {
                        $subtitles[] = $lang;
                    }
                }
            }

            return player::render($filename, [
                'audio' => $isaudio,
                'subtitles' => $subtitles,
                'courseid' => $courseid,
            ]);
        }, $text);
    }
}