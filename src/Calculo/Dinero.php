<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Calculo;

final class Dinero
{
    /** Redondea a céntimos (mitad hacia arriba). El pre-redondeo evita errores de representación binaria. */
    public static function redondear(float $importe): float
    {
        return round(round($importe, 6), 2);
    }
}
