<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Calculo;

/**
 * Impuesto sobre Vehículos de Tracción Mecánica (turismos).
 *
 * La cuota anual sale del cuadro de tarifas del art. 95.1 TRLRHL por caballos fiscales, ajustada por el
 * ayuntamiento (art. 95.4), que puede fijar un coeficiente distinto en cada tramo. Una ordenanza se
 * recoge en data/fiscal/<año>/ivtm_municipios.json de una de estas tres formas:
 *   coeficiente      un único coeficiente para todos los tramos
 *   coeficientes     lista [{cvf_desde, coeficiente}] con un coeficiente por tramo (Salamanca)
 *   cuotas           lista [{cvf_desde, cuota}] con las cuotas en euros que publica el ayuntamiento (Madrid)
 * Si el municipio no está verificado se usa la tarifa mínima legal (coeficiente 1) y se avisa.
 *
 * El año de la primera matriculación se paga prorrateado por trimestres naturales (art. 96.3), y el
 * trimestre de la matriculación cuenta entero; esa cuota es la que entra en el total.
 */
final class Ivtm
{
    /** @return array{partidas: array, avisos: array, cuota_anual: ?float} */
    public static function calcular(array $n, array $tablas, ?float $cvf, ?string $motivoSinCvf): array
    {
        $tar = $tablas['ivtm_tarifas'];
        $concepto = 'IVTM (impuesto de circulación)';
        $fila = $tablas['ivtm_por_ine'][$n['municipio']] ?? null;
        // El criterio del trimestre de alta lo fija cada ordenanza; la ley solo dice «por trimestres naturales»
        $prorrateoPendiente = Partida::pendiente($tar['prorrateo'] ?? null) && empty($fila['prorrateo_confirmado']);
        $verificar = Partida::pendiente($tar) || $prorrateoPendiente;
        $url = $tar['url'] ?? null;

        if ($cvf === null) {
            return [
                'partidas' => [Partida::noCalculable('ivtm', $concepto, $motivoSinCvf ?? 'No se han podido calcular los caballos fiscales.', $tar['fuente'], $verificar, $url)],
                'avisos' => [],
                'cuota_anual' => null,
            ];
        }

        $tarifa = (float) self::tramo($tar['turismos'], $cvf)['cuota'];
        $avisos = [];
        $nombreMun = $tablas['municipios'][$n['municipio']]['nombre'];
        $tipo = null;
        $tipoUnidad = null;

        if ($fila !== null && !empty($fila['cuotas'])) {
            $anual = Dinero::redondear((float) self::tramo($fila['cuotas'], $cvf)['cuota']);
            $detalle = sprintf('%s CVF: la ordenanza de %s fija una cuota de %s al año.', Partida::num($cvf), $nombreMun, Partida::eur($anual));
            $verificar = $verificar || Partida::pendiente($fila);
            $fuenteMun = 'Cuotas de ' . $nombreMun . ': ' . ($fila['fuente'] ?? 'ordenanza fiscal');
        } elseif ($fila !== null && !empty($fila['coeficientes'])) {
            $tipo = (float) self::tramo($fila['coeficientes'], $cvf)['coeficiente'];
            $tipoUnidad = 'coef';
            $anual = Dinero::redondear($tarifa * $tipo);
            $detalle = sprintf('%s CVF: tarifa de %s x coeficiente %s de %s = %s al año.', Partida::num($cvf), Partida::eur($tarifa), Partida::num($tipo, 4), $nombreMun, Partida::eur($anual));
            $verificar = $verificar || Partida::pendiente($fila);
            $fuenteMun = 'Coeficientes de ' . $nombreMun . ': ' . ($fila['fuente'] ?? 'ordenanza fiscal');
        } elseif ($fila !== null && ($fila['coeficiente'] ?? null) !== null) {
            $tipo = (float) $fila['coeficiente'];
            $tipoUnidad = 'coef';
            $anual = Dinero::redondear($tarifa * $tipo);
            $detalle = sprintf('%s CVF: tarifa de %s x coeficiente %s = %s al año.', Partida::num($cvf), Partida::eur($tarifa), Partida::num($tipo, 4), Partida::eur($anual));
            $verificar = $verificar || Partida::pendiente($fila);
            $fuenteMun = 'Coeficiente de ' . $nombreMun . ': ' . ($fila['fuente'] ?? 'ordenanza fiscal');
        } else {
            $tipo = (float) $tar['coeficiente_minimo'];
            $tipoUnidad = 'coef';
            $anual = Dinero::redondear($tarifa * $tipo);
            $detalle = sprintf('%s CVF: tarifa mínima legal de %s (coeficiente %s) = %s al año.', Partida::num($cvf), Partida::eur($tarifa), Partida::num($tipo), Partida::eur($anual));
            $verificar = true;
            $fuenteMun = 'Tarifa mínima legal (coeficiente ' . Partida::num($tipo) . ')';
            $avisos[] = Partida::aviso(
                'ivtm_tarifa_minima',
                'No tenemos verificada la ordenanza del IVTM de ' . $nombreMun . ': se ha usado la tarifa mínima legal. '
                . 'Tu ayuntamiento puede aplicar hasta el doble; consulta su ordenanza fiscal.'
            );
        }

        $trimestres = Fechas::trimestresRestantes($n['fecha_calculo']);
        $primerAnio = Dinero::redondear($anual * $trimestres / 4);

        // Bonificación ambiental: se resta solo si la ordenanza la concede de oficio y el distintivo es seguro
        $distintivo = $tablas['ivtm_tarifas']['distintivo_por_combustible'][$n['combustible']] ?? null;
        $bon = $fila['bonificacion_ambiental'] ?? null;
        $importe = $primerAnio;
        $textoBon = '';
        if (is_array($bon) && !empty($bon['de_oficio']) && in_array($distintivo, ['cero', 'eco'], true)) {
            $descuento = Dinero::redondear($primerAnio * (float) $bon['porcentaje'] / 100);
            $importe = Dinero::redondear($primerAnio - $descuento);
            $textoBon = sprintf(' Bonificación del %s %% por distintivo %s, que %s concede de oficio y descuenta al matricular: -%s, quedan %s.',
                Partida::num((float) $bon['porcentaje']), $distintivo === 'cero' ? 'CERO' : 'ECO', $nombreMun, Partida::eur($descuento), Partida::eur($importe));
            $avisos[] = Partida::aviso(
                'ivtm_bonificacion_aplicada',
                sprintf('Se ha descontado la bonificación del %s %% del IVTM de %s (%s). El distintivo ambiental debe constar en el Registro de Vehículos de la DGT.',
                    Partida::num((float) $bon['porcentaje']), $nombreMun, $distintivo === 'cero' ? $bon['cero'] : $bon['eco']),
                'info'
            );
            $verificar = $verificar || Partida::pendiente($bon);
        } else {
            $aviso = self::avisoBonificacion($n, $tablas, $fila, $nombreMun, $primerAnio);
            if ($aviso !== null) {
                $avisos[] = $aviso;
            }
        }

        $explicacion = sprintf(
            '%s Primer año prorrateado: %d de 4 trimestres (el trimestre de la matriculación cuenta entero) = %s.%s',
            $detalle, $trimestres, Partida::eur($primerAnio), $textoBon
        );
        return [
            'partidas' => [Partida::ok('ivtm', $concepto, $importe, $anual, $tipo, $tipoUnidad, $tar['fuente'] . ' | ' . $fuenteMun, $explicacion, $verificar, $fila['url_ordenanza'] ?? $url)],
            'avisos' => $avisos,
            'cuota_anual' => $anual,
        ];
    }

    /** Último tramo cuyo límite inferior de CVF no supera el del coche (las listas llevan cvf_desde). */
    private static function tramo(array $lista, float $cvf): array
    {
        usort($lista, static fn(array $a, array $b): int => $a['cvf_desde'] <=> $b['cvf_desde']);
        $elegido = $lista[0];
        foreach ($lista as $t) {
            if ($cvf >= $t['cvf_desde']) {
                $elegido = $t;
            }
        }
        return $elegido;
    }

    /**
     * Los ayuntamientos pueden bonificar hasta el 75 % a eléctricos e híbridos (art. 95.6 TRLRHL). Si la
     * ordenanza del municipio lo recoge se da el detalle; si no, un aviso genérico. Aquí solo se avisa; el
     * descuento automático lo hace calcular() cuando la ordenanza la concede de oficio.
     */
    private static function avisoBonificacion(array $n, array $tablas, ?array $fila, string $nombreMun, float $primerAnio): ?array
    {
        $distintivo = $tablas['ivtm_tarifas']['distintivo_por_combustible'][$n['combustible']] ?? null;
        if ($distintivo === null) {
            return null;
        }
        $bon = $fila['bonificacion_ambiental'] ?? null;
        if (!is_array($bon)) {
            return Partida::aviso(
                'ivtm_bonificacion',
                'Muchos ayuntamientos bonifican el IVTM de los coches eléctricos, híbridos y de gas (la ley permite hasta un 75 %). '
                . 'Consulta la ordenanza de tu municipio: la cuota real puede ser menor.',
                'info'
            );
        }
        $pct = (float) $bon['porcentaje'];
        $nombres = ['cero' => 'CERO', 'eco' => 'ECO', 'cero_o_eco' => 'CERO (con 40 km o más de autonomía eléctrica) o ECO'];
        $duracion = $distintivo === 'eco' ? $bon['eco'] : ($distintivo === 'cero' ? $bon['cero'] : $bon['cero'] . ' con distintivo CERO y ' . $bon['eco'] . ' con ECO');
        return Partida::aviso(
            'ivtm_bonificacion',
            sprintf(
                '%s bonifica un %s %% el IVTM de los vehículos con distintivo %s (%s). %sNo se ha restado de la cifra porque hay que solicitarla o depende del distintivo de tu coche. '
                . 'Con ella el primer año pagarías unos %s menos.',
                $nombreMun, Partida::num($pct), $nombres[$distintivo], $duracion,
                !empty($bon['nota']) ? $bon['nota'] . ' ' : '',
                Partida::eur(Dinero::redondear($primerAnio * $pct / 100))
            ),
            'info'
        );
    }
}
