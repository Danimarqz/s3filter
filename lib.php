<?php
/**
 * S3 Video filter for Moodle — shared library.
 *
 * Embeds HLS video hosted on a Reelo tenant's private CloudFront distribution
 * into Moodle pages, behind two independent layers of access control:
 *
 *   Layer 1 (Reelo). This site authenticates to the Reelo backend with its
 *     tenant API key and receives a CloudFront signature scoped to one
 *     Materia/Clase prefix and expiring on its own. The CloudFront private
 *     key never leaves Reelo's AWS account, so a compromised Moodle server
 *     cannot mint URLs, and disabling the tenant in Reelo's console stops
 *     playback here within one cache TTL.
 *
 *   Layer 2 (Moodle). Before any of that, this plugin checks the viewer is
 *     enrolled in the course the video was embedded in. Reelo cannot make
 *     that check - Moodle owns the enrolments - and the course identity is
 *     taken from the filter's render context and then HMAC-signed into the
 *     token, so it is not something the browser can change.
 *
 * Neither layer is sufficient alone. Layer 1 proves the request comes from
 * this site; layer 2 proves this learner may see this video.
 *
 * The player also burns a per-user watermark (configurable fields, e.g.
 * "name - profile_field_dni") and reports playback + tamper analytics to
 * Reelo's POST /events.
 *
 * @package   filter_s3video
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;

/**
 * Endpoint de la API de Reelo. Constante a propósito, NO configurable desde
 * los ajustes de Moodle.
 *
 * El motivo es de seguridad, no de estilo: el plugin manda
 * "Authorization: Bearer {apikey}" a esta URL. Si fuera un ajuste, cualquiera
 * capaz de escribir en la configuración del sitio podría apuntarla a un
 * servidor propio y recoger el token del tenant en la primera reproducción.
 * Como constante, para eso hace falta acceso al código desplegado.
 *
 * Es el endpoint de plataforma, común a todos los tenants: el apikey ya
 * identifica al tenant, así que no hace falta una URL por cliente. Se eligió
 * este y no el CDN del tenant porque el segundo cambia —cuando una academia
 * monta su dominio propio, por ejemplo— y obligaría a tocar el plugin.
 *
 * El prefijo /api es obligatorio: la distribución de CloudFront es unificada
 * (misma URL para webapp, API y media) y solo las rutas bajo /api llegan al
 * API Gateway. Sin él, POST /moodle/authorize lo atiende el bucket de S3 y
 * responde 405 MethodNotAllowed, que no se parece en nada a un error de
 * credenciales y despista mucho al diagnosticar.
 *
 * Ojo: el dominio del MEDIA no se configura en ningún sitio. Viene en cada
 * respuesta de /moodle/authorize (campo baseurl), así que ya es dinámico por
 * tenant sin que el plugin sepa nada.
 */
define('S3VIDEO_API_URL', 'https://reelo.danimarqz.dev/api');

/* --------------------------------------------------------------------- */
/* Settings (Moodle admin configuration)                                  */
/* --------------------------------------------------------------------- */

/**
 * Reads a plugin setting from Moodle admin settings (filter_s3video/...).
 *
 * @param string $name
 * @param mixed $default
 * @return mixed
 */
function s3video_setting(string $name, $default = null) {
    $value = get_config('filter_s3video', $name);
    return ($value === false) ? $default : $value;
}

/**
 * Reads a required plugin setting, throwing when missing.
 *
 * @param string $name
 * @return string
 * @throws coding_exception
 */
function s3video_require_setting(string $name): string {
    $value = s3video_setting($name);
    if ($value === null || $value === '') {
        throw new coding_exception('Missing required Reelo configuration: filter_s3video/' . $name);
    }
    return (string) $value;
}

/* --------------------------------------------------------------------- */
/* Request helpers                                                        */
/* --------------------------------------------------------------------- */

/**
 * Whether internal tokens should be bound to the requester's IP.
 *
 * @return bool
 */
function s3video_token_bind_ip(): bool {
    $raw = s3video_setting('bindip', '0');
    return filter_var((string) $raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
}

/**
 * Best-effort request IP, tolerating the usual reverse proxies.
 *
 * @return string
 */
function s3video_get_request_ip(): string {
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (!$candidate) {
            continue;
        }
        $value = trim(explode(',', $candidate)[0]);
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return $value;
        }
    }

    return '0.0.0.0';
}

/* --------------------------------------------------------------------- */
/* Watermark label                                                        */
/* --------------------------------------------------------------------- */

/**
 * Campos del usuario que la plantilla del watermark puede usar entre llaves.
 *
 * Es una lista blanca a propósito, NO "cualquier propiedad de $USER": ese
 * objeto lleva también el hash de la contraseña, el sesskey y datos de
 * sesión, y la plantilla la escribe un administrador de Moodle pero el
 * resultado se pinta en el navegador de cada alumno. Los campos de perfil
 * personalizados (profile_field_XXX) se resuelven aparte, ya validados por
 * el propio Moodle.
 */
define('S3VIDEO_WATERMARK_FIELDS', [
    'firstname', 'lastname', 'fullname', 'email', 'username', 'idnumber',
    'alternatename', 'middlename', 'city', 'country', 'institution',
    'department', 'phone1', 'phone2',
]);

/**
 * Builds the watermark label from the configured template.
 *
 * La plantilla (ajuste `watermarktemplate`) usa tokens entre llaves, y todo
 * lo demás se mantiene literal:
 *
 *   - "{firstname}", "{lastname}", "{email}"... -> S3VIDEO_WATERMARK_FIELDS
 *   - "{fullname}"            -> fullname($USER), respeta el formato del sitio
 *   - "{profile_field_XXX}"   -> campo de perfil personalizado XXX
 *
 * Ejemplos:
 *   "{firstname} - {profile_field_dni}" -> "Juan - 12345678A"
 *   "{fullname} · {email}"              -> "Juan Pérez · juan@ejemplo.com"
 *
 * Los tokens sueltos "name" y "dni" (sin llaves) siguen funcionando: es el
 * formato que guardaban las instalaciones anteriores a las llaves, y romperlo
 * dejaría el texto literal "name - dni" de watermark sin avisar. Un token
 * desconocido se deja tal cual, para que el administrador vea la errata.
 *
 * @param \stdClass $user
 * @return string
 */
function s3video_build_watermark_label(\stdClass $user): string {
    global $CFG;

    $template = trim((string) s3video_setting('watermarktemplate', '{fullname} - {idnumber}'));
    if ($template === '') {
        return '';
    }

    // Load custom profile fields if the template references any.
    if (stripos($template, 'profile_field_') !== false) {
        require_once($CFG->dirroot . '/user/profile/lib.php');
        if (function_exists('profile_load_data')) {
            profile_load_data($user);
        }
    }

    // Un token es "{loquesea}" o, por compatibilidad, "name"/"dni" sueltos.
    $result = preg_replace_callback(
        '/\{([A-Za-z0-9_]+)\}|\b(name|dni)\b/',
        function ($m) use ($user) {
            $token = $m[1] !== '' ? $m[1] : $m[2];
            $value = s3video_watermark_field($user, $token);
            // Token desconocido: se deja literal para que se vea la errata.
            return $value ?? $m[0];
        },
        $template
    );

    // Collapse repeated whitespace around empty tokens ("name -  " -> "name").
    $result = trim(preg_replace('/\s+/', ' ', (string) $result));
    return $result;
}

/**
 * Color del watermark, listo para meter en una hoja de estilos.
 *
 * Valida la forma en vez de confiar en el ajuste: el valor acaba dentro de
 * un bloque <style>, así que un valor libre permitiría cerrar la regla e
 * inyectar CSS arbitrario en la página. Solo hexadecimal (#rgb, #rrggbb o
 * con alfa), que es lo que produce el selector de color de Moodle;
 * cualquier otra cosa cae al blanco de siempre.
 *
 * @return string
 */
function s3video_watermark_color(): string {
    $color = trim((string) s3video_setting('watermarkcolor', '#ffffff'));
    if (preg_match('/^#(?:[0-9A-Fa-f]{3,4}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})$/', $color)) {
        return $color;
    }
    return '#ffffff';
}

/**
 * Resuelve un token de la plantilla contra los datos del usuario.
 *
 * Devuelve null si el token no está permitido — que no es lo mismo que un
 * campo permitido pero vacío (cadena vacía): el primero se deja literal en
 * la plantilla, el segundo desaparece del watermark.
 *
 * No se aplica format_string() a los valores: el watermark los escribe con
 * textContent, así que no hay HTML que filtrar y pasarlos por ahí solo
 * conseguiría que un apellido como "O'Brien & Sons" saliera con entidades.
 * Lo que sí importa es cómo se inyectan en el <script> — ver el json_encode
 * con JSON_HEX_TAG de s3video_build_video_extras().
 *
 * @param \stdClass $user
 * @param string $token nombre del token, ya sin llaves
 * @return string|null null si el token no existe
 */
function s3video_watermark_field(\stdClass $user, string $token): ?string {
    // Alias heredados de las plantillas sin llaves.
    if ($token === 'name') {
        $token = 'fullname';
    } elseif ($token === 'dni') {
        $token = 'idnumber';
    }

    if ($token === 'fullname') {
        return (string) fullname($user);
    }
    if (in_array($token, S3VIDEO_WATERMARK_FIELDS, true)) {
        return (string) ($user->{$token} ?? '');
    }
    if (preg_match('/^profile_field_[A-Za-z0-9_]+$/', $token)) {
        return (string) ($user->{$token} ?? '');
    }

    return null;
}

/* --------------------------------------------------------------------- */
/* Layer 2: the course the video was embedded in                          */
/* --------------------------------------------------------------------- */

/**
 * Resolves the course id a filter context belongs to.
 *
 * @param \context|null $context
 * @return int course id, or 0 when the context is not inside a course
 */
function s3video_courseid_from_context($context): int {
    if (!$context instanceof \context) {
        return 0;
    }
    $coursecontext = $context->get_course_context(false);
    if (!$coursecontext) {
        return 0;
    }
    // SITEID is the front page, which would defeat the check.
    if ((int) $coursecontext->instanceid === (int) SITEID) {
        return 0;
    }
    return (int) $coursecontext->instanceid;
}

/**
 * Whether the current user may watch content belonging to a course.
 *
 * Active enrolment is the rule for learners. Managers/teachers who can view
 * a course without being enrolled are allowed through the capability check,
 * so a teacher can preview their own material.
 *
 * @param int $courseid
 * @return bool
 */
function s3video_user_can_access_course(int $courseid, int $userid = 0): bool {
    global $USER;

    if ($courseid <= 0) {
        return false;
    }

    // $userid > 0 llega del token firmado (camino de la app, sin cookie de
    // sesión): la identidad la prueba el HMAC, no isloggedin(). Con 0 se
    // comprueba el usuario de la sesión actual, que es el camino de siempre.
    if ($userid <= 0) {
        if (!isloggedin() || isguestuser()) {
            return false;
        }
        $userid = $USER->id ?? 0;
        if ($userid <= 0) {
            return false;
        }
    }

    $context = context_course::instance($courseid, IGNORE_MISSING);
    if (!$context) {
        return false;
    }

    // La matrícula se recomprueba en cada petición aunque el token la
    // acreditara al emitirse: entre la emisión y la reproducción pueden pasar
    // horas, y una baja del curso debe cortar el acceso sin esperar a que
    // caduque el token.
    if (is_enrolled($context, $userid, '', true)) {
        return true;
    }

    return has_capability('moodle/course:view', $context, $userid);
}

/**
 * Whether a video with no course context may still be played.
 *
 * @return bool
 */
function s3video_require_course(): bool {
    $raw = s3video_setting('requirecourse', '1');
    return filter_var((string) $raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
}

/**
 * Builds the internal token shared by embed.php and playlist.php.
 *
 * The course id is part of the signed payload: the course is decided by the
 * filter from the render context, and the browser cannot substitute a
 * different one without invalidating the HMAC.
 *
 * @param string $filename logical Materia/Clase path
 * @param int $expires unix timestamp
 * @param int $courseid course the video was embedded in (0 = none)
 * @param string $ip
 * @return string
 * @throws coding_exception
 */
function s3video_generate_token(string $filename, int $expires, int $courseid, string $ip, int $userid = 0): string {
    $secret = s3video_require_setting('secretkey');
    $payload = "{$filename}|{$expires}|{$courseid}";

    if (s3video_token_bind_ip()) {
        $payload .= "|{$ip}";
    }

    // El usuario se firma dentro del token para que el reproductor funcione
    // SIN sesión de navegador: en la app de Moodle el webview no lleva la
    // cookie de sesión (se autentica con token de web service), asi que
    // isloggedin() es false en playlist.php aunque el alumno esté logueado en
    // la app. El HMAC prueba que fue este Moodle quien emitió el token para
    // ese alumno, esa clase y ese curso, y con esa identidad se puede
    // recomprobar la matrícula en cada petición sin depender de la cookie.
    //
    // Al final y solo si hay usuario: un token con $userid = 0 produce el
    // mismo payload de siempre, asi que los tokens ya emitidos (viven horas)
    // siguen validando tras desplegar esto.
    if ($userid > 0) {
        $payload .= "|u{$userid}";
    }

    return hash_hmac('sha256', $payload, $secret);
}

/**
 * Validates a token produced by s3video_generate_token().
 *
 * @param string $filename
 * @param string $token
 * @param int $expires
 * @param int $courseid
 * @param string $ip
 * @return bool
 */
function s3video_validate_token(string $filename, string $token, int $expires, int $courseid, string $ip, int $userid = 0): bool {
    if (time() > $expires) {
        return false;
    }
    $expected = s3video_generate_token($filename, $expires, $courseid, $ip, $userid);
    return hash_equals($expected, $token);
}

/**
 * Cabeceras CORS de los endpoints que consume el reproductor.
 *
 * Hacen falta por el camino de la app: su webview no está en el dominio del
 * Moodle (su origen es http://localhost), y video.js pide la playlist y los
 * segmentos por XHR, que va sujeto a CORS. Sin esto el reproductor monta pero
 * falla al cargar el medio ("The media could not be loaded"). En el navegador
 * nunca se notó porque ahí es mismo origen.
 *
 * No se usa el comodín "*" sino lista blanca: la cabecera solo admite un
 * origen exacto o "*", así que se compara el Origin de la petición y se
 * devuelve tal cual si encaja. Los permitidos son los del webview de la app
 * (todos son localhost, con distintos esquemas según plataforma y versión) y
 * cualquier subdominio del propio sitio.
 *
 * Conviene saber qué protege esto y qué no: estos endpoints se autentican con
 * el token firmado de la URL, no con la cookie de sesión, y no se piden con
 * credentials. Quien tenga un token válido se descarga el contenido con curl
 * sin pasar por CORS. Esto no cierra ese agujero — evita conceder permiso a
 * orígenes que no lo necesitan, que es distinto.
 *
 * Sin cabecera Origin (curl, reproductores nativos, HLS nativo de iOS) no se
 * manda nada: CORS solo lo aplican los navegadores.
 *
 * @param bool $allowpost si además se admite POST con JSON (events.php)
 */
function s3video_send_cors_headers(bool $allowpost = false): void {
    global $CFG;

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return;
    }

    // Orígenes del webview de la app de Moodle. Cambian según plataforma y
    // versión (Android usa http://localhost, iOS los esquemas de Capacitor o
    // Ionic), así que están todos.
    $permitidos = [
        'http://localhost',
        'https://localhost',
        'ionic://localhost',
        'capacitor://localhost',
        'moodleappfs://localhost',
    ];

    $vale = in_array($origin, $permitidos, true);

    // Y el propio sitio, con sus subdominios: de wwwroot se saca el dominio
    // base (moodle.opositatcae.es -> opositatcae.es) y se admite cualquier
    // host https bajo él.
    if (!$vale) {
        $host = parse_url($CFG->wwwroot, PHP_URL_HOST) ?: '';
        $partes = explode('.', $host);
        if (count($partes) >= 2) {
            $base = implode('.', array_slice($partes, -2));
            $originhost = parse_url($origin, PHP_URL_HOST) ?: '';
            $esquema = parse_url($origin, PHP_URL_SCHEME) ?: '';
            $vale = $esquema === 'https'
                && ($originhost === $base || substr($originhost, -strlen('.' . $base)) === '.' . $base);
        }
    }

    if (!$vale) {
        return;
    }

    header('Access-Control-Allow-Origin: ' . $origin);
    // Allow-Credentials es imprescindible para el modo cookie: el reproductor
    // pide la playlist y los segmentos con withCredentials para que viajen las
    // cookies CloudFront-*, y con credenciales el navegador exige origen
    // exacto (nunca "*") y esta cabecera. Por eso arriba se devuelve el Origin
    // recibido en vez de un comodín.
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');

    if (!$allowpost) {
        return;
    }

    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');

    // El POST con Content-Type JSON dispara comprobación previa: hay que
    // contestar al OPTIONS y salir, antes de exigir token ni nada.
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/**
 * URL de uno de los endpoints del plugin con los parámetros firmados.
 *
 * Existe para que los cuatro sitios que construyen estas URLs (playlist,
 * embed, beacon de analítica y datos del reproductor in-app) no puedan
 * divergir: si a una se le olvidara el `u`, esa ruta dejaría de funcionar sin
 * cookie de sesión y solo se notaría desde el móvil.
 *
 * @param string $script embed.php | playlist.php | events.php
 * @param string $filename Materia/Clase
 * @param string $token token HMAC
 * @param int $expires caducidad del token
 * @param int $courseid curso (0 = ninguno)
 * @param int $userid usuario firmado en el token (0 = ninguno)
 * @param array $extra parámetros adicionales (a=1 para audio, etc.)
 * @return string
 */
function s3video_endpoint_url(string $script, string $filename, string $token, int $expires,
        int $courseid, int $userid, array $extra = []): string {
    global $CFG;

    $params = ['f' => $filename, 't' => $token, 'e' => $expires, 'c' => $courseid];
    if ($userid > 0) {
        $params['u'] = $userid;
    }

    return $CFG->wwwroot . '/filter/s3video/' . $script . '?'
        . http_build_query(array_merge($params, $extra), '', '&', PHP_QUERY_RFC3986);
}

/**
 * Autorización común de embed.php, playlist.php y events.php: valida el token
 * HMAC y decide si quien pide puede reproducir esta clase.
 *
 * Los tres endpoints hacían lo mismo con tres copias del mismo bloque, que es
 * justo donde una diverge sin que nadie se entere. Aquí también vive la regla
 * nueva: la identidad puede venir de la sesión de navegador O del $userid
 * firmado dentro del token, que es lo que permite reproducir desde el webview
 * de la app de Moodle, donde no hay cookie de sesión.
 *
 * @param string $path Materia/Clase
 * @param string|null $token token HMAC recibido
 * @param int $expires caducidad que venía en la URL
 * @param int $courseid curso (0 = sin contexto de curso)
 * @param int $userid usuario firmado en el token (0 = ninguno)
 * @return string|null null si puede seguir; si no, el identificador de la
 *     cadena de idioma con el motivo
 */
function s3video_authorize_request(string $path, ?string $token, int $expires, int $courseid, int $userid): ?string {
    $ip = s3video_get_request_ip();

    if ($token === null || $token === ''
            || !s3video_validate_token($path, $token, $expires, $courseid, $ip, $userid)) {
        return 'tokeninvalid';
    }

    if ($courseid > 0) {
        return s3video_user_can_access_course($courseid, $userid) ? null : 'notenrolled';
    }

    if (s3video_require_course()) {
        return 'nocoursecontext';
    }

    // Sin contexto de curso sigue haciendo falta saber quién reproduce: o hay
    // sesión de navegador, o el token trae el usuario firmado.
    if ($userid <= 0 && (!isloggedin() || isguestuser())) {
        return 'reopenthroughapp';
    }

    return null;
}

/* --------------------------------------------------------------------- */
/* Layer 1: CloudFront signatures issued by Reelo                          */
/* --------------------------------------------------------------------- */

/**
 * Obtains a CloudFront signature for a class prefix from the Reelo backend.
 *
 * Returns an array with:
 *   baseurl   - media root for this tenant, no trailing slash
 *   query     - "Policy=...&Signature=...&Key-Pair-Id=...", ready to append
 *   expiresat - unix timestamp the signature stops working at
 *
 * One signature covers the master playlist, both renditions, every segment
 * and the subtitle tracks of that class, because Reelo signs a custom policy
 * whose resource ends in "*". Cached until shortly before expiry.
 *
 * @param string $path logical Materia/Clase path
 * @param string|null $reason out-param: 'denied', 'unavailable' or null
 * @param int $userid usuario firmado en el token (0 = usar el de la sesión)
 * @return array|null null on failure
 */
function s3video_fetch_signature(string $path, ?string &$reason = null, int $userid = 0): ?array {
    global $CFG;

    $reason = null;

    $cache = cache::make_from_params(cache_store::MODE_APPLICATION, 'filter_s3video', 'signatures');
    $cachekey = sha1($path);

    $cached = $cache->get($cachekey);
    if (is_array($cached) && isset($cached['expiresat']) && $cached['expiresat'] > time() + 300) {
        return $cached;
    }

    // La clase curl de Moodle vive en filelib.php y NO se autocarga: en una
    // página normal suele estar cargada ya por otro include, pero playlist.php
    // es un endpoint ligero y ahí no lo está ("Class curl not found").
    // Se carga aquí y no al principio de lib.php a propósito: el filtro
    // incluye lib.php en CADA página del sitio, y filelib.php es grande.
    require_once($CFG->libdir . '/filelib.php');

    $apiurl = rtrim(S3VIDEO_API_URL, '/');
    $apikey = s3video_require_setting('apikey');

    $payload = ['videoPath' => $path];
    // Sin el userid del token, las peticiones que vienen del webview de la app
    // (sin cookie) mandarían el subject vacío y Reelo perdería de quién es la
    // firma.
    $subject = s3video_analytics_subject($userid);
    if ($subject !== '') {
        $payload['subject'] = $subject;
    }

    $curl = new \curl();
    $curl->setHeader([
        'Authorization: Bearer ' . $apikey,
        'Content-Type: application/json',
        'Accept: application/json',
    ]);
    $response = $curl->post($apiurl . '/moodle/authorize', json_encode($payload), [
        'CURLOPT_TIMEOUT' => 10,
        'CURLOPT_CONNECTTIMEOUT' => 5,
        'CURLOPT_FOLLOWLOCATION' => 0,
    ]);

    $info = $curl->get_info();
    $httpcode = (int) ($info['http_code'] ?? 0);

    if ($curl->get_errno()) {
        debugging('filter_s3video: Reelo API unreachable: ' . $curl->error, DEBUG_NORMAL);
        $reason = 'unavailable';
        return null;
    }
    if ($httpcode === 401 || $httpcode === 403 || $httpcode === 409) {
        // Bad/revoked API key, or a disabled tenant. Not transient.
        debugging('filter_s3video: Reelo API rejected this site (HTTP ' . $httpcode . ')', DEBUG_NORMAL);
        $reason = 'denied';
        return null;
    }
    if ($httpcode < 200 || $httpcode >= 300) {
        debugging('filter_s3video: Reelo API returned HTTP ' . $httpcode, DEBUG_NORMAL);
        $reason = 'unavailable';
        return null;
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)
            || empty($decoded['baseUrl'])
            || empty($decoded['policy'])
            || empty($decoded['signature'])
            || empty($decoded['keyPairId'])) {
        debugging('filter_s3video: malformed response from the Reelo API', DEBUG_NORMAL);
        $reason = 'unavailable';
        return null;
    }

    $query = 'Policy=' . $decoded['policy']
        . '&Signature=' . $decoded['signature']
        . '&Key-Pair-Id=' . $decoded['keyPairId'];

    $ttl = isset($decoded['ttlSeconds']) ? (int) $decoded['ttlSeconds'] : 3600;
    $result = [
        'baseurl' => rtrim((string) $decoded['baseUrl'], '/'),
        'query' => $query,
        'expiresat' => time() + max(600, $ttl),
        // El nombre real del master de la clase (videos.master): sin el,
        // playlist.php asume "{clase}.m3u8" y falla si el master tiene otro
        // nombre.
        'master' => isset($decoded['master']) ? (string) $decoded['master'] : '',
    ];

    $cache->set($cachekey, $result);
    return $result;
}

/**
 * Dominio común entre este Moodle y el CDN del tenant, o cadena vacía si no
 * comparten uno.
 *
 * Es lo que decide si se puede usar el modo cookie: una cookie con
 * Domain=.opositatcae.es viaja desde moodle.opositatcae.es hasta
 * reelo.cdn.moodle.opositatcae.es porque comparten dominio registrable. Con
 * el CDN en dzqvsfq0bfq1j.cloudfront.net no comparten nada y no hay cookie
 * posible: ese tenant se queda en URLs firmadas.
 *
 * @param string $baseurl raíz del media, como la devuelve s3video_fetch_signature
 * @return string dominio para la cookie (con punto inicial) o ''
 */
function s3video_cookie_domain(string $baseurl): string {
    global $CFG;

    $mediahost = parse_url($baseurl, PHP_URL_HOST) ?: '';
    $sitehost = parse_url($CFG->wwwroot, PHP_URL_HOST) ?: '';
    if ($mediahost === '' || $sitehost === '') {
        return '';
    }

    // Dominio registrable aproximado: las dos últimas etiquetas. Vale para
    // dominios normales; con sufijos de dos niveles (.co.uk) se quedaría corto
    // y simplemente no activaría el modo cookie, que es el fallo seguro.
    $base = implode('.', array_slice(explode('.', $sitehost), -2));
    if ($base === '' || substr($mediahost, -strlen($base)) !== $base) {
        return '';
    }

    return '.' . $base;
}

/**
 * Pone las cookies firmadas de CloudFront para la clase que cubre $signature.
 *
 * Las emite la misma respuesta que sirve la playlist, no una llamada aparte, y
 * eso evita una carrera: si el reproductor pidiera las cookies por un lado y
 * la playlist por otro, los primeros segmentos podrían salir antes de que la
 * cookie estuviera puesta y llegarían 403 sin causa visible. Cuando el
 * navegador tiene la playlist, tiene ya la cookie.
 *
 * @param array $signature como la devuelve s3video_fetch_signature()
 * @return bool false si el tenant no admite modo cookie o la firma no sirve
 */
function s3video_set_cloudfront_cookies(array $signature): bool {
    $domain = s3video_cookie_domain($signature['baseurl'] ?? '');
    if ($domain === '') {
        return false;
    }

    // Reelo devuelve la firma como query string ya montada; las cookies son
    // los mismos tres valores con otros nombres. Por eso el modo cookie no
    // necesita ningún cambio en el backend.
    parse_str($signature['query'] ?? '', $partes);
    $cookies = [
        'CloudFront-Policy' => $partes['Policy'] ?? '',
        'CloudFront-Signature' => $partes['Signature'] ?? '',
        'CloudFront-Key-Pair-Id' => $partes['Key-Pair-Id'] ?? '',
    ];
    foreach ($cookies as $valor) {
        if ($valor === '') {
            return false;
        }
    }

    // HttpOnly porque el JavaScript no las necesita, y así una inyección en la
    // página no puede robarlas. SameSite=Lax basta: la página y el CDN son
    // same-site, que es la precondición de todo este modo.
    $opciones = [
        'expires' => (int) ($signature['expiresat'] ?? 0),
        'path' => '/',
        'domain' => $domain,
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    foreach ($cookies as $nombre => $valor) {
        setcookie($nombre, $valor, $opciones);
    }

    return true;
}

/**
 * Appends a signature's query string to a CloudFront URL.
 *
 * En modo cookie NO se firma la URL: la autorización viaja en las cookies
 * CloudFront-* que puso cookies.php, y ese es justamente el premio del modo.
 * Con la firma en la query queda horneada en la playlist que el reproductor
 * retiene, así que no se puede rotar sin reescribir la playlist —y reescribir
 * la playlist reinicia la reproducción—, lo que obliga a TTLs de horas. Fuera
 * de la URL, la firma se puede renovar por detrás mientras el alumno ve la
 * clase.
 *
 * @param string $url
 * @param array $signature as returned by s3video_fetch_signature()
 * @param bool $cookiemode
 * @return string
 */
function s3video_sign_url(string $url, array $signature, bool $cookiemode = false): string {
    if ($cookiemode) {
        return $url;
    }
    $separator = strpos($url, '?') === false ? '?' : '&';
    return $url . $separator . $signature['query'];
}

/**
 * Percent-encodes a key path segment by segment, leaving the slashes.
 *
 * @param string $path
 * @return string
 */
function s3video_encode_key(string $path): string {
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

/**
 * Relays a batch of analytics events to Reelo's POST /events with this
 * site's tenant API key. Called only from events.php, server-side: the API
 * key must never appear in a page the learner's browser can read.
 *
 * Fire-and-forget via a detached curl: a slow or unreachable Reelo backend
 * must not pin a PHP-FPM worker for seconds per heartbeat (one flush per
 * viewer every 15s is self-inflicted DoS if the relay blocks). Credentials
 * and payload go in 0600 temp files, never on the curl command line (ps
 * would expose them). Falls back to a short synchronous POST if popen is
 * disabled.
 *
 * @param array $payload {subject, events:[{videoPath,type,positionSeconds,ts}]}
 * @return bool true when the relay was dispatched (not necessarily delivered)
 */
function s3video_post_events(array $payload): bool {
    global $CFG;

    $apikey = (string) s3video_setting('apikey', '');
    if ($apikey === '') {
        error_log('filter_s3video: no apikey configured; dropping analytics batch');
        return false;
    }

    $endpoint = rtrim(S3VIDEO_API_URL, '/') . '/events';
    $json = json_encode($payload);
    if ($json === false) {
        return false;
    }

    // Sin popen o en Windows (donde 'cmd &' no es fire-and-forget fiable):
    // POST sincrono corto. Mejor un evento bloqueante ocasional que perder
    // la analitica.
    if (PHP_OS_FAMILY === 'Windows' || !function_exists('popen')) {
        // Ver s3video_fetch_signature: la clase curl no se autocarga.
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setHeader([
            'Authorization: Bearer ' . $apikey,
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        $curl->post($endpoint, $json, [
            'CURLOPT_TIMEOUT' => 2,
            'CURLOPT_CONNECTTIMEOUT' => 2,
            'CURLOPT_FOLLOWLOCATION' => 0,
        ]);
        $info = $curl->get_info();
        $httpcode = (int) ($info['http_code'] ?? 0);
        return $httpcode >= 200 && $httpcode < 300;
    }

    $payloadfile = tempnam(sys_get_temp_dir(), 'reelo_evt_');
    $cfgfile = tempnam(sys_get_temp_dir(), 'reelo_cfg_');
    if ($payloadfile === false || $cfgfile === false) {
        return false;
    }
    @file_put_contents($payloadfile, $json);
    // Config de curl: mantiene el apikey fuera de ps y del historial de la
    // shell. tempnam crea los dos ficheros con modo 0600.
    $cfg = "silent\n"
        . "output=/dev/null\n"
        . "request=POST\n"
        . "header=Authorization: Bearer {$apikey}\n"
        . "header=Content-Type: application/json\n"
        . "data-binary=@{$payloadfile}\n"
        . "max-time=5\n"
        . "connect-timeout=3\n"
        . "url={$endpoint}\n";
    @file_put_contents($cfgfile, $cfg);

    // '&' al final: la shell hace el fork y pclose() vuelve al instante.
    $cmd = 'curl --config ' . escapeshellarg($cfgfile) . ' >/dev/null 2>&1 &';
    $p = popen($cmd, 'r');
    if ($p === false) {
        return false;
    }
    pclose($p);
    return true;
}

/**
 * Downloads a URL that already carries a valid signature, with a single
 * fast retry. NO sleep entre reintentos: se invoca en cada peticion de
 * playlist y de subtitulo, y 3 reintentos con sleep(1) y timeout 15 podian
 * bloquear un worker de PHP-FPM hasta 45s bajo carga (DoS autoinfligido).
 *
 * @param string $url
 * @param int $retries
 * @return string|false
 */
function s3video_fetch_remote(string $url, int $retries = 2) {
    $attempt = 0;
    while ($attempt < $retries) {
        $attempt++;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'Reelo-Moodle-HLS-Proxy',
            CURLOPT_FAILONERROR => true,
        ]);

        $body = curl_exec($ch);
        $err = curl_errno($ch);
        $errmsg = curl_error($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err === 0 && $http < 400 && stripos((string) $body, '<Error>') === false) {
            return $body;
        }

        $safeurl = strtok($url, '?');
        error_log("filter_s3video: fetch {$attempt}/{$retries} failed for [{$safeurl}]. HTTP {$http}. curl [{$err}] {$errmsg}");
    }

    return false;
}

/* --------------------------------------------------------------------- */
/* HLS playlist handling                                                  */
/* --------------------------------------------------------------------- */

/**
 * Whether a playlist is a master (demuxed: variants + media) rather than a
 * media playlist made of segments.
 *
 * @param string $content
 * @return bool
 */
function s3video_is_master_playlist(string $content): bool {
    return (stripos($content, '#EXT-X-STREAM-INF') !== false) || (stripos($content, '#EXT-X-MEDIA:') !== false);
}

/**
 * Validates that a rendition name is a safe ".m3u8" basename.
 *
 * @param string $name
 * @return bool
 */
function s3video_is_safe_rendition_name(string $name): bool {
    if ($name === '' || strpos($name, '..') !== false || strpos($name, '/') !== false || strpos($name, '\\') !== false) {
        return false;
    }
    return (bool) preg_match('/^[A-Za-z0-9._-]+\.m3u8$/', $name);
}

/**
 * Rewrites a demuxed master playlist so its rendition URIs point back at
 * playlist.php instead of at CloudFront.
 *
 * @param string $content original master playlist
 * @param string $path logical video path
 * @param int $courseid course the video is embedded in
 * @param string $ip requester IP
 * @param bool $audio whether the original request carried the audio flag
 * @param int $userid usuario firmado en el token de la petición original
 * @param bool $cookiemode si la petición original venía en modo cookie
 * @return string
 * @throws coding_exception
 */
function s3video_rewrite_master_playlist(string $content, string $path, int $courseid, string $ip,
        bool $audio, int $userid = 0, bool $cookiemode = false): string {
    global $CFG;

    // Mismo fallback que s3video_player (25200 = 7 h): los tokens de los
    // renditions dentro del master deben durar lo mismo que el del player.
    // Si divergieran, en un sitio sin el ajuste guardado el player recibiria
    // un token de 7 h y los renditions de 30 min, y la recuperacion ante 403
    // fallaria a mitad de clase.
    $tokenttl = max(60, (int) s3video_setting('tokenttl', 25200));
    $expires = time() + $tokenttl;
    // El token nuevo se ata al MISMO usuario que el de la petición original:
    // si se emitiera sin él, la URL de la rendition llegaría sin `u` y no
    // pasaría su propia validación HMAC. Solo se nota en clases con varias
    // calidades, que son las que traen master playlist.
    $token = s3video_generate_token($path, $expires, $courseid, $ip, $userid);

    $buildproxyurl = function (string $rendition) use ($CFG, $path, $token, $expires, $courseid, $audio,
            $userid, $cookiemode): string {
        $params = ['f' => $path, 'r' => $rendition, 't' => $token, 'e' => $expires, 'c' => $courseid];
        if ($userid > 0) {
            $params['u'] = $userid;
        }
        if ($cookiemode) {
            // El modo tiene que propagarse: si la rendition volviera en modo
            // firma, sus segmentos llevarían la firma en la query y se
            // perdería el sentido de haber puesto las cookies.
            $params['modo'] = 'cookie';
        }
        if ($audio) {
            $params['a'] = 1;
        }
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        return $CFG->wwwroot . '/filter/s3video/playlist.php?' . $query;
    };

    $normalized = str_replace(["\r\n", "\r"], "\n", $content);
    $lines = explode("\n", $normalized);
    $rewritten = [];

    foreach ($lines as $line) {
        $trim = trim($line);

        if ($trim === '') {
            $rewritten[] = $line;
            continue;
        }

        if ($trim[0] === '#') {
            if (stripos($trim, '#EXT-X-MEDIA:') === 0 && preg_match('/URI="([^"]*)"/', $trim, $m) && $m[1] !== '') {
                $renditionname = basename(str_replace('\\', '/', strtok($m[1], '?')));
                if (s3video_is_safe_rendition_name($renditionname)) {
                    $line = preg_replace('/URI="[^"]*"/', 'URI="' . $buildproxyurl($renditionname) . '"', $line, 1);
                }
            }
            $rewritten[] = $line;
            continue;
        }

        $bare = strtok($trim, '?');
        if (preg_match('/\.m3u8$/i', $bare)) {
            $renditionname = basename(str_replace('\\', '/', $bare));
            if (s3video_is_safe_rendition_name($renditionname)) {
                $rewritten[] = $buildproxyurl($renditionname);
                continue;
            }
        }

        $rewritten[] = $line;
    }

    return implode("\n", $rewritten);
}

/* --------------------------------------------------------------------- */
/* Player                                                                 */
/* --------------------------------------------------------------------- */

/**
 * Whether the request comes from the Moodle mobile app.
 *
 * @param bool $forceplayer
 * @return bool
 */
function s3video_is_mobile_app(bool $forceplayer): bool {
    if ($forceplayer) {
        return false;
    }

    $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return stripos($agent, 'MoodleMobile') !== false;
}

/**
 * Renders a short message in place of the player.
 *
 * @param string $identifier language string identifier
 * @return string
 */
function s3video_notice(string $identifier): string {
    return '<div class="alert alert-warning" role="alert">'
        . s(get_string($identifier, 'filter_s3video'))
        . '</div>';
}

/**
 * Builds the player HTML for one video.
 *
 * @param string $filename logical Materia/Clase path
 * @param array $options
 *   courseid      int   course the video is embedded in
 *   audio         bool  render as audio-only
 *   subtitles     array language codes, e.g. ['es', 'en']
 *   forceplayer   bool  render inline even for the mobile app (embed.php)
 *   token/expires       reuse an already validated token instead of minting one
 * @return string
 * @throws coding_exception
 */
function s3video_player(string $filename, array $options = []): string {
    global $CFG, $USER;

    $defaults = [
        'courseid' => 0,
        'forceplayer' => false,
        'token' => null,
        'expires' => null,
        'userid' => null,
        'playbackrates' => [0.5, 0.75, 1, 1.25, 1.5, 2],
        'audio' => false,
        'subtitles' => [],
    ];
    $options = array_merge($defaults, $options);
    $isaudio = !empty($options['audio']);
    $courseid = (int) $options['courseid'];

    // Identidad de esta reproducción. Se resuelve antes que nada porque la
    // comprobación de matrícula depende de ella: cuando el reproductor lo
    // pinta embed.php abierto desde la app, no hay cookie de sesión y el
    // único usuario conocido es el que viene firmado en el token.
    $tokenuserid = (int) ($options['userid'] ?? 0);
    if ($tokenuserid <= 0) {
        $tokenuserid = (isloggedin() && !isguestuser()) ? (int) $USER->id : 0;
    }

    if ($courseid <= 0 && s3video_require_course()) {
        return s3video_notice('nocoursecontext');
    }
    if ($courseid > 0 && !s3video_user_can_access_course($courseid, $tokenuserid)) {
        return s3video_notice('notenrolled');
    }

    // Render cache is keyed on the video; anything per-user (watermark,
    // analytics subject) is appended afterwards via s3video_build_video_extras.
    $cacheable = empty($options['token']) && empty($options['expires']) && empty($options['forceplayer']);
    static $rendercache = [];
    static $assetsprinted = false;

    $ismobileapp = s3video_is_mobile_app($options['forceplayer']);
    if ($ismobileapp) {
        $cacheable = false;
    }

    $cachekey = ($isaudio ? 'a:' : 'v:') . $courseid . ':' . $filename;

    $escapedid = preg_replace('/[^A-Za-z0-9\-_:.]/', '-', basename($filename));
    $escapedid = 'vjs_' . $escapedid;

    // tokenttl default 7 h (25200 s): debe sobrevivir a la firma de Reelo
    // (TTL = duración + 30 min, techo de 6 h), porque la recuperación ante
    // 403 (fase 0.2) recarga playlist.php con el token ORIGINAL del render.
    $tokenttl = max(60, (int) s3video_setting('tokenttl', 25200));

    if (!empty($options['token']) && !empty($options['expires'])) {
        $token = $options['token'];
        $expires = (int) $options['expires'];
    } else {
        $expires = time() + $tokenttl;
        $token = s3video_generate_token($filename, $expires, $courseid, s3video_get_request_ip(), $tokenuserid);
    }

    // Modo de autorización del media, decidido una sola vez y aquí arriba
    // porque lo necesitan tanto la URL de la playlist como la del beacon de
    // analítica (para poder contar por qué camino se reproduce cada clase).
    //
    //   cookies       -> el tenant tiene dominio propio bajo el de este Moodle
    //   urls firmadas -> cualquier otro caso, y siempre en la app
    //
    // Es la diferencia entre que la firma viaje fuera de la playlist (rotable,
    // TTL corto) o horneada en cada segmento (TTL de horas).
    // La app queda FUERA del modo cookie aunque el tenant tenga dominio
    // propio: su webview está en localhost, que respecto al CDN es cross-site,
    // así que las cookies no viajarían y todos los segmentos darían 403. Es la
    // fila "webview" de la tabla de modos: ahí las URLs firmadas no son deuda
    // técnica, son el único camino que funciona.
    // La app queda fuera del modo cookie aunque el tenant tenga dominio
    // propio: su webview está en localhost, que respecto al CDN es cross-site,
    // así que las cookies no viajarían y todos los segmentos darían 403.
    //
    // Ojo con la política CORS de CloudFront: si sirve el comodín "*", el
    // estándar prohíbe usar esa respuesta en peticiones con credenciales, que
    // es como pide los segmentos el reproductor en este modo. Para que el modo
    // cookie funcione de verdad hace falta que CloudFront devuelva el origen
    // exacto y Access-Control-Allow-Credentials.
    $cookiemode = false;
    if (!$isaudio && !$ismobileapp) {
        $razonfirma = null;
        $firma = s3video_fetch_signature($filename, $razonfirma, $tokenuserid);
        $cookiemode = $firma && s3video_cookie_domain($firma['baseurl']) !== '';
    }

    $extraparams = $isaudio ? ['a' => 1] : [];
    if ($cookiemode) {
        $extraparams['modo'] = 'cookie';
    }

    $playlisturl = s3video_endpoint_url('playlist.php', $filename, $token, $expires, $courseid,
        $tokenuserid, $extraparams);

    // El beacon de analitica se construye con el mismo token HMAC que la
    // playlist: el apikey del tenant NUNCA sale del servidor (lo usa solo
    // events.php, server-side). Ver s3video_build_video_extras(). Se le pasa
    // tambien la URL de playlist para la recuperacion ante 403 (fase 0.2 del
    // plan de TTL corto). Los embeds de solo audio no emiten extras (ni
    // watermark, ni analitica, ni recuperacion): a proposito, es un flujo
    // marginal que no merece el JS extra.
    $extrahtml = $isaudio ? '' : s3video_build_video_extras($escapedid, $filename, $token, $expires,
        $courseid, $playlisturl, $tokenuserid, $cookiemode);

    if ($cacheable && isset($rendercache[$cachekey])) {
        return $rendercache[$cachekey] . $extrahtml;
    }

    // El `u` que mete s3video_endpoint_url es lo que evita el 403 al abrir
    // este enlace en el navegador del móvil: ahí no hay cookie de sesión de
    // Moodle (comprobado por ADB, fase 0.4 del plan de TTL corto).
    $embedurl = s3video_endpoint_url('embed.php', $filename, $token, $expires, $courseid,
        $tokenuserid, $isaudio ? ['a' => 1] : []);

    $buttontext = get_string('openvideo', 'filter_s3video');

    if ($ismobileapp) {
        $infotext = get_string('openvideoinfo', 'filter_s3video');
        $embedhref = s($embedurl);

        // El HTML que se manda a la app lleva TODO lo que el reproductor
        // in-app necesita, ya firmado server-side. Aquí (petición del web
        // service) el alumno sí está autenticado, mientras que en el webview
        // no habrá cookie: por eso el token va atado al usuario y el
        // watermark viaja resuelto, no como plantilla.
        //
        // Dentro va el botón de abrir en el navegador como respaldo: si el
        // handler de CoreFilterDelegate no llega a ejecutarse (app antigua,
        // JS bloqueado, fallo al cargar video.js), el alumno ve exactamente
        // lo que veía antes en vez de un hueco vacío. El handler lo borra al
        // montar el reproductor.
        //
        // Esta rama solo corre en la petición del web service de la app
        // (embed.php pasa forceplayer, que desactiva s3video_is_mobile_app),
        // y ahí el alumno sí está autenticado: $USER es quien verá el vídeo.
        $watermarklabelapp = $tokenuserid > 0 ? s3video_build_watermark_label($USER) : '';

        $data = [
            'playlist' => $playlisturl,
            'events' => $isaudio ? '' : s3video_endpoint_url('events.php', $filename, $token,
                $expires, $courseid, $tokenuserid),
            'subject' => s3video_analytics_subject($tokenuserid),
            'path' => $filename,
            'watermark' => $watermarklabelapp,
            'color' => s3video_watermark_color(),
            'embed' => $embedurl,
        ];
        $attrs = '';
        foreach ($data as $key => $value) {
            $attrs .= ' data-s3video-' . $key . '="' . s((string) $value) . '"';
        }

        return <<<HTML
<div class="filter_s3video-app-player"{$attrs} style="text-align:center; padding:1em;">
  <a href="{$embedhref}" target="_blank"
     style="display:inline-block; background:#1976d2; color:#fff;
            padding:0.8em 1.2em; border-radius:6px;
            font-weight:600; text-decoration:none;">
    {$buttontext}
  </a>
  <p style="font-size:0.9em;color:#666;margin-top:0.5em;">{$infotext}</p>
</div>
HTML;
    }

    $assets = '';
    if (!$assetsprinted) {
        $assetsprinted = true;
        // El color del watermark se aplica por CSS y no como opción de
        // ReeloWatermark.attach(): el UMD del paquete trae "color:#fff" en el
        // style inline de cada capa, y una regla de hoja con !important lo
        // gana sin tener que recompilar y redistribuir el paquete. El bucle
        // anti-tampering del UMD reafirma display/visibility/opacity, pero no
        // toca color, así que no hay pelea entre los dos.
        $wmcolor = s3video_watermark_color();
        $assets = <<<HTML
<link href="https://vjs.zencdn.net/8.16.1/video-js.css" rel="stylesheet" />
<style>.reelo-watermark { color: {$wmcolor} !important; }</style>
<script src="https://vjs.zencdn.net/8.16.1/video.min.js"></script>
<script src="{$CFG->wwwroot}/filter/s3video/watermark.js"></script>
HTML;
    }

    $setupconfig = $isaudio ? ['audioOnlyMode' => true] : ['fluid' => true];

    if ($cookiemode) {
        // withCredentials es obligatorio: la página está en el subdominio del
        // Moodle y el media en el del CDN, así que sin él el navegador no
        // manda las cookies CloudFront-* a las peticiones de los segmentos.
        $setupconfig['html5'] = ['vhs' => ['withCredentials' => true]];
    }

    if (!empty($options['playbackrates']) && is_array($options['playbackrates'])) {
        $normalized = [];
        foreach ($options['playbackrates'] as $rate) {
            $rate = (float) $rate;
            if ($rate > 0) {
                $normalized[] = $rate;
            }
        }
        if (!empty($normalized)) {
            $normalized = array_values(array_unique($normalized));
            sort($normalized, SORT_NUMERIC);
            $setupconfig['playbackRates'] = $normalized;
        }
    }

    $setupattr = s(json_encode($setupconfig, JSON_UNESCAPED_SLASHES));
    $playlistsrc = s($playlisturl);

    $trackshtml = '';
    if (!$isaudio && !empty($options['subtitles']) && is_array($options['subtitles'])) {
        $langnames = ['es' => 'Español', 'en' => 'English', 'pt' => 'Português', 'fr' => 'Français'];
        foreach ($options['subtitles'] as $lang) {
            $lang = strtolower(trim((string) $lang));
            if (!preg_match('/^[a-z0-9\-]{2,5}$/', $lang)) {
                continue;
            }
            $trackparams = [
                'f' => $filename,
                'vtt' => $lang,
                't' => $token,
                'e' => $expires,
                'c' => $courseid,
            ];
            $trackquery = http_build_query($trackparams, '', '&', PHP_QUERY_RFC3986);
            $trackurl = $CFG->wwwroot . '/filter/s3video/playlist.php?' . $trackquery;
            $label = $langnames[$lang] ?? strtoupper($lang);
            $trackshtml .= '  <track kind="subtitles" srclang="' . s($lang) . '" label="' . s($label)
                . '" src="' . s($trackurl) . "\">\n";
        }
    }

    $assetsmarkup = $assets === '' ? '' : $assets . "\n";
    $vjsclass = $isaudio ? '' : ' vjs-fluid';
    $vjsstyle = $isaudio ? ' style="width:100%;height:3em"' : '';
    $embedhref = s($embedurl);

    $html = <<<HTML
{$assetsmarkup}<video id="{$escapedid}" class="video-js vjs-default-skin{$vjsclass}"{$vjsstyle}
       controls preload="auto" disablepictureinpicture playsinline data-setup='{$setupattr}'>
  <source src="{$playlistsrc}" type="application/x-mpegURL">
{$trackshtml}</video>
<script>
document.addEventListener('DOMContentLoaded', function() {
  if (typeof videojs !== 'undefined') {
    videojs('{$escapedid}').ready(function() { this.playbackRate(1); });
  }
});
</script>
<div style="text-align:center; padding:1em;">
  <a href="{$embedhref}" target="_blank"
     style="display:inline-block; background:#1976d2; color:#fff;
            padding:0.8em 1.2em; border-radius:6px;
            font-weight:600; text-decoration:none;">
    {$buttontext}
  </a>
</div>
HTML;

    if ($cacheable && $assets === '') {
        $rendercache[$cachekey] = $html;
    }

    return $html . $extrahtml;
}

/* --------------------------------------------------------------------- */
/* Watermark + analytics (per-user, appended after the cached player)      */
/* --------------------------------------------------------------------- */

/**
 * Pseudonymous, stable identifier for the current learner.
 *
 * Reelo receives this instead of the Moodle user id/name/DNI: enough to
 * count distinct viewers and follow one learner's progress, useless to
 * anyone who does not hold this site's secret.
 *
 * @return string empty for guests and anonymous requests
 */
function s3video_analytics_subject(int $userid = 0): string {
    global $USER;

    // $userid > 0 llega del token firmado: es el camino de la app, donde no
    // hay sesión de navegador y isloggedin() seria false pese a haber un
    // alumno identificado. Sin él, las reproducciones desde la app no
    // contarian en las estadisticas.
    if ($userid <= 0) {
        if (!isloggedin() || isguestuser()) {
            return '';
        }
        $userid = (int) $USER->id;
    }

    $secret = (string) s3video_setting('secretkey', '');
    if ($secret === '') {
        return '';
    }
    return substr(hash('sha256', $userid . '|' . $secret), 0, 24);
}

/**
 * Per-user markup appended after the (cacheable) player HTML: watermark and
 * analytics beacon sharing one script scope, so onTamper reports to the same
 * queue. Never cached.
 *
 * The beacon posts to the plugin's own events.php, which validates the same
 * HMAC token that protects the playlist and relays server-side to Reelo
 * with the tenant's API key. The API key never reaches the browser: leaking
 * it would let any student mint CloudFront signatures for the whole catalog
 * (POST /moodle/authorize trusts that same key).
 *
 * @param string $escapedid DOM id of the video element
 * @param string $filename logical video path
 * @param string $token HMAC token minted for this render
 * @param int $expires unix timestamp the token expires at
 * @param int $courseid course the video was embedded in (0 = none)
 * @param string $playlisturl playlist.php URL, used by the 403 auto-recovery
 *     to reload the source with a cache-buster when the signature expires
 *     mid-playback (plan TTL corto, fase 0.2)
 * @param int $userid usuario firmado en el token (0 = usar el de la sesión)
 * @param bool $cookiemode si el media se autoriza por cookies en vez de por
 *     firma en la URL; viaja hasta la analítica para poder contar por qué
 *     camino se reproduce cada clase
 * @return string
 */
function s3video_build_video_extras(string $escapedid, string $filename, string $token, int $expires,
        int $courseid, string $playlisturl, int $userid = 0, bool $cookiemode = false): string {
    global $CFG, $USER;

    $eventurl = s3video_endpoint_url('events.php', $filename, $token, $expires, $courseid, $userid,
        $cookiemode ? ['modo' => 'cookie'] : []);
    $subject = s3video_analytics_subject($userid);

    // El usuario del watermark sale del token cuando no hay sesión (la app
    // abre el reproductor sin cookie); si no, del usuario de la sesión. Sin
    // esto el watermark quedaría vacío justo en el camino móvil, que es donde
    // más falta hace.
    $watermarkuser = null;
    if ($userid > 0) {
        $watermarkuser = \core_user::get_user($userid);
    } else if (isloggedin() && !isguestuser()) {
        $watermarkuser = $USER;
    }

    $watermarklabel = $watermarkuser ? s3video_build_watermark_label($watermarkuser) : '';

    $idjson = json_encode($escapedid, JSON_UNESCAPED_SLASHES);
    $urljson = json_encode($eventurl, JSON_UNESCAPED_SLASHES);
    $subjectjson = json_encode($subject, JSON_UNESCAPED_SLASHES);
    $pathjson = json_encode($filename, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    // JSON_HEX_TAG no es opcional: la etiqueta sale de campos que edita el
    // propio usuario (nombre, campos de perfil) y se inyecta dentro de un
    // <script>. Sin escapar "<" y ">", un perfil con "</script>" cerraría el
    // bloque e inyectaría HTML en la página. Lo que se escapa son < y
    // >, escapes de cadena JS: el watermark muestra el texto tal cual.
    $labeljson = json_encode(
        $watermarklabel,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
    );
    $playlistjson = json_encode($playlisturl, JSON_UNESCAPED_SLASHES);
    $expiredjson = json_encode(s(get_string('sessionexpired', 'filter_s3video')), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return <<<HTML
<script>
(function() {
  var targetId = {$idjson};
  var watermarkLabel = {$labeljson};

  function waitForEl() {
    var el = document.getElementById(targetId);
    if (!el) { setTimeout(waitForEl, 150); return; }
    waitForPlayer();
  }

  function waitForPlayer() {
    if (typeof videojs === 'undefined') { setTimeout(waitForPlayer, 150); return; }
    var player = videojs.getPlayer ? videojs.getPlayer(targetId) : videojs(targetId);
    if (!player || !player.ready) { setTimeout(waitForPlayer, 150); return; }
    player.ready(function() { init(player); });
  }

  function init(player) {
    var cfg = {
      url: {$urljson},
      subject: {$subjectjson},
      videoPath: {$pathjson},
      playlistUrl: {$playlistjson},
      heartbeatSeconds: 15
    };
    var queue = [];

    function push(type, pos) {
      queue.push({videoPath: cfg.videoPath, type: type, positionSeconds: Math.round(pos), ts: Date.now()});
    }
    function flush() {
      if (queue.length === 0 || !cfg.url) return;
      var batch = queue; queue = [];
      try {
        fetch(cfg.url, {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({subject: cfg.subject, events: batch}),
          keepalive: true
        }).catch(function() {});
      } catch (e) {}
    }

    player.on('play', function() { push('play', player.currentTime() || 0); });
    player.on('pause', function() { push('pause', player.currentTime() || 0); });
    player.on('seeked', function() { push('seek', player.currentTime() || 0); });
    player.on('ended', function() { push('complete', player.currentTime() || 0); flush(); });

    var heartbeat = setInterval(function() {
      if (!player.paused()) { push('heartbeat', player.currentTime() || 0); flush(); }
    }, cfg.heartbeatSeconds * 1000);

    player.on('dispose', function() {
      clearInterval(heartbeat);
      flush();
    });

    // --- Recuperacion ante 403 (firma caducada a mitad de clase) ---------
    // Los segmentos llevan la firma horneada en la playlist; si caduca a
    // mitad de leccion, el reproductor ve una rafaga de 403 y dispara error.
    // Un solo reintento por caducidad: tras cada error, guardar la posicion,
    // recargar la playlist con cache-buster (playlist.php vuelve a firmar con
    // Reelo si la firma anterior caduco), restaurar la posicion y reanudar.
    // recovered se resetea en el primer 'playing' posterior: una sesion larga
    // que sufra varias caducidades (una cada firma) se recupera cada vez. Si
    // la recarga no llega a reproducir, recovered sigue true y el siguiente
    // error muestra el mensaje explicito.
    var recovered = false;

    function showSessionExpired() {
      var el = document.getElementById(targetId);
      if (!el) return;
      var msg = document.createElement('div');
      msg.setAttribute('role', 'alert');
      msg.className = 'alert alert-warning';
      msg.style.cssText = 'margin:0.5em 0;padding:0.6em 1em;';
      msg.textContent = {$expiredjson};
      el.parentNode.insertBefore(msg, el.nextSibling);
    }

    player.on('error', function() {
      if (recovered) {
        showSessionExpired();
        return;
      }
      recovered = true;
      var pos = player.currentTime() || 0;
      var sep = cfg.playlistUrl.indexOf('?') === -1 ? '?' : '&';
      player.src({ src: cfg.playlistUrl + sep + 'cb=' + Date.now(), type: 'application/x-mpegURL' });
      player.one('playing', function() { recovered = false; });
      if (pos > 0) {
        player.one('loadedmetadata', function() {
          player.currentTime(pos);
          player.play();
        });
      } else {
        player.play();
      }
    });

    if (watermarkLabel) {
      var wm = ReeloWatermark.attach(player, {
        label: watermarkLabel,
        tamperLimit: 3,
        onTamper: function(count, position) {
          push('tamper', position);
          flush();
        }
      });

      // El watermark flotante se posiciona en pixeles absolutos calculados
      // con el tamano del reproductor en ese instante, y solo se recalcula en
      // su temporizador aleatorio (20-40 s). Al entrar en pantalla completa o
      // girar la pantalla, el reproductor cambia de tamano y esa posicion
      // puede quedar fuera: el watermark se ve a medias o desaparece. El
      // retardo es necesario porque justo despues del cambio
      // getBoundingClientRect aun devuelve el tamano anterior.
      var recolocar = function() {
        setTimeout(function() {
          try { wm.reposition(); } catch (e) {}
        }, 120);
      };
      player.on('fullscreenchange', recolocar);
      window.addEventListener('resize', recolocar);
      window.addEventListener('orientationchange', recolocar);
      player.on('dispose', function() {
        window.removeEventListener('resize', recolocar);
        window.removeEventListener('orientationchange', recolocar);
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', waitForEl);
  } else {
    waitForEl();
  }
})();
</script>
HTML;
}