<?php
namespace filter_s3video;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../lib.php');

class text_filter extends \moodle_text_filter {
    public function filter($text, array $options = array()) {
        if (strpos($text, '[s3:') === false && strpos($text, '[s3audio:') === false) {
            return $text;
        }

        static $pattern = '/\[s3(audio)?:([^\]]+)\]/';

        return preg_replace_callback($pattern, function ($matches) {
            $isaudio = $matches[1] === 'audio';
            // Otros filtros (autolink) pueden inyectar <a> dentro del nombre;
            // strip_tags recupera el texto original.
            $filename = trim(strip_tags($matches[2]));
            if (empty($filename)) {
                return '';
            }

            return s3video_player($filename, ['audio' => $isaudio]);
        }, $text);
    }
}
