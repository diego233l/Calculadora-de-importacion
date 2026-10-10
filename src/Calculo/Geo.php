<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Calculo;

final class Geo
{
    /** Distancia en línea recta (gran círculo) entre dos puntos, en km. */
    public static function haversine(float $lat1, float $lon1, float $lat2, float $lon2, float $radioKm = 6371.0): float
    {
        $f1 = deg2rad($lat1);
        $f2 = deg2rad($lat2);
        $df = deg2rad($lat2 - $lat1);
        $dl = deg2rad($lon2 - $lon1);
        $a = sin($df / 2) ** 2 + cos($f1) * cos($f2) * sin($dl / 2) ** 2;
        return 2 * $radioKm * asin(min(1.0, sqrt($a)));
    }

    /**
     * Coordenadas de un código postal alemán a partir del prefijo más largo disponible (3 cifras, luego 2).
     * @param array<string, array{0: float, 1: float}> $prefijos
     * @return array{0: float, 1: float, 2: string}|null  [lat, lon, prefijo usado]
     */
    public static function coordenadasPlz(string $cp, array $prefijos): ?array
    {
        if (strlen($cp) !== 5 || !ctype_digit($cp)) {
            return null;
        }
        foreach ([3, 2] as $largo) {
            $pre = substr($cp, 0, $largo);
            if (isset($prefijos[$pre])) {
                return [(float) $prefijos[$pre][0], (float) $prefijos[$pre][1], $pre];
            }
        }
        return null;
    }
}
