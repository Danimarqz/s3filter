<?php
/**
 * Impronta filter for Moodle.
 *
 * Replaces [imp:Materia/Clase] and the legacy [s3:Materia/Clase] tags (plus
 * their impaudio/s3audio variants) with a video player with per-user
 * watermark, course-based access control, and analytics.
 *
 * Options after a pipe:
 *   [imp:Fisica/Tema 3|subs=es,en]
 *   [impaudio:Fisica/Tema 3]
 *
 * [s3:] se conserva como alias para que el contenido histórico pase por el
 * backend de Impronta sin mantener el proxy CloudFront antiguo.
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

        // El prefijo no captura para que 'audio' siga siendo $1.
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
