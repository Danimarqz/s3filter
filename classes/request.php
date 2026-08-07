<?php
/**
 * Datos y cabeceras de la petición HTTP en curso.
 *
 * @package   filter_s3video
 */

namespace filter_s3video;

defined('MOODLE_INTERNAL') || die();

/**
 * IP del cliente y política CORS de los endpoints del plugin.
 */
class request {

    /**
     * IP de quien pide, tolerando los proxies inversos habituales.
     *
     * @return string
     */
    public static function ip(): string {
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
    public static function send_cors_headers(bool $allowpost = false): void {
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
}
