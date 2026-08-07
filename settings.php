<?php
/**
 * Settings for the S3 Video filter.
 *
 * The Reelo backend URL is a class constant (\filter_s3video\reelo_api::URL),
 * deliberately NOT editable here. Only the tenant credential, the local
 * signing secret, and the watermark template are configured from Moodle.
 *
 * @package   filter_s3video
 */

defined('MOODLE_INTERNAL') || die();

use filter_s3video\reelo_api;

// Moodle incluye este fichero al construir el árbol de administración, y lo
// hace SIN cargar ningún lib.php del plugin. De ahí que todo lo que use este
// fichero tenga que ser una clase de classes/, que sí se autocarga: cuando
// esto dependía de una constante de lib.php, la excepción no se quedaba en la
// página de ajustes, reventaba cualquier página que construyera ese árbol —o
// sea, el sitio entero. Comprobado en producción de la peor manera posible el
// 2026-08-06.

if ($hassiteconfig) {
    $settings = new admin_settingpage('filter_s3video_settings', get_string('settings', 'filter_s3video'));

    $settings->add(new admin_setting_heading(
        'filter_s3video/backend',
        get_string('backendheading', 'filter_s3video'),
        get_string('backenddesc', 'filter_s3video', reelo_api::URL)
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'filter_s3video/apikey',
        get_string('apikey', 'filter_s3video'),
        get_string('apikeydesc', 'filter_s3video'),
        ''
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'filter_s3video/secretkey',
        get_string('secretkey', 'filter_s3video'),
        get_string('secretkeydesc', 'filter_s3video'),
        ''
    ));

    $settings->add(new admin_setting_heading(
        'filter_s3video/watermark',
        get_string('watermarkheading', 'filter_s3video'),
        get_string('watermarkdesc', 'filter_s3video')
    ));

    $settings->add(new admin_setting_configtext(
        'filter_s3video/watermarktemplate',
        get_string('watermarktemplate', 'filter_s3video'),
        get_string('watermarktemplatedesc', 'filter_s3video'),
        '{fullname} - {idnumber}',
        PARAM_RAW,
        60
    ));

    $settings->add(new admin_setting_configtext(
        'filter_s3video/mobileusers',
        get_string('mobileusers', 'filter_s3video'),
        get_string('mobileusersdesc', 'filter_s3video'),
        '',
        PARAM_RAW,
        40
    ));

    $settings->add(new admin_setting_configcolourpicker(
        'filter_s3video/watermarkcolor',
        get_string('watermarkcolor', 'filter_s3video'),
        get_string('watermarkcolordesc', 'filter_s3video'),
        '#ffffff'
    ));

    $settings->add(new admin_setting_heading(
        'filter_s3video/access',
        get_string('accessheading', 'filter_s3video'),
        get_string('accessdesc', 'filter_s3video')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'filter_s3video/requirecourse',
        get_string('requirecourse', 'filter_s3video'),
        get_string('requirecoursedesc', 'filter_s3video'),
        '1'
    ));

    $settings->add(new admin_setting_configcheckbox(
        'filter_s3video/bindip',
        get_string('bindip', 'filter_s3video'),
        get_string('bindipdesc', 'filter_s3video'),
        '0'
    ));

    $settings->add(new admin_setting_configtext(
        'filter_s3video/tokenttl',
        get_string('tokenttl', 'filter_s3video'),
        get_string('tokenttldesc', 'filter_s3video'),
        25200,
        PARAM_INT,
        10
    ));

    $ADMIN->add('filtersettings', $settings);
}