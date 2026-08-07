<?php
// Soporte para la app de Moodle: registra el reproductor in-app.
//
// Los handlers de CoreFilterDelegate son un caso especial dentro del API de
// plugins de la app: NO se pueden declarar de forma declarativa aquí, solo
// registrarse desde JavaScript. Por eso la única pieza que importa de este
// fichero es 'init', cuyo JavaScript ejecuta la app nada más recuperar el
// plugin y es quien llama a CoreFilterDelegate.registerHandler().
//
// El filtro PHP sigue corriendo server-side antes de mandar el HTML a la app
// (ver s3video_player): lo que llega al dispositivo ya es un marcador con la
// URL de la playlist, el watermark resuelto y el token firmado. El handler
// solo tiene que montar el reproductor sobre ese marcador.

defined('MOODLE_INTERNAL') || die();

$addons = [
    'filter_s3video' => [
        'handlers' => [
            's3videoplayer' => [
                'delegate' => 'CoreFilterDelegate',
                'method' => 'mobile_init',
                'init' => 'mobile_init',
            ],
        ],
        'lang' => [
            ['openvideo', 'filter_s3video'],
            ['openvideoinfo', 'filter_s3video'],
        ],
    ],
];
