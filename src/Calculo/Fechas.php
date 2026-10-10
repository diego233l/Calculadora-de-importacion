<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Calculo;

use DateTimeImmutable;

/** Aritmética de fechas pura: nada lee el reloj; la fecha de cálculo siempre llega como dato. */
final class Fechas
{
    /** Acepta solo AAAA-MM-DD válido. */
    public static function parsear(string $fecha): ?DateTimeImmutable
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $m)) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return new DateTimeImmutable($fecha . ' 00:00:00', new \DateTimeZone('UTC'));
    }

    /** Años completos cumplidos entre dos fechas (desde <= hasta). */
    public static function aniosCompletos(DateTimeImmutable $desde, DateTimeImmutable $hasta): int
    {
        return $desde->diff($hasta)->y;
    }

    /** Suma meses sin desbordar al mes siguiente: 31/08 + 6 meses = 28/02 (o 29/02). */
    public static function sumarMeses(DateTimeImmutable $fecha, int $meses): DateTimeImmutable
    {
        $total = (int) $fecha->format('Y') * 12 + ((int) $fecha->format('n') - 1) + $meses;
        $anio = intdiv($total, 12);
        $mes = $total % 12 + 1;
        $dia = min((int) $fecha->format('j'), (int) date('t', mktime(0, 0, 0, $mes, 1, $anio)));
        return new DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $anio, $mes, $dia), new \DateTimeZone('UTC'));
    }

    /** Trimestres naturales que quedan del año contando el de la fecha (enero-marzo = 4, octubre-diciembre = 1). */
    public static function trimestresRestantes(DateTimeImmutable $fecha): int
    {
        return 4 - intdiv((int) $fecha->format('n') - 1, 3);
    }
}
