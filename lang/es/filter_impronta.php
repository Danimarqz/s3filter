<?php
$string['pluginname'] = 'Impronta';
// A un filtro lo nombra 'filtername', no 'pluginname': es el título de su fila
// en Gestionar filtros y de su página de ajustes. Si falta, Moodle escribe
// literalmente "[filtername,filter_impronta]".
$string['filtername'] = 'Impronta';
$string['notsupported'] = 'Tu navegador no soporta la etiqueta de video.';
$string['openvideo'] = 'Abrir video';
$string['openvideoinfo'] = 'El video se abrirá en tu navegador.';
$string['tokeninvalid'] = 'Este enlace seguro ha caducado. Vuelve a abrir el video desde la app de Moodle.';
$string['sessionconflict'] = 'Ahora estás conectado como {$a}. Cierra la sesión en este navegador antes de usar otra cuenta.';
$string['logoutandretry'] = 'Cerrar sesión y reintentar';
$string['reopenthroughapp'] = 'Vuelve a abrir el video desde Moodle para generar un enlace nuevo.';
$string['sessionexpired'] = 'La sesión de reproducción ha caducado. Vuelve a abrir el video desde Moodle.';
$string['accessrevoked'] = 'Acceso revocado. Ponte en contacto con tu academia.';
$string['sessionevicted'] = 'Esta clase se ha abierto en otro dispositivo. Vuelve a cargar la página para seguir aquí.';
$string['missingfilename'] = 'Falta el identificador del video. Vuelve a abrir este recurso desde Moodle.';
$string['manualenrolrequired'] = 'Tu cuenta debe tener una matrícula manual activa para acceder a este vídeo.';

// Settings.
$string['backendheading'] = 'Backend de Impronta';
$string['backenddesc'] = 'El plugin está vinculado al despliegue de Impronta en {$a}. Esta URL es fija y no se puede cambiar aquí.';
$string['apikey'] = 'API key de Impronta';
$string['apikeydesc'] = 'El token de API del tenant. Se obtiene de la consola root de Impronta (detalle del tenant) y se puede rotar desde allí.';
$string['secretkey'] = 'Secreto de firma local';
$string['secretkeydesc'] = 'Secreto usado para firmar los tokens internos entre el filtro y los endpoints playlist/embed. Debe ser aleatorio y privado.';
$string['registersite'] = 'Registrar este sitio Moodle';
$string['registersitedesc'] = 'Después de guardar la API key y el secreto, registra este origen Moodle en Impronta para restringir los iframes compartidos a este sitio.';
$string['registersitebutton'] = 'Registrar sitio Moodle';
$string['registerneedscredentials'] = 'Guarda arriba la API key y el secreto local de firma para activar el registro.';
$string['registersuccess'] = 'Este sitio Moodle ya está registrado en Impronta.';
$string['registerfailure'] = 'No se pudo registrar el sitio Moodle. Comprueba la API key y la conectividad.';
$string['scormguest'] = 'Los usuarios invitados no pueden reproducir vídeos compartidos. Inicia sesión en Moodle.';
$string['scormsession'] = 'Este enlace de vídeo compartido pertenece a otra sesión de Moodle. Ábrelo de nuevo desde tu cuenta.';
$string['scormgroupinvalid'] = 'El enlace del grupo de autorización no es válido.';
$string['watermarkheading'] = 'Watermark';
$string['watermarkdesc'] = 'El watermark es un texto superpuesto al vídeo, distinto para cada alumno, que identifica a quien lo está viendo.';
$string['watermarktemplate'] = 'Plantilla del watermark';
$string['watermarktemplatedesc'] = 'Campos entre llaves, sustituidos por los datos de cada alumno; el resto del texto se deja tal cual. Ejemplo: <code>{firstname} - {profile_field_dni}</code>.<br />Disponibles: <code>{firstname}</code>, <code>{lastname}</code>, <code>{fullname}</code>, <code>{email}</code>, <code>{username}</code>, <code>{idnumber}</code>, <code>{alternatename}</code>, <code>{middlename}</code>, <code>{city}</code>, <code>{country}</code>, <code>{institution}</code>, <code>{department}</code>, <code>{phone1}</code>, <code>{phone2}</code> y <code>{profile_field_XXX}</code> para cualquier campo de perfil personalizado.<br />Un campo que el alumno tenga vacío se sustituye por nada (el separador que lo rodea sí se queda); un nombre de campo mal escrito se ve literal, lo que sirve para detectar la errata.';
$string['mobileusers'] = 'Reproductor in-app (ids de usuario)';
$string['mobileusersdesc'] = 'Ids de usuario separados por comas que reciben el reproductor dentro de la app de Moodle. <strong>Vacío = todo el mundo</strong>, que es el valor normal; con ids, solo esos usuarios.<br />Ojo: el complemento móvil se sirve a todas las apps conectadas al sitio, así que un fallo aquí no afecta solo a quien abre un vídeo — puede dejar la app sin cargar ningún curso para todo el mundo. Por eso esta lista existe: para acotar la prueba de un cambio a unos pocos usuarios sin exponer a los alumnos.';
$string['watermarkcolor'] = 'Color del watermark';
$string['watermarkcolordesc'] = 'Color del texto superpuesto. El blanco por defecto es el que mejor se lee sobre casi cualquier vídeo: cuanto más se parezca el color al de la imagen, menos legible será el watermark si hay que usarlo como prueba.';
$string['mediaheading'] = 'Media del tenant';
$string['mediadesc'] = 'El video sale de la distribución de CloudFront del tenant, que no es el dominio de este Moodle. Aquí se indica esa base para que el reproductor pueda enseñar la miniatura de cada clase en lugar del logo del sitio.';
$string['mediabase'] = 'Base del media (CDN del tenant)';
$string['mediabasedesc'] = 'URL base del media del tenant (p. ej. <code>https://d123.cloudfront.net</code>). El poster se construye como <code>mediabase/thumbs/Materia/Clase.jpg</code>, una imagen estática generada en la ingesta. <strong>Vacío = logo del sitio como poster</strong>.';
$string['accessheading'] = 'Acceso por curso';
$string['accessdesc'] = 'Por defecto un video solo se puede reproducir si el usuario está matriculado en el curso donde está incrustado.';
$string['requirecourse'] = 'Exigir matrícula en el curso';
$string['requirecoursedesc'] = 'Bloquear la reproducción cuando el video no está incrustado dentro de un curso (o el usuario no está matriculado).';
$string['bindip'] = 'Vincular tokens a IP';
$string['bindipdesc'] = 'Añade la IP de la solicitud al payload del token interno para protección extra.';
$string['tokenttl'] = 'TTL del token interno (segundos)';
$string['tokenttldesc'] = 'Cuánto tiempo es válido un token de playlist. Además de cubrir toda la sesión de visionado, debe SOBREVIVIR a la firma de CloudFront de Impronta (TTL = duración de la clase + 30 min, techo de 6 h): la recuperación ante 403 recarga la playlist con este mismo token, y si el token caduca antes que la firma el reintento muere con un 403. El default (7 h = 25200 s) está por encima del techo de la firma; no lo bajes por debajo de él.';
$string['nocoursecontext'] = 'Este video solo está disponible dentro de un curso.';
$string['notenrolled'] = 'No estás matriculado en el curso donde está incrustado este video.';
$string['servicedenied'] = 'Este sitio no está autorizado para acceder al servicio de video. Contacta con el administrador del sitio.';
$string['servicedown'] = 'El servicio de video no está disponible temporalmente. Inténtalo de nuevo en un momento.';

// Quien es quien: traduce el seudonimo de Impronta al alumno de Moodle.
$string['whotitle'] = 'Impronta: ¿quién es este alumno?';
$string['whointro'] = 'Impronta no conoce el nombre de tus alumnos: guarda un identificador que solo este Moodle puede deshacer. Pega aquí los identificadores que veas en un aviso o en el panel —uno por línea, separados por comas o el correo entero pegado— y te decimos a quién corresponden.';
$string['wholookup'] = 'Buscar';
$string['whocolid'] = 'Identificador';
$string['whocoluser'] = 'Alumno';
$string['whocolemail'] = 'Correo';
$string['whounknown'] = 'Sin correspondencia (¿cuenta eliminada, o clave de firma cambiada?)';
$string['whononefound'] = 'No se ha reconocido ningún identificador. Son 24 caracteres hexadecimales, como 803cc8a9bc6813259f0383d3.';
$string['whonosecret'] = 'No hay clave de firma configurada, así que no se puede resolver ningún identificador. Configúrala en los ajustes del plugin.';

// Bloque informativo de los ajustes.
$string['aboutheading'] = 'Sobre Impronta';
$string['aboutdesc'] = '<p>Este plugin conecta tu Moodle con <a href="https://impronta.video/" target="_blank" rel="noopener">Impronta</a>: cada alumno ve la clase con su nombre y su DNI en pantalla, los enlaces caducan en minutos y queda registrado quién ha pedido cada clase.</p><p><a href="https://impronta.video/moodle" target="_blank" rel="noopener">Cómo funciona la integración</a> · <a href="https://impronta.video/seguridad" target="_blank" rel="noopener">Qué protege y qué no</a> · <a href="https://impronta.video/precios" target="_blank" rel="noopener">Precios</a></p><p><a href="{$a}">¿Quién es este alumno?</a> — traduce los identificadores de los avisos al nombre real. La correspondencia no sale de este servidor.</p>';
