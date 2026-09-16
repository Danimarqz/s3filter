<?php
namespace filter_impronta\external;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');

class renew extends \external_api {
    public static function execute_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'url' => new \external_value(PARAM_RAW, 'Previously signed playlist URL'),
        ]);
    }

    public static function execute(string $url): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['url' => $url]);
        if (!isloggedin() || isguestuser()) {
            throw new \moodle_exception('requireloginerror', 'error');
        }
        return \filter_impronta\renewal::issue($params['url'], (int) $USER->id, true);
    }

    public static function execute_returns(): \external_single_structure {
        return new \external_single_structure([
            'playlistUrl' => new \external_value(PARAM_URL, 'Fresh playlist URL'),
            'eventsUrl' => new \external_value(PARAM_URL, 'Fresh analytics URL'),
            'sessionUrl' => new \external_value(PARAM_URL, 'Fresh heartbeat URL'),
            'expiresAt' => new \external_value(PARAM_INT, 'Unix expiry'),
        ]);
    }
}
