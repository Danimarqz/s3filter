<?php
namespace filter_s3video;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../lib.php');

class text_filter extends \moodle_text_filter {
    public function filter($text, array $options = array()) {
        if (strpos($text, '[s3:') === false) {
            return $text;
        }

        static $pattern = '/\[s3:([^\]]+)\]/';

        return preg_replace_callback($pattern, function ($matches) {
            $filename = trim($matches[1]);
            if (empty($filename)) {
                return '';
            }

            $urlhtml = s3video_player($filename);
            return $urlhtml;


        }, $text);
    }
}
