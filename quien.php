<?php
/**
 * Traduce los identificadores que aparecen en Impronta al alumno de Moodle.
 *
 * # Por qué hace falta esta página
 *
 * Impronta nunca ve el nombre de tus alumnos. Lo que guarda es un seudónimo
 * -substr(sha256("<id de usuario>|<clave de firma>"), 0, 24)- que solo este
 * Moodle puede deshacer, porque la clave de firma no sale nunca de aquí. Es lo
 * que permite que un proveedor externo lleve la analítica y el registro de
 * accesos sin recibir un solo dato personal.
 *
 * El precio de eso es que los avisos y los paneles hablan de "803cc8a9…" en vez
 * de "María González". Esta página es la que cierra ese círculo, y se ejecuta
 * DENTRO de tu servidor: nadie de fuera puede resolver un seudónimo.
 *
 * # Cómo resuelve
 *
 * El seudónimo es un hash de una sola dirección: no se invierte, se recalcula.
 * Se recorren los usuarios y se compara. Con decenas de miles de cuentas son
 * decenas de miles de sha256, que es trabajo de menos de un segundo, así que no
 * hay tabla de correspondencias que mantener ni que proteger — y no tenerla es
 * justamente lo que hace que un volcado de la base de datos de Impronta no sea
 * un volcado de tus alumnos.
 *
 * @package   filter_impronta
 */

require_once(__DIR__ . '/../../config.php');

use filter_impronta\config;

require_login();
$context = context_system::instance();
// Solo administradores del sitio: deshacer el seudonimato es exactamente la
// operación que el diseño quiere que sea difícil, y quien recibe los avisos de
// Impronta es el administrador.
require_capability('moodle/site:config', $context);

$url = new moodle_url('/filter/impronta/quien.php');
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('whotitle', 'filter_impronta'));
$PAGE->set_heading(get_string('whotitle', 'filter_impronta'));

$entrada = optional_param('ids', '', PARAM_RAW);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('whotitle', 'filter_impronta'));
echo html_writer::tag('p', get_string('whointro', 'filter_impronta'));

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey(),
]);
echo html_writer::tag('textarea', s($entrada), [
    'name' => 'ids', 'rows' => 5, 'cols' => 60,
    'placeholder' => '803cc8a9bc6813259f0383d3',
]);
echo html_writer::empty_tag('br');
echo html_writer::empty_tag('input', [
    'type' => 'submit', 'class' => 'btn btn-primary mt-2',
    'value' => get_string('wholookup', 'filter_impronta'),
]);
echo html_writer::end_tag('form');

if ($entrada !== '') {
    require_sesskey();

    $secret = (string) config::get('secretkey', '');
    if ($secret === '') {
        echo $OUTPUT->notification(get_string('whonosecret', 'filter_impronta'), 'error');
        echo $OUTPUT->footer();
        exit;
    }

    // Se acepta lo que sea que haya copiado el administrador: un aviso pegado
    // entero, una lista con comas, una columna del panel. Cualquier cosa que
    // parezca un seudónimo (24 caracteres hexadecimales) cuenta.
    preg_match_all('/[0-9a-f]{24}/i', $entrada, $m);
    $buscados = array_unique(array_map('strtolower', $m[0]));

    if (empty($buscados)) {
        echo $OUTPUT->notification(get_string('whononefound', 'filter_impronta'), 'warning');
        echo $OUTPUT->footer();
        exit;
    }

    $pendientes = array_flip($buscados);
    $encontrados = [];

    $rs = $DB->get_recordset_select(
        'user',
        'deleted = 0 AND id > 1',
        null,
        'id ASC',
        'id, username, firstname, lastname, email'
    );
    foreach ($rs as $u) {
        $seudonimo = substr(hash('sha256', $u->id . '|' . $secret), 0, 24);
        if (isset($pendientes[$seudonimo])) {
            $encontrados[$seudonimo] = $u;
            unset($pendientes[$seudonimo]);
            if (empty($pendientes)) {
                break;
            }
        }
    }
    $rs->close();

    $tabla = new html_table();
    $tabla->head = [
        get_string('whocolid', 'filter_impronta'),
        get_string('whocoluser', 'filter_impronta'),
        get_string('whocolemail', 'filter_impronta'),
    ];
    foreach ($buscados as $seudonimo) {
        if (isset($encontrados[$seudonimo])) {
            $u = $encontrados[$seudonimo];
            $perfil = new moodle_url('/user/profile.php', ['id' => $u->id]);
            $tabla->data[] = [
                html_writer::tag('code', s($seudonimo)),
                html_writer::link($perfil, fullname($u)),
                s($u->email),
            ];
        } else {
            // Un seudónimo sin dueño es informativo, no un error: suele ser un
            // alumno dado de baja, o una clave de firma distinta de la que
            // estaba activa cuando se generó.
            $tabla->data[] = [
                html_writer::tag('code', s($seudonimo)),
                get_string('whounknown', 'filter_impronta'),
                '',
            ];
        }
    }
    echo html_writer::table($tabla);
}

echo $OUTPUT->footer();
