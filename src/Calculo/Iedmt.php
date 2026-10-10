<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Calculo;

/**
 * Impuesto Especial sobre Determinados Medios de Transporte (modelo 576).
 *
 * - Tipo: según las emisiones oficiales de CO2 (WLTP) y el régimen (península y Baleares, o Canarias).
 * - Base de un coche "nuevo": el precio sin IVA alemán.
 * - Base de un coche usado: el valor venal del BOE, minorado por la imposición indirecta que ya llevaba
 *   incorporada cuando estuvo matriculado en el extranjero (regla segunda de la Orden):
 *   BI = VM / (1 + (tipo IVA + tipo IEDMT + otros tipos) / 100).
 */
final class Iedmt
{
    /**
     * @param array{nuevo: bool, precio_neto: float, valor_venal: ?float, tipo_indirecto: float} $ctx
     * @return array{partidas: array, avisos: array, tipo: ?float}
     */
    public static function calcular(array $n, array $tablas, array $ctx): array
    {
        $t = $tablas['iedmt'];
        $concepto = 'Impuesto de matriculación (IEDMT)';
        $avisos = [];
        $regimen = $tablas['ccaa'][$n['ccaa']]['regimen_iedmt'];
        $nodoRegimen = $t['regimenes'][$regimen];
        $verificar = Partida::pendiente($t, $nodoRegimen);
        $fuente = $nodoRegimen['fuente'];
        $url = $t['url'] ?? null;

        if ($n['combustible'] === 'electrico') {
            $co2 = 0.0;
        } elseif ($n['co2_wltp'] === null) {
            return [
                'partidas' => [Partida::noCalculable('iedmt', $concepto,
                    'Falta el dato de emisiones de CO2 (WLTP): sin él no se puede determinar el tipo del impuesto. '
                    . 'Si el CO2 no se acredita con el certificado del fabricante, la ley aplica el tipo más alto ('
                    . Partida::num(max(array_column($nodoRegimen['tramos'], 'tipo'))) . ' %, art. 70.1, epígrafe 4.º b).',
                    $fuente, $verificar, $url)],
                'avisos' => $avisos,
                'tipo' => null,
            ];
        } else {
            $co2 = $n['co2_wltp'];
            if ($n['fecha_matriculacion'] < Fechas::parsear($t['aviso_co2_nedc_antes_de'] ?? '1900-01-01')) {
                $avisos[] = Partida::aviso(
                    'iedmt_co2_nedc',
                    'El coche se matriculó antes de la obligatoriedad del ciclo WLTP: comprueba que el CO2 indicado es el que figura en la ficha técnica.'
                );
            }
        }

        $tramo = self::tramoPorCo2($nodoRegimen['tramos'], $co2);
        $tipoEstatal = (float) $tramo['tipo'];
        // Art. 70.2.a: rigen los tipos que haya aprobado la comunidad autónoma (clave: el límite inferior del tramo)
        $tipoCcaa = $t['incrementos_autonomicos']['efectivos'][$n['ccaa']][(string) (int) $tramo['desde']] ?? null;
        $tipo = $tipoCcaa !== null ? (float) $tipoCcaa : $tipoEstatal;
        if ($tipoCcaa !== null) {
            $fuente .= ' | Tipo autonómico: ' . $t['incrementos_autonomicos']['fuente'];
            $verificar = $verificar || Partida::pendiente($t['incrementos_autonomicos']);
        }

        if ($ctx['nuevo']) {
            $bi = Dinero::redondear($ctx['precio_neto']);
            $explicacionBase = 'Coche nuevo a efectos de IVA: la base es el precio sin IVA alemán (' . Partida::eur($bi) . ').';
        } elseif ($ctx['valor_venal'] === null) {
            return [
                'partidas' => [Partida::noCalculable('iedmt', $concepto,
                    'Falta el valor venal (fila del BOE): sin él no se puede calcular la base del impuesto.', $fuente, $verificar, $url)],
                'avisos' => $avisos,
                'tipo' => $tipo,
            ];
        } else {
            $vm = $ctx['valor_venal'];
            $min = $t['base_imponible_usados_extranjero'];
            $verificar = $verificar || Partida::pendiente($min);
            if (!empty($min['activa'])) {
                $otros = (float) ($min['tipos_otros'] ?? 0);
                $divisor = 1 + ($ctx['tipo_indirecto'] + $tipo + $otros) / 100;
                $bi = Dinero::redondear($vm / $divisor);
                $explicacionBase = sprintf(
                    'Coche usado ya matriculado en el extranjero: se resta del valor venal (%s) la imposición indirecta que lleva incorporada. '
                    . 'Base = %s / (1 + (%s %% IVA + %s %% IEDMT + %s %% otros) / 100) = %s.',
                    Partida::eur($vm), Partida::eur($vm), Partida::num($ctx['tipo_indirecto']), Partida::num($tipo), Partida::num($otros), Partida::eur($bi)
                );
                $fuente .= ' | Base: ' . $min['fuente'];
            } else {
                $bi = $vm;
                $explicacionBase = 'La base es el valor venal del BOE (' . Partida::eur($vm) . ').';
            }
        }

        $importe = Dinero::redondear($bi * $tipo / 100);
        $descripcionCo2 = $n['combustible'] === 'electrico' ? 'Eléctrico (0 g/km)' : Partida::num($co2) . ' g/km de CO2';
        $origenTipo = $tipoCcaa !== null
            ? 'tipo de tu comunidad autónoma; el estatal es ' . Partida::num($tipoEstatal) . ' %'
            : ($regimen === 'canarias' ? 'Canarias' : 'península y Baleares');
        $explicacion = sprintf('%s. %s Tipo %s %% (%s). %s x %s %% = %s.',
            $descripcionCo2, $explicacionBase, Partida::num($tipo), $origenTipo,
            Partida::eur($bi), Partida::num($tipo), Partida::eur($importe));

        return [
            'partidas' => [Partida::ok('iedmt', $concepto, $importe, $bi, $tipo, '%', $fuente, $explicacion, $verificar, $url)],
            'avisos' => $avisos,
            'tipo' => $tipo,
        ];
    }

    /** Tramo cuyo límite inferior (inclusivo o no) cumple el CO2; gana el tramo más alto que lo cumple. */
    public static function tramoPorCo2(array $tramos, float $co2): array
    {
        usort($tramos, static fn(array $a, array $b): int => $a['desde'] <=> $b['desde']);
        $elegido = $tramos[0];
        foreach ($tramos as $tr) {
            $cumple = !empty($tr['desde_inclusivo']) ? $co2 >= $tr['desde'] : $co2 > $tr['desde'];
            if ($cumple) {
                $elegido = $tr;
            }
        }
        return $elegido;
    }

    /** Tipo estatal del tramo que corresponde a ese CO2. */
    public static function tipoPorCo2(array $tramos, float $co2): float
    {
        return (float) self::tramoPorCo2($tramos, $co2)['tipo'];
    }
}
