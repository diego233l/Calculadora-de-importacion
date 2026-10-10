<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Web;

/** Rutas y parámetros de la aplicación web. Nada aquí es secreto: los secretos se generan en el directorio de datos. */
final class Config
{
    /** Datos generados en ejecución (caché, SQLite, logs, clave de CSRF). Propiedad de www-data, fuera del repositorio. */
    public const DIR_VAR_POR_DEFECTO = '/var/lib/calculadora-importacion';
    public const ZONA_HORARIA = 'Europe/Madrid';
    /** Tamaño máximo de un formulario enviado (bytes). Los nuestros pesan menos de 3 KB. */
    public const MAX_CUERPO = 32768;
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

    /** Datos del titular para el aviso legal (data/sitio.json). */
    public static function sitio(): array
    {
        $texto = @file_get_contents(self::dirData() . '/sitio.json');
        $datos = $texto !== false ? json_decode($texto, true) : null;
        return is_array($datos) ? $datos : [];
    }
}
