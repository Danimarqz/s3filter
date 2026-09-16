<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'filter_impronta_renew' => [
        'classname' => '\\filter_impronta\\external\\renew',
        'methodname' => 'execute',
        'description' => 'Renew a playback lease for the authenticated mobile user',
        'type' => 'read',
        'ajax' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
];
