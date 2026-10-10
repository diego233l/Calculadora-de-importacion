<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Calculo;

/**
 * Valor venal = precio medio de la Orden anual (fila del BOE) x coeficiente de depreciación por antigüedad.
 * No es un coste: sirve de base al IEDMT y al ITP, así que se devuelve como cálculo intermedio.
 */
final class ValorVenal
{
    /** @return array{partida: array, valor: ?float, antiguedad_anios: int, coeficiente: float} */
    public static function calcular(array $n, array $tablas): array
    {
        $dep = $tablas['depreciacion'];
        $anios = Fechas::aniosCompletos($n['fecha_matriculacion'], $n['fecha_calculo']);

        $tramos = $dep['tramos'];
        usort($tramos, static fn(array $a, array $b): int => $a['desde_anios'] <=> $b['desde_anios']);
        $coef = (float) $tramos[0]['coeficiente'];
        foreach ($tramos as $t) {
            if ($anios >= $t['desde_anios']) {
                $coef = (float) $t['coeficiente'];
            }
        }

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
            'Precio medio de la Orden: %s. Antigüedad: %d %s completos desde la primera matriculación, coeficiente %s %%. %s x %s %% = %s.',
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
