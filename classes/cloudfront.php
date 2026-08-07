<?php
/**
 * Empaquetado de la firma de CloudFront: URLs firmadas, cookies y descarga.
 *
 * La firma la emite Reelo (ver reelo_api::signature). Aquí solo se decide cómo
 * viaja hasta el navegador y cómo se pide el media con ella.
 *
 * @package   filter_s3video
 */

namespace filter_s3video;

defined('MOODLE_INTERNAL') || die();

/**
 * Firma en la URL, firma en cookies, y descarga server-side del media.
 */
class cloudfront {

    /**
     * Pega la query de una firma a una URL de CloudFront.
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
     * @param array $signature como la devuelve reelo_api::signature()
     * @param bool $cookiemode
     * @return string
     */
    public static function sign_url(string $url, array $signature, bool $cookiemode = false): string {
        if ($cookiemode) {
            return $url;
        }
        $separator = strpos($url, '?') === false ? '?' : '&';
        return $url . $separator . $signature['query'];
    }

    /**
     * Codifica una clave segmento a segmento, dejando las barras.
     *
     * @param string $path
     * @return string
     */
    public static function encode_key(string $path): string {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
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
     * @param string $baseurl raíz del media, como la devuelve reelo_api::signature
     * @return string dominio para la cookie (con punto inicial) o ''
     */
    public static function cookie_domain(string $baseurl): string {
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
     * @param array $signature como la devuelve reelo_api::signature()
     * @return bool false si el tenant no admite modo cookie o la firma no sirve
     */
    public static function set_cookies(array $signature): bool {
        $domain = self::cookie_domain($signature['baseurl'] ?? '');
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
     * Descarga una URL que ya lleva firma válida, con un único reintento rápido.
     *
     * NO hay sleep entre reintentos: esto se invoca en cada petición de playlist
     * y de subtítulo, y 3 reintentos con sleep(1) y timeout 15 podían bloquear un
     * worker de PHP-FPM hasta 45 s bajo carga (DoS autoinfligido).
     *
     * @param string $url
     * @param int $retries
     * @return string|false
     */
    public static function fetch(string $url, int $retries = 2) {
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
            error_log("filter_s3video: fetch {$attempt}/{$retries} failed for [{$safeurl}]. "
                . "HTTP {$http}. curl [{$err}] {$errmsg}");
        }

        return false;
    }
}
