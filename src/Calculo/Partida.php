<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Calculo;

/**
 * Una línea del desglose. Siempre las mismas claves, para que la vista no tenga que adivinar:
 *   clave, concepto, importe, base, tipo, tipo_unidad, fuente, url, explicacion, verificar, estimacion, estado, motivo
 * verificar = el dato de la tabla aún no se ha contrastado con la norma; estimacion = es un supuesto editable (no una norma).
 * estado = 'ok' | 'no_calculable' (en ese caso importe es null y motivo explica qué falta).
 */
final class Partida
{
    public static function ok(
        string $clave,
        string $concepto,
        float $importe,
        ?float $base,
        ?float $tipo,
        ?string $tipoUnidad,
        string $fuente,
        string $explicacion,
        bool $verificar,
        ?string $url = null,
        bool $estimacion = false
    ): array {
        return [
            'clave' => $clave,
            'concepto' => $concepto,
            'importe' => $importe,
            'base' => $base,
            'tipo' => $tipo,
            'tipo_unidad' => $tipoUnidad,
            'fuente' => $fuente,
            'url' => $url,
            'explicacion' => $explicacion,
            'verificar' => $verificar,
            'estimacion' => $estimacion,
            'estado' => 'ok',
            'motivo' => null,
        ];
    }

    public static function noCalculable(
        string $clave,
        string $concepto,
        string $motivo,
        string $fuente,
        bool $verificar = false,
        ?string $url = null
    ): array {
        return [
            'clave' => $clave,
            'concepto' => $concepto,
            'importe' => null,
            'base' => null,
            'tipo' => null,
            'tipo_unidad' => null,
            'fuente' => $fuente,
            'url' => $url,
            'explicacion' => $motivo,
            'verificar' => $verificar,
            'estimacion' => false,
            'estado' => 'no_calculable',
            'motivo' => $motivo,
        ];
    }

    /** true si alguno de los nodos de datos lleva la marca VERIFICAR. */
    public static function pendiente(?array ...$nodos): bool
    {
        foreach ($nodos as $nodo) {
            if (is_array($nodo) && !empty($nodo['verificar'])) {
                return true;
            }
        }
        return false;
    }

    public static function aviso(string $codigo, string $texto, string $nivel = 'atencion'): array
    {
        return ['codigo' => $codigo, 'nivel' => $nivel, 'texto' => $texto];
    }

    /** Formatea un porcentaje o un importe al estilo español para las explicaciones ("9,75", "1.118,55"). */
    public static function num(float $valor, int $decimales = 2): string
    {
        $texto = number_format($valor, $decimales, ',', '.');
        if ($decimales > 0 && str_contains($texto, ',')) {
            $texto = rtrim(rtrim($texto, '0'), ',');
        }
        return $texto;
    }

    /** Importe en euros con siempre 2 decimales ("1.118,55 €"). */
    public static function eur(float $valor): string
    {
        return number_format($valor, 2, ',', '.') . ' €';
    }
}
