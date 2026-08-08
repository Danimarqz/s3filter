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

if ($hassiteconfig) {
    $settings = new admin_settingpage('filter_impronta_settings', get_string('settings', 'filter_impronta'));

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

    $ADMIN->add('filtersettings', $settings);
}