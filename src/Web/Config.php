<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Web;

/** Rutas y parámetros de la aplicación web. Nada aquí es secreto: los secretos se generan en el directorio de datos. */
final class Config
{
    /** Datos generados en ejecución (caché, SQLite, logs, clave de CSRF). Propiedad de www-data, fuera del repositorio. */
    public const DIR_VAR_POR_DEFECTO = '/var/lib/calculadora-importacion';
    public const ZONA_HORARIA = 'Europe/Madrid';
    /** Tamaño máximo de un formulario enviado (bytes): el texto pegado de un anuncio puede pasar de 60 KB con la codificación del formulario. */
    public const MAX_CUERPO = 262144;
    /** Caracteres que se leen del texto de un anuncio pegado (una página copiada entera ocupa unos 10.000). */
    public const MAX_TEXTO_ANUNCIO = 60000;
    /** Cálculos que puede guardar una misma IP por minuto. */
    public const LIMITE_CALCULOS_POR_MINUTO = 20;
    /** Días que se conserva un cálculo compartido. */
    public const DIAS_CONSERVACION = 365;

    public static function raiz(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function dirData(): string
    {
        return self::raiz() . '/data';
    }

    /** Se puede cambiar con la variable de entorno CALCULADORA_VAR (la usan los tests y el servidor de desarrollo). */
    public static function dirVar(): string
    {
        $entorno = getenv('CALCULADORA_VAR');
        return is_string($entorno) && $entorno !== '' ? rtrim($entorno, '/') : self::DIR_VAR_POR_DEFECTO;
    }

    /**
     * Datos del titular para el aviso legal y la política de privacidad. Valores por defecto en data/sitio.json (en el repositorio,
     * vacíos); los reales van en <dirVar>/sitio.json, fuera del repositorio, para que `git pull` nunca choque con ellos.
     *
     * @return array<string, string>
     */
    public static function sitio(): array
    {
        $leer = static function (string $ruta): array {
            $texto = @file_get_contents($ruta);
            $datos = $texto !== false ? json_decode($texto, true) : null;
            return is_array($datos) ? $datos : [];
        };
        $local = array_filter($leer(self::dirVar() . '/sitio.json'), static fn(mixed $v): bool => is_string($v) && trim($v) !== '');
        return array_merge($leer(self::dirData() . '/sitio.json'), $local);
    }
}
