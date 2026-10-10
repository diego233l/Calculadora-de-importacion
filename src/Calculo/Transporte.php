<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Calculo;

/**
 * Coste estimado de llevar el coche del código postal del vendedor al municipio de destino.
 * Distancia = haversine x factor de carretera; duración = distancia / velocidad media.
 * Modo "conduciendo": consumo x precio por unidad (litro, kg o kWh) + otros gastos del viaje.
 * Modo "camion": precio por km de un camión portavehículos.
 */
final class Transporte
{
    /** @return array{partidas: array, avisos: array, distancia: ?array} */
    public static function calcular(array $n, array $tablas): array
    {
        $t = $tablas['transporte'];
        $p = $n['transporte'];
        $modo = $p['modo'] ?? 'conduciendo';
        $verificar = Partida::pendiente($t);
        $concepto = $modo === 'camion' ? 'Transporte en camión portavehículos' : 'Viaje para recoger el coche';
        $fuente = $t['fuente'];
        $avisos = [];

        $origen = Geo::coordenadasPlz($n['cp_vendedor'], $tablas['plz']);
        if ($origen === null) {
            return [
                'partidas' => [Partida::noCalculable('transporte', $concepto,
                    'No se puede estimar el transporte sin un código postal alemán válido de 5 cifras del vendedor.', $fuente, $verificar)],
                'avisos' => [],
                'distancia' => null,
            ];
        }
        $destino = $tablas['municipios'][$n['municipio']];
        if (!empty($destino['aprox'])) {
            $avisos[] = Partida::aviso(
                'destino_aproximado',
                'La ubicación de ' . $destino['nombre'] . ' es aproximada (no tiene código postal propio en la base de datos), así que la distancia puede variar unos kilómetros.',
                'info'
            );
        }

        $factor = $p['factor_carretera'] ?? (float) $t['factor_carretera'];
        $velocidad = $p['velocidad_kmh'] ?? (float) $t['velocidad_media_kmh'];
        $recta = Geo::haversine($origen[0], $origen[1], $destino['lat'], $destino['lon'], (float) $t['radio_tierra_km']);
        $carretera = $recta * $factor;
        $horas = $carretera / $velocidad;
        $distancia = [
            'recta_km' => round($recta, 1),
            'carretera_km' => round($carretera, 1),
            'horas' => round($horas, 1),
            'prefijo_origen' => $origen[2],
        ];
        $resumen = sprintf(
            '%s km en línea recta x %s de factor de carretera = %s km; a %s km/h son unas %s h.',
            Partida::num($recta, 0), Partida::num($factor), Partida::num($carretera, 0), Partida::num($velocidad, 0), Partida::num($horas, 1)
        );
        $avisos[] = Partida::aviso('transporte_estimacion', 'El transporte es una estimación a partir de la distancia en línea recta; no usa rutas reales.', 'info');

        if ($modo === 'camion') {
            $precioKm = $p['precio_por_km'] ?? (float) $t['camion']['precio_por_km'];
            $importe = Dinero::redondear($carretera * $precioKm);
            $explicacion = sprintf('%s %s km x %s €/km = %s.', $resumen, Partida::num($carretera, 0), Partida::num($precioKm), Partida::eur($importe));
            return [
                'partidas' => [Partida::ok('transporte', $concepto, $importe, round($carretera, 2), $precioKm, '€/km', $fuente, $explicacion, $verificar, null, true)],
                'avisos' => $avisos,
                'distancia' => $distancia,
            ];
        }

        $c = $t['conduciendo'];
        $comb = $n['combustible'];
        $consumo = $p['consumo_por_100km'] ?? (float) $c['consumo_por_100km'][$comb];
        $precioUd = $p['precio_por_unidad'] ?? (float) $c['precio_por_unidad'][$comb];
        $unidad = $c['unidad_consumo'][$comb];
        $energia = Dinero::redondear($carretera * $consumo / 100 * $precioUd);
        $explicacion = sprintf(
            '%s Consumo %s %s/100 km x %s km = %s %s x %s €/%s = %s. No incluye peajes, billete de vuelta ni alojamiento (campo "otros gastos del viaje").',
            $resumen, Partida::num($consumo), $unidad, Partida::num($carretera, 0),
            Partida::num($carretera * $consumo / 100, 1), $unidad, Partida::num($precioUd), $unidad, Partida::eur($energia)
        );
        $partidas = [Partida::ok('transporte', $concepto, $energia, round($carretera, 2), $precioUd, '€/' . $unidad, $fuente, $explicacion, $verificar, null, true)];

        $otros = Dinero::redondear(($p['peajes'] ?? 0.0) + ($p['billete_vuelta'] ?? 0.0) + ($p['alojamiento'] ?? 0.0));
        if ($otros > 0) {
            $partidas[] = Partida::ok('otros_gastos_viaje', 'Otros gastos del viaje (peajes, billete de vuelta, alojamiento)', $otros, null, null, null,
                'Importes indicados por ti', 'Suma de peajes, billete de vuelta y alojamiento que has introducido.', false, null, false);
        }
        return ['partidas' => $partidas, 'avisos' => $avisos, 'distancia' => $distancia];
    }
}
