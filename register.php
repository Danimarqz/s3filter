<?php
/** Register the current Moodle origin with Impronta. */

require_once(__DIR__ . '/../../config.php');

use filter_impronta\config;
use filter_impronta\impronta_api;

require_login();
require_capability('moodle/site:config', context_system::instance());
require_sesskey();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(new moodle_url('/admin/settings.php', ['section' => 'filtersettingimpronta']));
}

$ok = impronta_api::register_site();
$url = new moodle_url('/admin/settings.php', ['section' => 'filtersettingimpronta']);
if ($ok) {
    // El registro trae y guarda el dominio del media (ajuste oculto mediabase):
    // enseñarlo aquí ahorra ir a la base de datos para comprobar que llegó.
    $msg = get_string('registersuccess', 'filter_impronta');
    $mediabase = (string) config::get('mediabase', '');
    if ($mediabase !== '') {
        $msg .= '<br>' . get_string('registermediabase', 'filter_impronta', s($mediabase));
    }
    redirect($url, $msg, null, \core\output\notification::NOTIFY_SUCCESS);
} else {
    redirect($url, get_string('registerfailure', 'filter_impronta'),
        5, \core\output\notification::NOTIFY_ERROR);
}
