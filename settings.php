<?php
/**
 * Settings for the S3 Video filter.
 *
 * The Reelo backend URL (videos.dmarquez.com) is a constant in lib.php and
 * is intentionally NOT editable here. Only the tenant credential, the local
 * signing secret, and the watermark template are configured from Moodle.
 *
 * @package   filter_s3video
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('filter_s3video_settings', get_string('settings', 'filter_s3video'));

    $settings->add(new admin_setting_heading(
        'filter_s3video/backend',
        get_string('backendheading', 'filter_s3video'),
        get_string('backenddesc', 'filter_s3video', S3VIDEO_API_URL)
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
        'name - dni',
        PARAM_RAW,
        60
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