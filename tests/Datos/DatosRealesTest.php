<?php

declare(strict_types=1);

use CalculadoraImportacion\Calculo\Calculadora;
use CalculadoraImportacion\Datos\Tablas;

/*
 * Integridad de los datos REALES de data/ (no las tablas de prueba): estructura, fuente y marca VERIFICAR.
 * No fijan importes concretos: comprueban que cada valor se puede rastrear hasta una norma.
 */

function dataDir(): string
{
    return dirname(__DIR__, 2) . '/data';
}

function jsonReal(string $ruta): array
{
    return json_decode(file_get_contents($ruta), true, 512, JSON_THROW_ON_ERROR);
}

prueba('D1. Todo fichero fiscal declara anio, fuente y verificar (booleano), y su año coincide con su carpeta', function (): void {
    $ficheros = glob(dataDir() . '/fiscal/*/*.json');
    Afirmar::verdadero(count($ficheros) >= 9, 'Faltan ficheros fiscales');
    foreach ($ficheros as $f) {
        $d = jsonReal($f);
        $nombre = basename(dirname($f)) . '/' . basename($f);
        Afirmar::verdadero(isset($d['fuente']) && is_string($d['fuente']) && trim($d['fuente']) !== '', "$nombre: falta «fuente»");
        Afirmar::verdadero(isset($d['verificar']) && is_bool($d['verificar']), "$nombre: «verificar» debe ser true o false");
        Afirmar::igual((int) basename(dirname($f)), $d['anio'] ?? null, "$nombre: el año no coincide con la carpeta");
        if ($d['verificar'] === true) {
            Afirmar::verdadero(!empty($d['verificar_motivo']), "$nombre: está marcado «verificar» pero no explica el motivo");
        }
    }
});

prueba('D2. Cualquier nodo con «verificar» lo declara como booleano y con fuente propia o heredada', function (): void {
    $recorrer = function (array $nodo, string $ruta, string $fuenteHeredada) use (&$recorrer): void {
        $fuente = isset($nodo['fuente']) && is_string($nodo['fuente']) ? $nodo['fuente'] : $fuenteHeredada;
        if (array_key_exists('verificar', $nodo)) {
            Afirmar::verdadero(is_bool($nodo['verificar']), "$ruta: «verificar» debe ser booleano");
            Afirmar::verdadero(trim($fuente) !== '', "$ruta: nodo sin fuente propia ni heredada");
        }
        foreach ($nodo as $clave => $hijo) {
            if (is_array($hijo)) {
                $recorrer($hijo, $ruta . '/' . $clave, $fuente);
            }
        }
    };
    foreach (glob(dataDir() . '/fiscal/*/*.json') as $f) {
        $recorrer(jsonReal($f), basename($f), '');
    }
});

prueba('D3. Un tipo de ITP a null debe documentar sus candidatos; nunca se deja un hueco mudo', function (): void {
    foreach (glob(dataDir() . '/fiscal/*/itp.json') as $f) {
        $d = jsonReal($f);
        foreach ($d['ccaa'] as $codigo => $fila) {
            if ($fila['tipo_general'] === null) {
                Afirmar::verdadero(!empty($fila['candidatos']), "$codigo: tipo_general null sin «candidatos»");
                Afirmar::verdadero($fila['verificar'], "$codigo: un tipo null debe estar marcado «verificar»");
            } else {
                Afirmar::verdadero(is_float($fila['tipo_general']) && $fila['tipo_general'] >= 0 && $fila['tipo_general'] <= 15, "$codigo: tipo fuera de rango");
            }
        }
    }
});

prueba('D4. Toda comunidad soportada tiene fila de ITP y régimen de IEDMT existente', function (): void {
    foreach (glob(dataDir() . '/fiscal/*', GLOB_ONLYDIR) as $dir) {
        $ccaa = jsonReal($dir . '/ccaa.json')['ccaa'];
        $itp = jsonReal($dir . '/itp.json')['ccaa'];
        $iedmt = jsonReal($dir . '/iedmt.json')['regimenes'];
        foreach ($ccaa as $codigo => $c) {
            if (!$c['soportada']) {
                continue;
            }
            Afirmar::verdadero(isset($itp[$codigo]), "$codigo sin fila de ITP");
            Afirmar::verdadero(isset($iedmt[$c['regimen_iedmt']]), "$codigo con régimen IEDMT inexistente");
        }
    }
});

prueba('D5. Los tramos fiscales están ordenados y cubren desde 0', function (): void {
    $d = jsonReal(dataDir() . '/fiscal/2026/depreciacion.json');
    Afirmar::igual(0, $d['tramos'][0]['desde_anios']);
    $anterior = -1;
    $coefAnterior = 2.0;
    foreach ($d['tramos'] as $t) {
        Afirmar::verdadero($t['desde_anios'] > $anterior, 'años desordenados');
        Afirmar::verdadero($t['coeficiente'] <= $coefAnterior && $t['coeficiente'] > 0 && $t['coeficiente'] <= 1.0, 'la depreciación debe ser decreciente');
        $anterior = $t['desde_anios'];
        $coefAnterior = $t['coeficiente'];
    }
    $i = jsonReal(dataDir() . '/fiscal/2026/iedmt.json');
    foreach ($i['regimenes'] as $nombre => $r) {
        Afirmar::igual(0, $r['tramos'][0]['desde'], "$nombre: el primer tramo debe empezar en 0 g/km");
        Afirmar::igual(0.0, (float) $r['tramos'][0]['tipo'], "$nombre: hasta 120 g/km el tipo debe ser 0");
    }
    $v = jsonReal(dataDir() . '/fiscal/2026/ivtm_tarifas.json');
    Afirmar::igual(0, $v['turismos'][0]['cvf_desde']);
});

prueba('D6. Los municipios del IVTM existen en municipios_es.json', function (): void {
    $t = Tablas::cargar(dataDir(), 2026);
    Afirmar::igual(50, count($t['ivtm_por_ine']));
    foreach ($t['ivtm_por_ine'] as $ine => $m) {
        Afirmar::verdadero(isset($t['municipios'][$ine]), "El municipio $ine ({$m['nombre']}) no está en municipios_es.json");
        if ($m['coeficiente'] !== null) {
            Afirmar::verdadero($m['coeficiente'] >= 1.0 && $m['coeficiente'] <= 2.0, "$ine: coeficiente fuera de lo legal");
            Afirmar::verdadero(!empty($m['url_ordenanza']), "$ine: coeficiente sin URL de la ordenanza");
        }
    }
});

prueba('D7. Datos geográficos: cobertura y coordenadas verosímiles', function (): void {
    $t = Tablas::cargar(dataDir(), 2026);
    Afirmar::verdadero(count($t['municipios']) >= 8100, 'Faltan municipios');
    Afirmar::verdadero(count($t['plz']) >= 700, 'Faltan prefijos alemanes');
    foreach ($t['municipios'] as $clave => $m) {
        $ine = (string) $clave;   // PHP convierte en enteros las claves como "28079"
        Afirmar::verdadero(isset($t['provincias'][substr($ine, 0, 2)]), "$ine: provincia desconocida");
        Afirmar::verdadero(isset($t['ccaa'][$t['provincias'][substr($ine, 0, 2)]['ccaa']]), "$ine: CCAA desconocida");
        Afirmar::verdadero($m['lat'] > 27 && $m['lat'] < 44.5 && $m['lon'] > -19 && $m['lon'] < 5, "$ine: coordenadas fuera de España");
    }
    foreach ($t['plz'] as $pre => [$lat, $lon]) {
        Afirmar::verdadero($lat > 47 && $lat < 55.2 && $lon > 5.5 && $lon < 15.5, "PLZ $pre: coordenadas fuera de Alemania");
    }
    // Referencias conocidas (centro de la ciudad) con una holgura de 5 km
    $referencias = [
        '28079' => [40.4168, -3.7038, 'Madrid'], '08019' => [41.3874, 2.1686, 'Barcelona'],
        '41091' => [37.3891, -5.9845, 'Sevilla'], '46250' => [39.4699, -0.3763, 'València'],
        '48020' => [43.2630, -2.9350, 'Bilbao'], '35016' => [28.1235, -15.4363, 'Las Palmas de Gran Canaria'],
    ];
    foreach ($referencias as $ine => [$lat, $lon, $nombre]) {
        $m = $t['municipios'][$ine];
        Afirmar::igual($nombre, $m['nombre']);
        $km = CalculadoraImportacion\Calculo\Geo::haversine($lat, $lon, $m['lat'], $m['lon']);
        Afirmar::verdadero($km < 5.0, "$nombre: la coordenada está a " . round($km, 1) . ' km del centro');
    }
    // Múnich (803xx) y Berlín (101xx)
    [$latM, $lonM] = $t['plz']['803'];
    Afirmar::verdadero(CalculadoraImportacion\Calculo\Geo::haversine(48.1351, 11.5820, $latM, $lonM) < 8.0, 'prefijo 803 lejos de Múnich');
    [$latB, $lonB] = $t['plz']['101'];
    Afirmar::verdadero(CalculadoraImportacion\Calculo\Geo::haversine(52.5200, 13.4050, $latB, $lonB) < 8.0, 'prefijo 101 lejos de Berlín');
});

prueba('D8. Con los datos reales el motor calcula un caso completo sin lanzar excepciones', function (): void {
    $t = Tablas::cargar(dataDir(), 2026);
    $r = Calculadora::calcular([
        'fecha_matriculacion' => '2021-03-01', 'fecha_calculo' => '2026-10-10', 'km' => 80000,
        'cc' => 1998, 'cilindros' => 4, 'combustible' => 'diesel', 'co2_wltp' => 170, 'precio' => 18000,
        'vendedor' => 'particular', 'cp_vendedor' => '80331', 'municipio' => '28079', 'fila_boe' => ['precio' => 38000],
    ], $t);
    // Madrid tiene tipo de ITP, así que no falta ninguna partida
    Afirmar::verdadero($r['completo']);
    Afirmar::verdadero($r['verificar_pendiente'], 'mientras haya datos sin verificar debe constar en el resultado');
    // Valor venal: 38.000 x coeficiente de 5 años completos
    Afirmar::euros(38000 * 0.39, partida($r, 'valor_venal')['importe']);
    // Tipo de IEDMT de 170 g/km en la península según la ley (tramo >= 160 y < 200)
    Afirmar::igual(9.75, partida($r, 'iedmt')['tipo']);
    // Múnich - Madrid son unos 1.500 km en línea recta, es decir, entre 1.700 y 2.100 km con el factor de carretera
    $km = $r['derivados']['distancia']['carretera_km'];
    Afirmar::verdadero($km > 1700 && $km < 2100, "Distancia Múnich-Madrid inverosímil: $km km");
});

prueba('D9. Año fiscal: se usa la carpeta del año pedido o la más reciente anterior', function (): void {
    Afirmar::igual(2026, Tablas::anioDisponible(dataDir(), 2026));
    Afirmar::igual(2026, Tablas::anioDisponible(dataDir(), 2030));
    Afirmar::igual(2026, Tablas::anioDisponible(dataDir(), 2020), 'sin año anterior se usa el más antiguo disponible');
});
