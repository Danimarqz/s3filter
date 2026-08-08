<?php
/**
 * Empaquetado de la firma de CloudFront: URL firmada y descarga server-side.
 *
 * La firma la emite Impronta (ver impronta_api::signature) y ya solo la usan los
 * subtítulos, que este plugin descarga y sirve él.
 *
 * Aquí hubo también un modo cookie, que ponía la firma en el navegador del
 * alumno como cookies CloudFront-*. Se retiró: esas cookies cubren
 * "Materia/Clase/*" durante horas, o sea la clase entera, en un sitio del que
 * se pueden copiar. Es exactamente lo que el vídeo servido segmento a segmento
 * existe para impedir, y tener las dos cosas a la vez habría dejado abierta la
 * puerta que la otra vigila.
 *
 * @package   filter_impronta
 */

namespace filter_impronta;

defined('MOODLE_INTERNAL') || die();

/**
 * Firma en la URL y descarga server-side del media.
 */
class cloudfront {

    /**
     * Pega la query de una firma a una URL de CloudFront.
     *
     * Hoy solo lo usan los subtítulos, y la URL firmada que devuelve NO baja al
     * navegador: la descarga playlist.php y sirve el contenido él. El vídeo ya
     * no pasa por aquí — sus segmentos los firma el backend de uno en uno,
     * después de comprobar que el alumno sigue teniendo acceso.
     *
     * @param string $url
     * @param array $signature como la devuelve impronta_api::signature()
     * @return string
     */
    public static function sign_url(string $url, array $signature): string {
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
                CURLOPT_USERAGENT => 'Impronta-Moodle-HLS-Proxy',
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
            error_log("filter_impronta: fetch {$attempt}/{$retries} failed for [{$safeurl}]. "
                . "HTTP {$http}. curl [{$err}] {$errmsg}");
        }

        return false;
    }
}
