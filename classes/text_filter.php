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
 * # [s3:] también, y por qué
 *
 * El prefijo del filtro anterior se acepta como sinónimo exacto de [imp:]
 * ([s3audio:] igual que [impaudio:]). El payload es el mismo Materia/Clase, así
 * que solo cambia el nombre.
 *
 * No es nostalgia: quien escribe estos tags en los cursos es el programa de
 * ingesta, y emite [s3:]. Renombrar el prefijo obliga a tocar el generador Y a
 * pasar un UPDATE sobre el contenido de los cursos ya publicados —877 tags en
 * OpositaTCAE a 2026-08-09—, para no ganar nada que se vea desde fuera.
 *
 * Lo que sí hay que tener presente: mientras este filtro acepte [s3:], el
 * filtro viejo filter_s3video TIENE que estar desactivado. Los dos responden al
 * mismo prefijo, y el que corra primero se lleva el tag.
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
        if (strpos($text, '[imp') === false && strpos($text, '[s3') === false) {
            return $text;
        }

        // El prefijo va en grupo sin captura para que 'audio' siga siendo $1.
        static $pattern = '/\[(?:imp|s3)(audio)?:([^\]]+)\]/';

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