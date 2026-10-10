<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Calculo;

/**
 * Valor venal = precio medio de la Orden anual (fila del BOE) x coeficiente de depreciación por antigüedad.
 * No es un coste: sirve de base al IEDMT y al ITP, así que se devuelve como cálculo intermedio.
 */
final class ValorVenal
{
    /**
     * Coeficiente del anexo IV. Los tramos son «hasta N años» (el día del N-ésimo aniversario aún pertenece
     * al tramo) y el último, con hasta_anios null, es «más de N años». Orden ascendente.
     */
    public static function coeficiente(array $tramos, \DateTimeImmutable $matriculacion, \DateTimeImmutable $calculo): float
    {
        foreach ($tramos as $t) {
            if ($t['hasta_anios'] === null || $calculo <= Fechas::sumarMeses($matriculacion, 12 * (int) $t['hasta_anios'])) {
                return (float) $t['coeficiente'];
            }
        }
        return (float) end($tramos)['coeficiente'];
    }

    /** @return array{partida: array, valor: ?float, antiguedad_anios: int, coeficiente: float} */
    public static function calcular(array $n, array $tablas): array
    {
        $dep = $tablas['depreciacion'];
        $anios = Fechas::aniosCompletos($n['fecha_matriculacion'], $n['fecha_calculo']);

        $coef = self::coeficiente($dep['tramos'], $n['fecha_matriculacion'], $n['fecha_calculo']);

        $verificar = Partida::pendiente($dep);
        if ($n['fila_boe'] === null) {
            return [
                'partida' => Partida::noCalculable(
                    'valor_venal',
                    'Valor venal (BOE)',
                    'Falta la fila del BOE con el precio medio del modelo: sin ella no se puede calcular el valor venal.',
                    $dep['fuente'],
                    $verificar,
                    $dep['url'] ?? null
                ),
                'valor' => null,
                'antiguedad_anios' => $anios,
                'coeficiente' => $coef,
            ];
        }

        $precioBoe = $n['fila_boe']['precio'];
        $valor = Dinero::redondear($precioBoe * $coef);
        $explicacion = sprintf(
            'Precio medio de la Orden: %s. Antigüedad: %d %s completos desde la primera matriculación, que según el anexo IV corresponde a un coeficiente del %s %%. %s x %s %% = %s.',
            Partida::eur($precioBoe),
            $anios,
            $anios === 1 ? 'año' : 'años',
            Partida::num($coef * 100),
            Partida::eur($precioBoe),
            Partida::num($coef * 100),
            Partida::eur($valor)
        );
        return [
            'partida' => Partida::ok('valor_venal', 'Valor venal (BOE)', $valor, $precioBoe, $coef * 100, '%', $dep['fuente'], $explicacion, $verificar, $dep['url'] ?? null),
            'valor' => $valor,
            'antiguedad_anios' => $anios,
            'coeficiente' => $coef,
        ];
    }
}
