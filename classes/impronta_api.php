<?php
/**
 * Cliente del backend de Impronta.
 *
 * Es el único sitio del plugin que habla con la API del tenant, y por tanto el
 * único que toca el apikey. Nada de lo que hay aquí puede ejecutarse en el
 * navegador del alumno.
 *
 * @package   filter_impronta
 */

namespace filter_impronta;

defined('MOODLE_INTERNAL') || die();

/**
 * Firmas de CloudFront y analítica, pedidas a Impronta con el apikey del tenant.
 */
class impronta_api {

    /**
     * Endpoint de la API de Impronta. Constante a propósito, NO configurable desde
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
     * Ojo: el dominio del MEDIA no se configura en ningún sitio a mano. Viene en
     * cada respuesta de /moodle/authorize (campo baseurl), así que ya es dinámico
     * por tenant sin que el plugin sepa nada en el play; y en el registro del sitio
     * (PUT /moodle/site) se guarda oculto para que el poster se construya como
     * string sin llamar a la API en el render.
     *
     * # Cambiar esto es desplegar el plugin, y ese es el precio a pagar
     *
     * El 2026-08-08 el dominio anterior (reelo.danimarqz.dev) dejó de resolver y
     * TODAS las llamadas al backend murieron en el DNS, sin log ni error
     * distinguible: el plugin solo veía "servicio no disponible". Ser constante
     * es lo que evita el robo del apiToken, pero también significa que un
     * dominio muerto solo se arregla redesplegando. Si vuelve a cambiar, esta
     * línea es lo primero que hay que mirar.
     *
     * Va a app.impronta.video y NO a impronta.video: el segundo es el sitio
     * comercial, estático y servido desde Cloudflare, donde no hay ningún API
     * Gateway detrás. La API vive en la distribución de la app.
     */
    const URL = 'https://app.impronta.video/api';

    /**
     * Identificador seudónimo y estable del alumno.
     *
     * Impronta recibe esto en vez del id, el nombre o el DNI de Moodle: basta para
     * contar espectadores distintos y seguir el progreso de un alumno, y no
     * sirve de nada a quien no tenga el secreto de este sitio.
     *
     * @param int $userid usuario firmado en el token (0 = el de la sesión)
     * @return string vacío para invitados y peticiones anónimas
     */
    public static function subject(int $userid = 0): string {
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

        $secret = (string) config::get('secretkey', '');
        if ($secret === '') {
            return '';
        }
        return substr(hash('sha256', $userid . '|' . $secret), 0, 24);
    }

    /**
     * Pide al backend de Impronta una firma de CloudFront para el prefijo de una
     * clase.
     *
     * Devuelve un array con:
     *   baseurl   - raíz del media de este tenant, sin barra final
     *   query     - "Policy=...&Signature=...&Key-Pair-Id=...", listo para pegar
     *   expiresat - timestamp unix en el que la firma deja de valer
     *   master    - nombre real del master de la clase
     *
     * Una firma cubre el master, las dos renditions, todos los segmentos y los
     * subtítulos de esa clase, porque Impronta firma una policy cuyo recurso acaba
     * en "*". Se cachea hasta poco antes de caducar.
     *
     * @param string $path ruta lógica Materia/Clase
     * @param string|null $reason parámetro de salida: 'denied', 'unavailable' o null
     * @param int $userid usuario firmado en el token (0 = usar el de la sesión)
     * @return array|null null si falla
     */
    public static function signature(string $path, ?string &$reason = null, int $userid = 0): ?array {
        global $CFG;

        $reason = null;

        $cache = \cache::make_from_params(\cache_store::MODE_APPLICATION, 'filter_impronta', 'signatures');
        $cachekey = sha1($path);

        $cached = $cache->get($cachekey);
        if (is_array($cached) && isset($cached['expiresat']) && $cached['expiresat'] > time() + 300) {
            return $cached;
        }

        // La clase curl de Moodle vive en filelib.php y NO se autocarga: en una
        // página normal suele estar cargada ya por otro include, pero playlist.php
        // es un endpoint ligero y ahí no lo está ("Class curl not found").
        require_once($CFG->libdir . '/filelib.php');

        $apiurl = rtrim(self::URL, '/');
        $apikey = config::required('apikey');

        $payload = ['videoPath' => $path];
        // Sin el userid del token, las peticiones que vienen del webview de la app
        // (sin cookie) mandarían el subject vacío y Impronta perdería de quién es la
        // firma.
        $subject = self::subject($userid);
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
            debugging('filter_impronta: Impronta API unreachable: ' . $curl->error, DEBUG_NORMAL);
            $reason = 'unavailable';
            return null;
        }
        if ($httpcode === 401 || $httpcode === 403 || $httpcode === 409) {
            // Apikey mala o revocada, o tenant desactivado. No es transitorio.
            debugging('filter_impronta: Impronta API rejected this site (HTTP ' . $httpcode . ')', DEBUG_NORMAL);
            $reason = 'denied';
            return null;
        }
        if ($httpcode < 200 || $httpcode >= 300) {
            debugging('filter_impronta: Impronta API returned HTTP ' . $httpcode, DEBUG_NORMAL);
            $reason = 'unavailable';
            return null;
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)
                || empty($decoded['baseUrl'])
                || empty($decoded['policy'])
                || empty($decoded['signature'])
                || empty($decoded['keyPairId'])) {
            debugging('filter_impronta: malformed response from the Impronta API', DEBUG_NORMAL);
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
     * Pide al backend la playlist de una clase, ya lista para servir.
     *
     * Sustituye a signature() para el vídeo. La diferencia no es de estilo: una
     * firma de CloudFront ya emitida NO SE PUEDE REVOCAR, y la que devuelve
     * signature() cubre "Materia/Clase/*", así que mientras dure vale para
     * bajarse la clase entera. La playlist que devuelve esto apunta cada
     * segmento a una Lambda que comprueba el bloqueo antes de servirlo, y no
     * lleva ninguna firma dentro.
     *
     * NO se cachea, al contrario que signature(). Cada llamada abre una sesión
     * de reproducción con su propio identificador; reutilizar una respuesta
     * haría que dos alumnos compartieran sesión, y el recuento de sesiones
     * simultáneas -que es lo que detecta cuentas compartidas- dejaría de
     * significar nada.
     *
     * Devuelve el cuerpo decodificado: sessionId, playlist, playlistUrl,
     * segmentCount, heartbeatSeconds, watermark.
     *
     * @param string $path ruta lógica Materia/Clase
     * @param string|null $reason parámetro de salida: 'denied', 'unavailable' o null
     * @param int $userid usuario firmado en el token (0 = usar el de la sesión)
     * @return array|null null si falla
     */
    public static function playlist(string $path, ?string &$reason = null, int $userid = 0,
            string $authorizationgroupid = ''): ?array {
        global $CFG, $USER;

        $reason = null;

        // Ver signature(): la clase curl de Moodle no se autocarga y este es un
        // endpoint ligero donde nadie la ha incluido ya.
        require_once($CFG->libdir . '/filelib.php');

        // El backend distingue "viene del Moodle de esta academia, de parte de
        // este alumno" de "viene con el JWT de un alumno" por si este campo va
        // vacío. Si lo mandáramos vacío intentaría validar el apikey como JWT y
        // respondería 401, que no se parece en nada al problema real.
        $subject = self::subject($userid);
        if ($subject === '') {
            debugging('filter_impronta: no stable subject for this viewer', DEBUG_NORMAL);
            $reason = 'denied';
            return null;
        }

        $apikey = config::required('apikey');

        $curl = new \curl();
        $curl->setHeader([
            'Authorization: Bearer ' . $apikey,
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        // El backend necesita la etiqueta exacta para los segmentos quemados.
        // Se resuelve aquí, server-to-server: nunca viaja en la URL ni se deja
        // a JavaScript decidirla.
        $watermarkuser = null;
        if ($userid > 0) {
            $watermarkuser = \core_user::get_user($userid);
        } else if (isloggedin() && !isguestuser()) {
            $watermarkuser = $USER;
        }
        $payload = [
            'classId' => $path,
            'userId' => $subject,
            // La IP del ALUMNO. Sin esto, Impronta registraría la del servidor
            // de este Moodle en todas las sesiones -la petición la hace el PHP,
            // no el navegador-, y el administrador vería una IP que parece
            // informar y no informa de nada.
            'ip' => request::ip(),
        ];
        if ($watermarkuser && !empty($watermarkuser->id)) {
            $payload['watermarkLabel'] = watermark::label($watermarkuser);
        }
        if ($authorizationgroupid !== '') {
            $payload['authorizationGroupId'] = $authorizationgroupid;
        }
        $response = $curl->post(rtrim(self::URL, '/') . '/player/playlist', json_encode($payload), [
            'CURLOPT_TIMEOUT' => 10,
            'CURLOPT_CONNECTTIMEOUT' => 5,
            'CURLOPT_FOLLOWLOCATION' => 0,
        ]);

        $info = $curl->get_info();
        $httpcode = (int) ($info['http_code'] ?? 0);

        if ($curl->get_errno()) {
            debugging('filter_impronta: Impronta API unreachable: ' . $curl->error, DEBUG_NORMAL);
            $reason = 'unavailable';
            return null;
        }
        if ($httpcode === 401 || $httpcode === 403 || $httpcode === 409) {
            // Apikey mala o revocada, tenant desactivado, alumno bloqueado, o
            // una clase que todavía está en el formato antiguo de pistas
            // separadas. Ninguno es transitorio.
            debugging('filter_impronta: Impronta API rejected this request (HTTP ' . $httpcode . ')', DEBUG_NORMAL);
            $reason = 'denied';
            return null;
        }
        if ($httpcode < 200 || $httpcode >= 300) {
            debugging('filter_impronta: Impronta API returned HTTP ' . $httpcode, DEBUG_NORMAL);
            $reason = 'unavailable';
            return null;
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded) || empty($decoded['playlist'])) {
            debugging('filter_impronta: malformed playlist response from the Impronta API', DEBUG_NORMAL);
            $reason = 'unavailable';
            return null;
        }

        return $decoded;
    }

    /**
     * Cuánto se recuerda el identificador de una sesión de reproducción.
     *
     * Basta con que cubra una clase larga. Pasado eso se olvida, y el motivo no
     * es el espacio: un identificador viejo mandado a un latido resucitaría en
     * Impronta una sesión que ya había muerto por inactividad, y el recuento de
     * sesiones simultáneas -que es lo que detecta cuentas compartidas- contaría
     * fantasmas.
     */
    const SESSION_MEMORY = 21600;

    /**
     * Clave de caché de la sesión de un alumno en una clase.
     *
     * Se construye sobre subject() y no sobre el id de usuario porque ya
     * resuelve la identidad por los dos caminos -sesión de navegador y token
     * firmado de la app- y no expone el id en la caché.
     *
     * Es por (alumno, clase, playbackid). El playbackid lo genera player.php
     * por render cuando el filtro no trae uno, así que cada instancia de
     * reproductor guarda y late SU sesión: una recarga del mismo vídeo ya no
     * roba los latidos de la reproducción anterior.
     *
     * Esto dejó de ser solo telemetría cuando la lambda de watermark pasó a
     * exigir sesión viva: matar la sesión del reproductor en marcha congela el
     * vídeo en el primer segmento marcado (incidente del 2026-08-31). La
     * lambda además renueva sesiones caducadas por inactividad con token
     * válido, así que el peor caso ya no es un vídeo colgado, pero no por eso
     * hay que volver a compartir clave: la sesión renovada pierde la etiqueta
     * del watermark y el recuento de sesiones simultáneas deja de tener
     * sentido.
     *
     * @param string $path
     * @param int $userid
     * @return string vacío si no hay alumno identificable
     */
    private static function session_key(string $path, int $userid, string $playbackid = ''): string {
        $subject = self::subject($userid);
        return $subject === '' ? '' : sha1($subject . '|' . $path . '|' . $playbackid);
    }

    /**
     * Guarda el identificador de la sesión que acaba de abrir la playlist.
     *
     * Existe porque quien pide la playlist es el reproductor de vídeo, no
     * nuestro JavaScript: la respuesta lleva el identificador en una cabecera
     * que el JS no llega a ver. Guardándolo aquí, el latido puede recuperarlo
     * sin que el navegador tenga que conocerlo, que además es mejor: el cliente
     * no puede latir por una sesión que no sea la suya.
     *
     * @param string $path
     * @param int $userid
     * @param string $sessionid
     */
    public static function remember_session(string $path, int $userid, string $sessionid,
            string $playbackid = ''): void {
        $key = self::session_key($path, $userid, $playbackid);
        if ($key === '' || $sessionid === '') {
            return;
        }
        $cache = \cache::make_from_params(\cache_store::MODE_APPLICATION, 'filter_impronta', 'sessions');
        $cache->set($key, ['id' => $sessionid, 'at' => time()]);
    }

    /**
     * Recupera el identificador de sesión guardado por remember_session().
     *
     * @param string $path
     * @param int $userid
     * @return string vacío si no hay ninguno vigente
     */
    public static function recall_session(string $path, int $userid, string $playbackid = ''): string {
        $key = self::session_key($path, $userid, $playbackid);
        if ($key === '') {
            return '';
        }
        $cache = \cache::make_from_params(\cache_store::MODE_APPLICATION, 'filter_impronta', 'sessions');
        $stored = $cache->get($key);
        if (!is_array($stored) || empty($stored['id'])) {
            return '';
        }
        if (time() - (int) ($stored['at'] ?? 0) > self::SESSION_MEMORY) {
            return '';
        }
        return (string) $stored['id'];
    }

    /**
     * Manda un latido de la sesión de reproducción y devuelve lo que conteste
     * Impronta: {evicted, blocked, heartbeatSeconds}.
     *
     * `watchedSeconds` no es telemetría, es el DENOMINADOR de la detección de
     * descarga masiva: sin él un alumno viendo clase es indistinguible de
     * alguien que se la está bajando, porque los dos piden segmentos a ritmo
     * parecido durante un rato. Impronta lo acota al intervalo entre latidos, así
     * que inflarlo no sirve de nada.
     *
     * @param string $path
     * @param int $userid usuario firmado en el token (0 = el de la sesión)
     * @param string $sessionid
     * @param int $watched segundos de vídeo consumidos desde el latido anterior
     * @return array|null null si falla
     */
    public static function heartbeat(string $path, int $userid, string $sessionid, int $watched,
            string $authorizationgroupid = ''): ?array {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');

        $subject = self::subject($userid);
        if ($subject === '' || $sessionid === '') {
            return null;
        }

        $curl = new \curl();
        $curl->setHeader([
            'Authorization: Bearer ' . config::required('apikey'),
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        $payload = [
            'classId' => $path,
            'sessionId' => $sessionid,
            // Ver playlist(): la del alumno, no la de este servidor.
            'ip' => request::ip(),
            'watchedSeconds' => $watched,
            'userId' => $subject,
        ];
        if ($authorizationgroupid !== '') {
            $payload['authorizationGroupId'] = $authorizationgroupid;
        }
        $response = $curl->post(rtrim(self::URL, '/') . '/player/heartbeat', json_encode($payload), [
            // Corto a propósito: esto corre cada dos minutos por cada alumno
            // reproduciendo. Un Impronta lento no puede clavar un worker de
            // PHP-FPM por cada uno, que es un DoS autoinfligido.
            'CURLOPT_TIMEOUT' => 5,
            'CURLOPT_CONNECTTIMEOUT' => 3,
            'CURLOPT_FOLLOWLOCATION' => 0,
        ]);

        $info = $curl->get_info();
        $httpcode = (int) ($info['http_code'] ?? 0);
        if ($curl->get_errno() || $httpcode < 200 || $httpcode >= 300) {
            debugging('filter_impronta: heartbeat failed (HTTP ' . $httpcode . ')', DEBUG_NORMAL);
            return null;
        }

        $decoded = json_decode((string) $response, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Registers this Moodle origin for the tenant.  The API key and secret are
     * read only on the server; the browser receives neither credential.
     *
     * @return bool
     */
    public static function register_site(): bool {
        global $CFG;

        if (config::get('apikey', '') === '' || config::get('secretkey', '') === '') {
            debugging('filter_impronta: API key and local secret are required before site registration', DEBUG_NORMAL);
            return false;
        }
        require_once($CFG->libdir . '/filelib.php');
        $origin = rtrim((string) $CFG->wwwroot, '/');
        $curl = new \curl();
        $curl->setHeader([
            'Authorization: Bearer ' . config::required('apikey'),
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        $curl->put(rtrim(self::URL, '/') . '/moodle/site', json_encode(['origin' => $origin]), [
            'CURLOPT_TIMEOUT' => 10,
            'CURLOPT_CONNECTTIMEOUT' => 5,
            'CURLOPT_FOLLOWLOCATION' => 0,
        ]);
        $info = $curl->get_info();
        $status = (int) ($info['http_code'] ?? 0);
        if ($curl->get_errno() || $status < 200 || $status >= 300) {
            debugging('filter_impronta: Moodle site registration failed (HTTP ' . $status . ')', DEBUG_NORMAL);
            return false;
        }

        // El registro trae de regalo el dominio del media del tenant: es el dato
        // que el poster necesita y que NO puede pedirse en el render de un curso
        // (serían 8 llamadas síncronas por página). Aquí es estático y de una vez:
        // si el tenant cambia de CDN, basta volver a registrar el sitio. Se guarda
        // sin ajuste visible a propósito: no es algo que el administrador deba
        // poder tocar sin pasar por el registro.
        $decoded = json_decode((string) $curl->getResponse(), true);
        if (is_array($decoded) && !empty($decoded['mediaDomain'])) {
            set_config('mediabase', 'https://' . rtrim((string) $decoded['mediaDomain'], '/'), 'filter_impronta');
        }
        return true;
    }

    /**
     * Reenvía un lote de eventos de analítica al POST /events de Impronta con el
     * apikey del tenant. Lo llama solo events.php, server-side: el apikey no
     * puede aparecer nunca en una página que el navegador del alumno pueda leer.
     *
     * Se dispara y se olvida, con un curl desacoplado: un backend de Impronta lento
     * o caído no puede clavar un worker de PHP-FPM durante segundos por cada
     * heartbeat (un flush por espectador cada 15 s es un DoS autoinfligido si el
     * relay bloquea). Las credenciales y la carga van en ficheros temporales
     * 0600, nunca en la línea de comandos de curl (ps las expondría). Si popen
     * está desactivado, cae a un POST síncrono corto.
     *
     * @param array $payload {subject, events:[{videoPath,type,positionSeconds,ts}]}
     * @return bool true si el relay salió (no necesariamente si llegó)
     */
    /**
     * ¿Hay un curl(1) ejecutable? Se cachea en la peticion: es una llamada a la
     * shell y post_events se invoca una vez por lote de analitica.
     *
     * @return bool
     */
    private static function has_curl_cli(): bool {
        static $tiene = null;
        if ($tiene !== null) {
            return $tiene;
        }
        $salida = @shell_exec('command -v curl 2>/dev/null');
        $tiene = is_string($salida) && trim($salida) !== '';
        if (!$tiene) {
            error_log('filter_impronta: curl(1) no disponible; la analitica se envia en modo sincrono');
        }
        return $tiene;
    }

    public static function post_events(array $payload): bool {
        global $CFG;

        $apikey = (string) config::get('apikey', '');
        if ($apikey === '') {
            error_log('filter_impronta: no apikey configured; dropping analytics batch');
            return false;
        }

        $endpoint = rtrim(self::URL, '/') . '/events';
        $json = json_encode($payload);
        if ($json === false) {
            return false;
        }

        // Sin popen, sin curl en el PATH o en Windows (donde 'cmd &' no es
        // fire-and-forget fiable): POST sincrono corto. Mejor un evento
        // bloqueante ocasional que perder la analitica.
        //
        // La comprobacion de curl(1) importa: el camino asincrono no puede
        // saber si el proceso hijo ha fallado, asi que sin ella un servidor sin
        // curl instalado descarta TODA la analitica sin una sola linea de log.
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('popen') || !self::has_curl_cli()) {
            // Ver signature(): la clase curl no se autocarga.
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
            if ($httpcode < 200 || $httpcode >= 300) {
                // Se registra el codigo: un 401 (apikey mala) y un 502
                // (backend caido) se arreglan de formas distintas, y sin esto
                // los dos se ven igual desde fuera: estadisticas vacias.
                error_log("filter_impronta: POST /events devolvio HTTP {$httpcode}");
                return false;
            }
            return true;
        }

        $payloadfile = tempnam(sys_get_temp_dir(), 'impronta_evt_');
        $cfgfile = tempnam(sys_get_temp_dir(), 'impronta_cfg_');
        if ($payloadfile === false || $cfgfile === false) {
            return false;
        }
        @file_put_contents($payloadfile, $json);
        // Config de curl: mantiene el apikey fuera de ps y del historial de la
        // shell. tempnam crea los dos ficheros con modo 0600.
        $cfg = "silent\n"
            . "output=/dev/null\n"
            . "request=POST\n"
            // Las COMILLAS no son decorativas: en un fichero de config, curl
            // corta el valor en el primer espacio si no va entrecomillado, y
            // un header sin valor lo descarta entero. Sin ellas la peticion
            // salia SIN Authorization, el backend respondia 401 y aqui no se
            // notaba nada -esta rama devuelve true pase lo que pase-. 115
            // lotes de analitica enviados y ninguno registrado.
            //
            // El apikey es hexadecimal, asi que no hay nada que escapar.
            . "header=\"Authorization: Bearer {$apikey}\"\n"
            . "header=\"Content-Type: application/json\"\n"
            . "data-binary=@{$payloadfile}\n"
            . "max-time=5\n"
            . "connect-timeout=3\n"
            // 'fail' hace que un 401 o un 500 sean un fallo de curl (exit 22) y
            // no un exito silencioso. Sin esto, curl termina con 0 aunque el
            // servidor haya rechazado la peticion, que es como 115 lotes se
            // perdieron sin dejar rastro.
            . "fail\n"
            . "url={$endpoint}\n";
        @file_put_contents($cfgfile, $cfg);

        // Los tres comandos van agrupados y el '&' aplica al grupo: la shell
        // hace el fork y pclose() vuelve al instante, que es todo el sentido de
        // este camino -no clavar un worker de PHP-FPM 5 s por cada latido-.
        //
        // El 'logger' es lo que hace que este camino deje de ser ciego. Un hijo
        // desacoplado no puede devolver nada a PHP, asi que si falla la unica
        // forma de enterarse es que lo escriba el: sin esta linea, un apikey
        // caducado o un backend caido se ven exactamente igual que todo bien
        // -estadisticas vacias y ni un log-.
        //
        // El rm va DENTRO del grupo y despues del '||' para que los temporales
        // se borren pase lo que pase: llevan el apikey dentro y se acumulaban
        // en /tmp porque antes el '&' se aplicaba solo a curl.
        $cmd = '( curl --config ' . escapeshellarg($cfgfile)
            . ' >/dev/null 2>&1 || logger -t filter_impronta '
            . escapeshellarg('POST /events fallido (curl no pudo entregar el lote)')
            . ' ; rm -f ' . escapeshellarg($cfgfile) . ' '
            . escapeshellarg($payloadfile) . ' ) &';
        $p = popen($cmd, 'r');
        if ($p === false) {
            return false;
        }
        pclose($p);
        return true;
    }
}
