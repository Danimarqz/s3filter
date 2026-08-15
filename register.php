<?php
/** Register the current Moodle origin with Impronta. */

require_once(__DIR__ . '/../../config.php');

use filter_impronta\impronta_api;

require_login();
require_capability('moodle/site:config', context_system::instance());
require_sesskey();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(new moodle_url('/admin/settings.php', ['section' => 'filter_impronta']));
}

$ok = impronta_api::register_site();
$url = new moodle_url('/admin/settings.php', ['section' => 'filter_impronta']);
redirect($url, get_string($ok ? 'registersuccess' : 'registerfailure', 'filter_impronta'),
    $ok ? null : 5, $ok ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR);
