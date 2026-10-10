<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Web;

/** Petición HTTP ya leída. Los tests la construyen a mano; index.php la construye con las variables globales. */
final class Peticion
{
    public function __construct(
        public readonly string $metodo,
        public readonly string $ruta,
        public readonly array $consulta = [],
        public readonly array $post = [],
        public readonly array $cookies = [],
        public readonly string $ip = '0.0.0.0',
        public readonly bool $https = false,
        public readonly int $longitudCuerpo = 0,
        public readonly string $host = ''
    ) {
    }

    public static function desdeGlobales(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $ruta = (string) parse_url($uri, PHP_URL_PATH);
        $ruta = '/' . ltrim(rawurldecode($ruta), '/');
        $ruta = (string) preg_replace('#/{2,}#', '/', $ruta);
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $ruta,
            $_GET,
            $_POST,
            $_COOKIE,
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
            $https,
            (int) ($_SERVER['CONTENT_LENGTH'] ?? 0),
            self::hostSeguro((string) ($_SERVER['HTTP_HOST'] ?? ''))
        );
    }

    /** La cabecera Host la controla quien hace la petición: solo se usa si parece un nombre de host normal. */
    public static function hostSeguro(string $host): string
    {
        return preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?(:\d{1,5})?$/i', $host) === 1 ? strtolower($host) : '';
    }

    /** Texto de un campo del formulario o de la consulta: nunca un array y recortado a $max caracteres. */
    public static function texto(array $origen, string $clave, int $max = 200): string
    {
        $valor = $origen[$clave] ?? '';
        if (!is_string($valor)) {
            return '';
        }
        $valor = trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $valor) ?? '');
        return mb_substr($valor, 0, $max);
    }

    /** Texto de varias líneas (el que se pega en un cuadro de texto): conserva los saltos de línea y recorta a $max caracteres. */
    public static function textoLargo(array $origen, string $clave, int $max = 60000): string
    {
        $valor = $origen[$clave] ?? '';
        if (!is_string($valor)) {
            return '';
        }
        $valor = str_replace(["\r\n", "\r"], "\n", $valor);
        $valor = trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $valor) ?? '');
        return mb_substr($valor, 0, $max);
    }
}
