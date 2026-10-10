<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Anuncio;

/**
 * Descarga con cURL, con todos los límites a la vez:
 *   una sola petición GET (con, como mucho, 3 redirecciones, cada una dentro de los hosts de mobile.de y por https),
 *   10 s en total, 3 MB como máximo, User-Agent que dice quiénes somos y sin cookies.
 * Si el sitio responde con un bloqueo (403, 429, página de «Access denied» o captcha) NO se insiste ni se disimula: se devuelve
 * el tipo de error «bloqueado» y la web ofrece pegar el texto del anuncio. Nunca se cambia de User-Agent, de cabeceras ni de IP.
 */
final class DescargadorCurl implements Descargador
{
    public const USER_AGENT = 'CalculadoraImportacion/1.0 (+https://github.com/diego233l/calculadora-de-importacion; herramienta personal sin ánimo de lucro)';
    public const TIEMPO_MAX = 10;
    public const BYTES_MAX = Extractor::MAX_BYTES;
    public const REDIRECCIONES_MAX = 3;

    public function obtener(string $url): array
    {
        if (!function_exists('curl_init')) {
            return self::fallo(null, 'sin_curl');
        }
        $actual = $url;
        $inicio = microtime(true);
        for ($salto = 0; $salto <= self::REDIRECCIONES_MAX; $salto++) {
            if (!Enlace::hostPermitido($actual)) {
                return self::fallo(null, 'redireccion');
            }
            $r = $this->peticion($actual, max(1, (int) ceil(self::TIEMPO_MAX - (microtime(true) - $inicio))));
            if ($r['error'] !== null) {
                return self::fallo($r['estado'], $r['error']);
            }
            $estado = $r['estado'];
            if ($estado >= 300 && $estado < 400 && $r['location'] !== null) {
                $actual = self::absoluta($actual, $r['location']);
                continue;
            }
            if (in_array($estado, [401, 403, 407, 429], true)) {
                return self::fallo($estado, 'bloqueado');
            }
            if ($estado === 404 || $estado === 410) {
                return self::fallo($estado, 'no_existe');
            }
            if ($estado >= 500) {
                return self::fallo($estado, 'servidor');
            }
            if ($estado < 200 || $estado >= 300) {
                return self::fallo($estado, 'red');
            }
            if ($r['tipo'] !== '' && !str_contains($r['tipo'], 'html') && !str_contains($r['tipo'], 'xml') && !str_contains($r['tipo'], 'text/plain')) {
                return self::fallo($estado, 'contenido');
            }
            if (self::esDesafio($r['cuerpo'])) {
                return self::fallo($estado, 'bloqueado');
            }
            return ['ok' => true, 'estado' => $estado, 'cuerpo' => $r['cuerpo'], 'tipo_error' => null];
        }
        return self::fallo(null, 'redireccion');
    }

    /** ¿Es la página de un anti-bot (Akamai, Cloudflare, captcha…) en lugar del anuncio? */
    public static function esDesafio(string $cuerpo): bool
    {
        $cabecera = mb_strtolower(substr($cuerpo, 0, 20000), 'UTF-8');
        foreach (['zugriff verweigert', 'access denied', 'pardon our interruption', 'attention required', 'captcha', 'are you a robot', 'bist du ein roboter',
            'unusual traffic', 'verify you are human', 'request blocked'] as $pista) {
            if (str_contains($cabecera, $pista)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{estado: ?int, cuerpo: string, tipo: string, location: ?string, error: ?string} */
    private function peticion(string $url, int $segundos): array
    {
        $cuerpo = '';
        $tipo = '';
        $location = null;
        $demasiado = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => false,                 // las redirecciones se siguen a mano, comprobando cada una
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => min(5, $segundos),
            CURLOPT_TIMEOUT => $segundos,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.5', 'Accept-Language: de-DE,de;q=0.9,es;q=0.5,en;q=0.3'],
            CURLOPT_ENCODING => '',                          // acepta gzip/deflate; el límite de tamaño cuenta lo ya descomprimido
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_COOKIEFILE => '',                        // sin cookies guardadas entre peticiones
            CURLOPT_WRITEFUNCTION => static function ($ch, string $trozo) use (&$cuerpo, &$demasiado): int {
                if (strlen($cuerpo) + strlen($trozo) > self::BYTES_MAX) {
                    $demasiado = true;
                    return 0;                                // aborta la descarga
                }
                $cuerpo .= $trozo;
                return strlen($trozo);
            },
            CURLOPT_HEADERFUNCTION => static function ($ch, string $linea) use (&$tipo, &$location): int {
                if (stripos($linea, 'content-type:') === 0) {
                    $tipo = strtolower(trim(substr($linea, 13)));
                } elseif (stripos($linea, 'location:') === 0) {
                    $location = trim(substr($linea, 9));
                }
                return strlen($linea);
            },
        ]);
        $ok = curl_exec($ch);
        $estado = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        $error = null;
        if ($demasiado) {
            $error = 'demasiado_grande';
        } elseif ($ok === false) {
            $error = $errno === CURLE_OPERATION_TIMEDOUT ? 'tiempo' : 'red';
        }
        return ['estado' => $estado > 0 ? $estado : null, 'cuerpo' => $cuerpo, 'tipo' => $tipo, 'location' => $location, 'error' => $error];
    }

    /** Resuelve el Location de una redirección (absoluto, de raíz o relativo) respecto a la URL actual. */
    private static function absoluta(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $p = parse_url($base);
        $origen = 'https://' . ($p['host'] ?? '');
        if (str_starts_with($location, '//')) {
            return 'https:' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origen . $location;
        }
        return $origen . rtrim(dirname($p['path'] ?? '/'), '/') . '/' . $location;
    }

    /** @return array{ok: false, estado: ?int, cuerpo: null, tipo_error: string} */
    private static function fallo(?int $estado, string $tipo): array
    {
        return ['ok' => false, 'estado' => $estado, 'cuerpo' => null, 'tipo_error' => $tipo];
    }
}
