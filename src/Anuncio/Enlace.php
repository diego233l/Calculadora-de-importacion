<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Anuncio;

/**
 * Validación de los enlaces que pega el usuario (protección SSRF).
 *
 * Solo se aceptan enlaces https de los hosts de mobile.de de la lista cerrada HOSTS. Del enlace solo se
 * conserva el número del anuncio: la URL que se usará (para descargar o para mostrar) se reconstruye siempre
 * a partir de ese id, de modo que nada de lo que pegó el usuario llega tal cual a ninguna petición.
 * Función pura: no hace ninguna petición de red.
 */
final class Enlace
{
    public const HOSTS = ['suchen.mobile.de', 'www.mobile.de', 'm.mobile.de'];
    private const MAX_LONGITUD = 2048;

    /** ¿Es una URL https de uno de los hosts de mobile.de, sin credenciales ni puerto raro? (para validar cada redirección) */
    public static function hostPermitido(string $url): bool
    {
        if (preg_match('/[\x00-\x20\x7f-\xff\\\\]/', $url) || !preg_match('#^https://#i', $url)) {
            return false;
        }
        $p = parse_url($url);
        return $p !== false && in_array(strtolower($p['host'] ?? ''), self::HOSTS, true)
            && !isset($p['user']) && !isset($p['pass']) && (!isset($p['port']) || $p['port'] === 443);
    }

    /** @return array{ok: true, id: string, host: string, canonica: string}|array{ok: false, error: string} */
    public static function validar(string $texto): array
    {
        $enlace = trim($texto);
        if ($enlace === '') {
            return self::error('Pega el enlace del anuncio de mobile.de.');
        }
        if (strlen($enlace) > self::MAX_LONGITUD) {
            return self::error('El enlace es demasiado largo.');
        }
        // Espacios, saltos de línea, caracteres de control, barras invertidas y todo lo que no sea ASCII
        // (hosts con letras parecidas, como «mobıle.de») se rechazan antes de analizar nada.
        if (preg_match('/[\x00-\x20\x7f-\xff\\\\]/', $enlace)) {
            return self::error('El enlace contiene caracteres no válidos. Cópialo de nuevo desde la barra de direcciones.');
        }
        if (!preg_match('#^https://#i', $enlace)) {
            return self::error('El enlace debe empezar por https:// y ser de mobile.de.');
        }
        if (!preg_match('#^https://([a-z0-9.-]+)(?::(\d{1,5}))?((?:/[^?\#]*)?)(?:\?([^\#]*))?(?:\#.*)?$#i', $enlace, $m)) {
            return self::error('No reconozco ese enlace. Debe ser la dirección completa de un anuncio de mobile.de.');
        }
        $host = strtolower($m[1]);
        if (!in_array($host, self::HOSTS, true)) {
            return self::error('Solo se admiten enlaces de mobile.de (suchen.mobile.de, www.mobile.de o m.mobile.de).');
        }
        if ($m[2] !== '' && $m[2] !== '443') {
            return self::error('El enlace no puede usar un puerto distinto del habitual.');
        }
        // Segunda comprobación independiente con parse_url: debe coincidir con lo anterior
        $partes = parse_url($enlace);
        if ($partes === false || strtolower($partes['host'] ?? '') !== $host
            || isset($partes['user']) || isset($partes['pass']) || strtolower($partes['scheme'] ?? '') !== 'https') {
            return self::error('No reconozco ese enlace. Debe ser la dirección completa de un anuncio de mobile.de.');
        }

        $id = self::extraerId($m[3] ?? '', $m[4] ?? '');
        if ($id === null) {
            return self::error('No encuentro el número del anuncio en el enlace. Abre el anuncio en mobile.de y copia la dirección completa.');
        }
        return [
            'ok' => true,
            'id' => $id,
            'host' => $host,
            'canonica' => 'https://suchen.mobile.de/fahrzeuge/details.html?id=' . $id,
        ];
    }

    /** Parámetro «id» de la consulta o, si no está, el último número largo del path (p. ej. «…/123456789.html»). */
    private static function extraerId(string $path, string $consulta): ?string
    {
        if ($consulta !== '') {
            parse_str($consulta, $parametros);
            $id = $parametros['id'] ?? null;
            if (is_string($id) && preg_match('/^\d{6,12}$/', $id)) {
                return $id;
            }
        }
        if (preg_match_all('/(?<!\d)(\d{6,12})(?!\d)/', $path, $coincidencias) && $coincidencias[1]) {
            return end($coincidencias[1]);
        }
        return null;
    }

    private static function error(string $mensaje): array
    {
        return ['ok' => false, 'error' => $mensaje];
    }
}
