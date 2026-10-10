<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Calculo;

/**
 * Impuesto sobre Vehículos de Tracción Mecánica (turismos).
 * Cuota anual = tarifa por caballos fiscales (art. 95.1 TRLRHL) x coeficiente municipal.
 * El año de la primera matriculación en España se paga prorrateado por trimestres naturales
 * (art. 96.3), contando el trimestre de la matriculación; esa es la cifra que entra en el total.
 */
final class Ivtm
{
    /** @return array{partidas: array, avisos: array, cuota_anual: ?float} */
    public static function calcular(array $n, array $tablas, ?float $cvf, ?string $motivoSinCvf): array
    {
        $tar = $tablas['ivtm_tarifas'];
        $concepto = 'IVTM (impuesto de circulación)';
        $verificar = Partida::pendiente($tar);
        $url = $tar['url'] ?? null;

        if ($cvf === null) {
            return [
                'partidas' => [Partida::noCalculable('ivtm', $concepto, $motivoSinCvf ?? 'No se han podido calcular los caballos fiscales.', $tar['fuente'], $verificar, $url)],
                'avisos' => [],
                'cuota_anual' => null,
            ];
        }

        $tramos = $tar['turismos'];
        usort($tramos, static fn(array $a, array $b): int => $a['cvf_desde'] <=> $b['cvf_desde']);
        $tarifa = (float) $tramos[0]['cuota'];
        foreach ($tramos as $t) {
            if ($cvf >= $t['cvf_desde']) {
                $tarifa = (float) $t['cuota'];
            }
        }

        $avisos = [];
        $fila = $tablas['ivtm_por_ine'][$n['municipio']] ?? null;
        $coefMin = (float) $tar['coeficiente_minimo'];
        $nombreMun = $tablas['municipios'][$n['municipio']]['nombre'];
        if ($fila !== null && $fila['coeficiente'] !== null) {
            $coef = (float) $fila['coeficiente'];
            $verificar = $verificar || Partida::pendiente($fila);
            $fuenteCoef = 'Coeficiente de ' . $nombreMun . ': ' . ($fila['fuente'] ?? 'ordenanza fiscal');
        } else {
            $coef = $coefMin;
            $verificar = true;
            $fuenteCoef = 'Tarifa mínima legal (coeficiente ' . Partida::num($coefMin) . ')';
            $avisos[] = Partida::aviso(
                'ivtm_tarifa_minima',
                'No tenemos verificado el coeficiente del IVTM de ' . $nombreMun . ': se ha usado la tarifa mínima legal. '
                . 'Tu ayuntamiento puede aplicar hasta el doble; consulta su ordenanza fiscal.'
            );
        }
        if (in_array($tablas['ccaa'][$n['ccaa']]['regimen'], ['foral'], true)) {
            $avisos[] = Partida::aviso(
                'ivtm_foral',
                'En País Vasco y Navarra el IVTM tiene tarifas forales propias: el cuadro estatal es solo orientativo.'
            );
        }

        $anual = Dinero::redondear($tarifa * $coef);
        $trimestres = Fechas::trimestresRestantes($n['fecha_calculo']);
        $primerAnio = Dinero::redondear($anual * $trimestres / 4);

        $explicacion = sprintf(
            '%s CVF: tarifa de %s. %s x coeficiente %s = %s al año. Primer año prorrateado: %d de 4 trimestres (el de la matriculación cuenta entero) = %s.',
            Partida::num($cvf), Partida::eur($tarifa), Partida::eur($tarifa), Partida::num($coef, 3), Partida::eur($anual), $trimestres, Partida::eur($primerAnio)
        );
        return [
            'partidas' => [Partida::ok('ivtm', $concepto, $primerAnio, $anual, $coef, 'coef', $tar['fuente'] . ' | ' . $fuenteCoef, $explicacion, $verificar, $fila['url_ordenanza'] ?? $url)],
            'avisos' => $avisos,
            'cuota_anual' => $anual,
        ];
    }
}
