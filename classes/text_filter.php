<?php
/**
 * S3 Video filter for Moodle.
 *
 * Replaces [s3:Materia/Clase] (and [s3audio:...]) with a video player with
 * per-user watermark, course-based access control, and analytics. Also
 * accepts the legacy [reelo:...] and [reeloaudio:...] tags for migration.
 *
 * Options after a pipe:
 *   [s3:Fisica/Tema 3|subs=es,en]
 *   [s3audio:Fisica/Tema 3]
 *
 * The course is read from the filter's render context and signed into the
 * access token — the browser cannot change it.
 *
 * @package   filter_s3video
 */

namespace filter_s3video;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../lib.php');

class text_filter extends \moodle_text_filter {
    public function filter($text, array $options = array()) {
        if (strpos($text, '[s3') === false && strpos($text, '[reelo') === false) {
            return $text;
        }

        static $pattern = '/\[(?:reelo|s3)(audio)?:([^\]]+)\]/';

        $courseid = \s3video_courseid_from_context($this->context);

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

            return \s3video_player($filename, [
                'audio' => $isaudio,
                'subtitles' => $subtitles,
                'courseid' => $courseid,
            ]);
        }, $text);
    }
}