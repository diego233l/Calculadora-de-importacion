<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Calculo;

/**
 * Caballos fiscales (CVF). Orden de preferencia:
 *   1. el CVF que trae la fila del BOE;
 *   2. eléctricos: kW / 5,152;
 *   3. resto (motor de cuatro tiempos; en híbridos, el térmico): 0,08 x (cc / cilindros)^0,6 x cilindros.
 */
final class CaballosFiscales
{
    /** @return array{cvf: ?float, origen: ?string, explicacion: string, motivo: ?string, verificar: bool} */
    public static function calcular(array $n, array $tablas): array
    {
        $f = $tablas['ivtm_tarifas']['formulas_cvf'];
        $verificar = Partida::pendiente($f);

        if ($n['fila_boe'] !== null && $n['fila_boe']['cvf'] !== null) {
            $cvf = $n['fila_boe']['cvf'];
            return ['cvf' => $cvf, 'origen' => 'boe', 'verificar' => false, 'motivo' => null,
                'explicacion' => 'CVF tomado de la fila del BOE: ' . Partida::num($cvf) . '.'];
        }

        if ($n['combustible'] === 'electrico') {
            if ($n['kw'] === null) {
                return self::falta('Faltan los kW del motor eléctrico para calcular los caballos fiscales.', $verificar);
            }
            $div = (float) $f['electrico_divisor_kw'];
            $cvf = round($n['kw'] / $div, 2);
            return ['cvf' => $cvf, 'origen' => 'kw', 'verificar' => $verificar, 'motivo' => null,
                'explicacion' => sprintf('Eléctrico: %s kW / %s = %s CVF.', Partida::num($n['kw']), Partida::num($div, 3), Partida::num($cvf))];
        }

        $cilindros = $n['cilindros'] ?? ($n['fila_boe']['cilindros'] ?? null);
        if ($n['cc'] === null || $cilindros === null) {
            return self::falta('Faltan la cilindrada o el número de cilindros para calcular los caballos fiscales (la fila del BOE no los trae).', $verificar);
        }
        $k = (float) $f['combustion']['coeficiente'];
        $exp = (float) $f['combustion']['exponente'];
        $cvf = round($k * (($n['cc'] / $cilindros) ** $exp) * $cilindros, 2);
        return ['cvf' => $cvf, 'origen' => 'formula', 'verificar' => $verificar, 'motivo' => null,
            'explicacion' => sprintf(
                '%s x (%d cm³ / %d cilindros)^%s x %d cilindros = %s CVF.',
                Partida::num($k), $n['cc'], $cilindros, Partida::num($exp), $cilindros, Partida::num($cvf)
            )];
    }

    private static function falta(string $motivo, bool $verificar): array
    {
        return ['cvf' => null, 'origen' => null, 'verificar' => $verificar, 'motivo' => $motivo, 'explicacion' => $motivo];
    }
}
