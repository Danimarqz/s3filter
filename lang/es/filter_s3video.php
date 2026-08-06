<?php
$string['pluginname'] = 'S3 Video filter';
$string['notsupported'] = 'Tu navegador no soporta la etiqueta de video.';
$string['openvideo'] = 'Abrir video';
$string['openvideoinfo'] = 'El video se abrirá en tu navegador.';
$string['tokeninvalid'] = 'Este enlace seguro ha caducado. Vuelve a abrir el video desde la app de Moodle.';
$string['sessionconflict'] = 'Ahora estás conectado como {$a}. Cierra la sesión en este navegador antes de usar otra cuenta.';
$string['logoutandretry'] = 'Cerrar sesión y reintentar';
$string['reopenthroughapp'] = 'Vuelve a abrir el video desde Moodle para generar un enlace nuevo.';
$string['sessionexpired'] = 'La sesión de reproducción ha caducado. Vuelve a abrir el video desde Moodle.';
$string['missingfilename'] = 'Falta el identificador del video. Vuelve a abrir este recurso desde Moodle.';
$string['manualenrolrequired'] = 'Tu cuenta debe tener una matrícula manual activa para acceder a este vídeo.';

// Settings.
$string['settings'] = 'Backend y watermark';
$string['backendheading'] = 'Backend de Reelo';
$string['backenddesc'] = 'El plugin está vinculado al despliegue de Reelo en {$a}. Esta URL es fija y no se puede cambiar aquí.';
$string['apikey'] = 'API key de Reelo';
$string['apikeydesc'] = 'El token de API del tenant. Se obtiene de la consola root de Reelo (detalle del tenant) y se puede rotar desde allí.';
$string['secretkey'] = 'Secreto de firma local';
$string['secretkeydesc'] = 'Secreto usado para firmar los tokens internos entre el filtro y los endpoints playlist/embed. Debe ser aleatorio y privado.';
$string['watermarkheading'] = 'Watermark';
$string['watermarkdesc'] = 'El watermark es un overlay por usuario (\'{name}\' nombre completo, \'{dni}\' idnumber, o profile_field_XXX para un campo de perfil personalizado).';
$string['watermarktemplate'] = 'Plantilla del watermark';
$string['watermarktemplatedesc'] = 'Tokens reemplazados por usuario: "name", "dni", o "profile_field_XXX". Ejemplo: "name - profile_field_dni".';
$string['accessheading'] = 'Acceso por curso';
$string['accessdesc'] = 'Por defecto un video solo se puede reproducir si el usuario está matriculado en el curso donde está incrustado.';
$string['requirecourse'] = 'Exigir matrícula en el curso';
$string['requirecoursedesc'] = 'Bloquear la reproducción cuando el video no está incrustado dentro de un curso (o el usuario no está matriculado).';
$string['bindip'] = 'Vincular tokens a IP';
$string['bindipdesc'] = 'Añade la IP de la solicitud al payload del token interno para protección extra.';
$string['tokenttl'] = 'TTL del token interno (segundos)';
$string['tokenttldesc'] = 'Cuánto tiempo es válido un token de playlist. Además de cubrir toda la sesión de visionado, debe SOBREVIVIR a la firma de CloudFront de Reelo (TTL = duración de la clase + 30 min, techo de 6 h): la recuperación ante 403 recarga la playlist con este mismo token, y si el token caduca antes que la firma el reintento muere con un 403. El default (7 h = 25200 s) está por encima del techo de la firma; no lo bajes por debajo de él.';
$string['nocoursecontext'] = 'Este video solo está disponible dentro de un curso.';
$string['notenrolled'] = 'No estás matriculado en el curso donde está incrustado este video.';
$string['servicedenied'] = 'Este sitio no está autorizado para acceder al servicio de video. Contacta con el administrador del sitio.';
$string['servicedown'] = 'El servicio de video no está disponible temporalmente. Inténtalo de nuevo en un momento.';