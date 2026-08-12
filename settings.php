<?php
/**
 * Settings for the Impronta filter.
 *
 * The Impronta backend URL is a class constant (\filter_impronta\impronta_api::URL),
 * deliberately NOT editable here. Only the tenant credential, the local
 * signing secret, and the watermark template are configured from Moodle.
 *
 * @package   filter_impronta
 */

defined('MOODLE_INTERNAL') || die();

use filter_impronta\impronta_api;

// Moodle incluye este fichero al construir el árbol de administración, y lo
// hace SIN cargar ningún lib.php del plugin. De ahí que todo lo que use este
// fichero tenga que ser una clase de classes/, que sí se autocarga: cuando
// esto dependía de una constante de lib.php, la excepción no se quedaba en la
// página de ajustes, reventaba cualquier página que construyera ese árbol —o
// sea, el sitio entero. Comprobado en producción de la peor manera posible el
// 2026-08-06.

// $settings YA existe y YA se añade al árbol: lo crea y lo cuelga
// core\plugininfo\filter::load_settings(), que es quien incluye este fichero.
// Aquí solo se rellena. Cuando esto creaba su propia página y la añadía con
// $ADMIN->add(), el mismo objeto entraba dos veces y los ajustes salían
// duplicados en la lista de Filtros.
if ($hassiteconfig) {
    // Bloque informativo, lo primero que se ve. Quien abre esta página suele
    // haber heredado el plugin de otra persona y no sabe qué hay al otro lado;
    // los enlaces son la respuesta a eso, y el de "quién es este alumno" es la
    // herramienta que hace falta cuando llega un aviso.
    //
    // moodle_url y no una cadena a pelo: el sitio puede estar en un subdirectorio
    // y un enlace absoluto a /filter/... daría 404 justo ahí.
    $settings->add(new admin_setting_heading(
        'filter_impronta/about',
        get_string('aboutheading', 'filter_impronta'),
        get_string(
            'aboutdesc',
            'filter_impronta',
            (new moodle_url('/filter/impronta/quien.php'))->out(false)
        )
    ));

    $settings->add(new admin_setting_heading(
        'filter_impronta/backend',
        get_string('backendheading', 'filter_impronta'),
        get_string('backenddesc', 'filter_impronta', impronta_api::URL)
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'filter_impronta/apikey',
        get_string('apikey', 'filter_impronta'),
        get_string('apikeydesc', 'filter_impronta'),
        ''
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'filter_impronta/secretkey',
        get_string('secretkey', 'filter_impronta'),
        get_string('secretkeydesc', 'filter_impronta'),
        ''
    ));

    $settings->add(new admin_setting_heading(
        'filter_impronta/watermark',
        get_string('watermarkheading', 'filter_impronta'),
        get_string('watermarkdesc', 'filter_impronta')
    ));

    $settings->add(new admin_setting_configtext(
        'filter_impronta/watermarktemplate',
        get_string('watermarktemplate', 'filter_impronta'),
        get_string('watermarktemplatedesc', 'filter_impronta'),
        '{fullname} - {idnumber}',
        PARAM_RAW,
        60
    ));

    $settings->add(new admin_setting_configtext(
        'filter_impronta/mobileusers',
        get_string('mobileusers', 'filter_impronta'),
        get_string('mobileusersdesc', 'filter_impronta'),
        '',
        PARAM_RAW,
        40
    ));

    $settings->add(new admin_setting_configcolourpicker(
        'filter_impronta/watermarkcolor',
        get_string('watermarkcolor', 'filter_impronta'),
        get_string('watermarkcolordesc', 'filter_impronta'),
        '#ffffff'
    ));

    $settings->add(new admin_setting_heading(
        'filter_impronta/access',
        get_string('accessheading', 'filter_impronta'),
        get_string('accessdesc', 'filter_impronta')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'filter_impronta/requirecourse',
        get_string('requirecourse', 'filter_impronta'),
        get_string('requirecoursedesc', 'filter_impronta'),
        '1'
    ));

    $settings->add(new admin_setting_configcheckbox(
        'filter_impronta/bindip',
        get_string('bindip', 'filter_impronta'),
        get_string('bindipdesc', 'filter_impronta'),
        '0'
    ));

    $settings->add(new admin_setting_configtext(
        'filter_impronta/tokenttl',
        get_string('tokenttl', 'filter_impronta'),
        get_string('tokenttldesc', 'filter_impronta'),
        25200,
        PARAM_INT,
        10
    ));
}