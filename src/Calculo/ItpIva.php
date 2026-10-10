<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Calculo;

/**
 * Qué impuesto "de la compraventa" se paga en España:
 *   - medio de transporte NUEVO (<= 6 meses o <= 6.000 km): IVA (modelo 309) sobre el precio sin IVA alemán.
 *     En Canarias se sustituye por el IGIC.
 *   - usado comprado a un PARTICULAR: ITP de la comunidad sobre el valor venal del BOE.
 *   - usado comprado a un PROFESIONAL: el IVA alemán ya está pagado (régimen general o REBU): ni ITP ni IVA.
 */
final class ItpIva
{
    /** @return array{nuevo: bool, por_meses: bool, por_km: bool, fecha_limite_meses: string} */
    public static function clasificar(array $n, array $tablas): array
    {
        $nuevo = $tablas['iva']['nuevo'];
        $limite = Fechas::sumarMeses($n['fecha_matriculacion'], (int) $nuevo['meses_max']);
        $porMeses = $n['fecha_calculo'] < $limite;   // «antes de los seis meses siguientes»: el día del sexto mes ya no cuenta
        $porKm = $n['km'] <= (int) $nuevo['km_max'];   // «no hayan recorrido más de 6.000 km»
        return [
            'nuevo' => $porMeses || $porKm,
            'por_meses' => $porMeses,
            'por_km' => $porKm,
            'fecha_limite_meses' => $limite->format('Y-m-d'),
        ];
    }

    /** Precio sin IVA alemán si es un coche nuevo de un profesional con IVA desglosable; si no, el precio del anuncio. */
    public static function precioNeto(array $n, array $tablas, bool $nuevo): float
    {
        if ($nuevo && $n['vendedor'] === 'profesional' && $n['iva_deducible']) {
            $ivaDe = (float) $tablas['iva']['iva_alemania']['valor'];
            return Dinero::redondear($n['precio'] / (1 + $ivaDe / 100));
        }
        return $n['precio'];
    }

    /**
     * @param array{nuevo: bool, precio_neto: float, valor_venal: ?float, cvf: ?float, antiguedad_anios: int, tipo_indirecto: float} $ctx
     * @return array{partidas: array, avisos: array}
     */
    public static function calcular(array $n, array $tablas, array $ctx): array
    {
        if ($ctx['nuevo']) {
            return self::iva($n, $tablas, $ctx);
        }
        if ($n['vendedor'] === 'profesional') {
            return self::sinImpuesto($n, $tablas);
        }
        return self::itp($n, $tablas, $ctx);
    }

    private static function iva(array $n, array $tablas, array $ctx): array
    {
        $iva = $tablas['iva'];
        $canarias = $tablas['ccaa'][$n['ccaa']]['regimen'] === 'canarias';
        $tipo = $ctx['tipo_indirecto'];
        $base = $ctx['precio_neto'];
        $importe = Dinero::redondear($base * $tipo / 100);
        $nombre = $canarias ? 'IGIC (Canarias)' : 'IVA español (modelo 309)';
        $origen = $n['vendedor'] === 'profesional' && $n['iva_deducible']
            ? 'precio del anuncio sin el ' . Partida::num((float) $iva['iva_alemania']['valor']) . ' % de IVA alemán'
            : 'precio del anuncio';
        $explicacion = sprintf(
            'Medio de transporte nuevo (%s): el impuesto se paga en España, no en Alemania. Base: %s (%s). %s x %s %% = %s.',
            $ctx['motivo_nuevo'] ?? 'menos de 6 meses o 6.000 km', Partida::eur($base), $origen, Partida::eur($base), Partida::num($tipo), Partida::eur($importe)
        );
        $avisos = [];
        if ($n['vendedor'] === 'profesional' && !$n['iva_deducible']) {
            $avisos[] = Partida::aviso(
                'iva_nuevo_sin_desglose',
                'El anuncio no indica que el IVA alemán se pueda desglosar. Se ha tomado el precio del anuncio como base: '
                . 'si la factura sale sin IVA alemán, ajusta el precio.'
            );
        }
        if ($canarias) {
            $avisos[] = Partida::aviso(
                'canarias_regimen',
                'Canarias tiene un régimen de importación propio (IGIC, arbitrio AIEM y trámite aduanero). El importe es orientativo.'
            );
        }
        return [
            'partidas' => [Partida::ok('itp_iva', $nombre, $importe, $base, $tipo, '%',
                $iva['fuente'], $explicacion, Partida::pendiente($iva), $iva['url'] ?? null)],
            'avisos' => $avisos,
        ];
    }

    private static function sinImpuesto(array $n, array $tablas): array
    {
        $iva = $tablas['iva'];
        $explicacion = 'Coche usado comprado a un profesional: el IVA alemán ya está incluido en el precio (régimen general o régimen especial de bienes usados, REBU), '
            . 'así que en España no se paga ni ITP ni IVA.';
        $avisos = [Partida::aviso(
            'usado_profesional',
            'Comprado a un profesional, el coche normalmente ya lleva el IVA alemán incluido y no se paga ITP ni IVA en España. '
            . 'Pide factura con la mención "Differenzbesteuerung" (REBU) o con el IVA desglosado.',
            'info'
        )];
        if ($n['iva_deducible']) {
            $avisos[] = Partida::aviso(
                'iva_no_recuperable',
                'El anuncio indica "MwSt. ausweisbar" (IVA desglosable): solo lo recupera una empresa. Un comprador particular no puede deducirlo.',
                'info'
            );
        }
        return [
            'partidas' => [Partida::ok('itp_iva', 'ITP / IVA en España', 0.0, null, null, null,
                $iva['fuente'], $explicacion, Partida::pendiente($iva), $iva['url'] ?? null)],
            'avisos' => $avisos,
        ];
    }

    private static function itp(array $n, array $tablas, array $ctx): array
    {
        $itp = $tablas['itp'];
        $fila = $itp['ccaa'][$n['ccaa']] ?? null;
        $concepto = 'ITP (Impuesto de Transmisiones Patrimoniales)';
        $fuente = $fila['fuente'] ?? $itp['fuente'];
        $verificar = Partida::pendiente($itp, $fila);
        $url = $itp['url'] ?? null;

        if ($ctx['valor_venal'] === null) {
            return ['partidas' => [Partida::noCalculable('itp_iva', $concepto,
                'Falta el valor venal (fila del BOE): sin él no se puede calcular la base del ITP.', $fuente, $verificar, $url)], 'avisos' => []];
        }
        if ($fila === null) {
            return ['partidas' => [Partida::noCalculable('itp_iva', $concepto,
                'No hay tabla de ITP para esta comunidad autónoma.', $fuente, true, $url)], 'avisos' => []];
        }

        if (($fila['reglas_vehiculos'] ?? 'completas') === 'pendientes') {
            $general = $fila['tipo_general'] !== null
                ? 'El tipo general para bienes muebles es del ' . Partida::num((float) $fila['tipo_general']) . ' %, pero '
                : '';
            $especiales = implode(' y ', $fila['especiales'] ?? ['reglas especiales']);
            return ['partidas' => [Partida::noCalculable('itp_iva', $concepto,
                $general . 'esta comunidad aplica además a los vehículos ' . $especiales . ', que aún no hemos incorporado. '
                . 'No calculamos el ITP para no darte una cifra errónea: pregúntalo a tu gestoría o en la agencia tributaria de tu comunidad.',
                $fuente, true, $url)], 'avisos' => []];
        }

        $regla = null;
        foreach ($fila['reglas'] ?? [] as $r) {
            $cumple = self::reglaCumple($r['si'] ?? [], $ctx['cvf'], $ctx['antiguedad_anios'], $n['combustible'], $ctx['valor_venal']);
            if ($cumple === null) {
                return ['partidas' => [Partida::noCalculable('itp_iva', $concepto,
                    'El ITP de esta comunidad depende de los caballos fiscales y no se han podido calcular.', $fuente, $verificar, $url)], 'avisos' => []];
            }
            if ($cumple) {
                $regla = $r;
                break;
            }
        }

        $base = $ctx['valor_venal'];
        if ($regla !== null && isset($regla['cuota_fija'])) {
            $importe = Dinero::redondear((float) $regla['cuota_fija']);
            $explicacion = sprintf('Compra a un particular: ITP de la comunidad. Para este coche (antigüedad y potencia) se aplica una cuota fija de %s.', Partida::eur($importe));
            return ['partidas' => [Partida::ok('itp_iva', $concepto, $importe, $base, null, null, $fuente, $explicacion, $verificar, $url)], 'avisos' => []];
        }

        $tipo = $regla['tipo'] ?? $fila['tipo_general'] ?? null;
        if ($tipo === null) {
            $cand = array_map(
                static fn(array $c): string => Partida::num((float) $c['tipo']) . ' % (' . $c['origen'] . ')',
                $fila['candidatos'] ?? []
            );
            return ['partidas' => [Partida::noCalculable('itp_iva', $concepto,
                'El tipo de ITP de esta comunidad autónoma está pendiente de verificar porque las fuentes consultadas no coinciden'
                . ($cand ? ' (' . implode('; ', $cand) . ')' : '') . '.', $fuente, true, $url)], 'avisos' => []];
        }

        $tipo = (float) $tipo;
        $importe = Dinero::redondear($base * $tipo / 100);
        $explicacion = sprintf(
            'Compra a un particular: se paga ITP en tu comunidad (modelo 620) sobre el valor venal del BOE. %s x %s %% = %s.',
            Partida::eur($base), Partida::num($tipo), Partida::eur($importe)
        );
        return ['partidas' => [Partida::ok('itp_iva', $concepto, $importe, $base, $tipo, '%', $fuente, $explicacion, $verificar, $url)], 'avisos' => []];
    }

    /**
     * true / false, o null si la regla necesita un dato que no se conoce (los CVF o el combustible).
     * Condiciones: cvf_min (>=), cvf_mayor_que (>), cvf_max (<), cvf_menor_igual (<=),
     * antiguedad_min (>=), antiguedad_max (<), combustible_en (lista), valor_menor_que (< valor venal).
     */
    public static function reglaCumple(array $si, ?float $cvf, int $antiguedad, ?string $combustible = null, ?float $valor = null): ?bool
    {
        $usaCvf = isset($si['cvf_min']) || isset($si['cvf_max']) || isset($si['cvf_mayor_que']) || isset($si['cvf_menor_igual']);
        if ($usaCvf && $cvf === null) {
            return null;
        }
        if (isset($si['combustible_en']) && $combustible === null) {
            return null;
        }
        if (isset($si['valor_menor_que']) && ($valor === null || $valor >= $si['valor_menor_que'])) {
            return $valor === null ? null : false;
        }
        if (isset($si['combustible_en']) && !in_array($combustible, $si['combustible_en'], true)) {
            return false;
        }
        if (isset($si['cvf_min']) && $cvf < $si['cvf_min']) {
            return false;
        }
        if (isset($si['cvf_mayor_que']) && $cvf <= $si['cvf_mayor_que']) {
            return false;
        }
        if (isset($si['cvf_max']) && $cvf >= $si['cvf_max']) {
            return false;
        }
        if (isset($si['cvf_menor_igual']) && $cvf > $si['cvf_menor_igual']) {
            return false;
        }
        if (isset($si['antiguedad_min']) && $antiguedad < $si['antiguedad_min']) {
            return false;
        }
        if (isset($si['antiguedad_max']) && $antiguedad >= $si['antiguedad_max']) {
            return false;
        }
        return true;
    }
}
