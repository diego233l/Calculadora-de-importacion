<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Web;

/**
 * Registro de sucesos en /var/lib/<proyecto>/registro.log (una línea por suceso). Nunca guarda datos personales:
 * ni la IP completa ni el contenido de los formularios. Si no se puede escribir, se ignora en silencio.
 */
final class Registro
{
    private const MAX_BYTES = 5_000_000;

    public static function escribir(string $dirVar, string $tipo, string $mensaje = ''): void
    {
        $ruta = $dirVar . '/registro.log';
        $linea = gmdate('c') . ' ' . preg_replace('/[^a-z0-9_.-]/i', '_', $tipo) . ' '
            . str_replace(["\r", "\n"], ' ', mb_substr($mensaje, 0, 500)) . "\n";
        try {
            if (is_file($ruta) && filesize($ruta) > self::MAX_BYTES) {
                @rename($ruta, $ruta . '.1');
            }
            @file_put_contents($ruta, $linea, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // sin registro no se rompe nada
        }
    }
}
