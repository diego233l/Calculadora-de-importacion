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

prueba('D3. ITP: cada comunidad declara si sus reglas de vehículos están completas; un tipo null o pendiente nunca queda mudo', function (): void {
    foreach (glob(dataDir() . '/fiscal/*/itp.json') as $f) {
        $d = jsonReal($f);
        foreach ($d['ccaa'] as $codigo => $fila) {
            Afirmar::verdadero(in_array($fila['reglas_vehiculos'], ['completas', 'pendientes'], true), "$codigo: reglas_vehiculos inválido");
            if ($fila['reglas_vehiculos'] === 'pendientes') {
                Afirmar::verdadero(!empty($fila['especiales']), "$codigo: reglas pendientes sin explicar cuáles");
                Afirmar::verdadero($fila['verificar'], "$codigo: las reglas pendientes deben estar marcadas «verificar»");
            }
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

prueba('D5. Anexo IV de la Orden HAC/1501/2025: los 13 porcentajes oficiales y tramos bien ordenados', function (): void {
    $d = jsonReal(dataDir() . '/fiscal/2026/depreciacion.json');
    // Texto del anexo IV (BOE de 23/12/2025, turismos): hasta 1 año 100, hasta 2 84, ... más de 12 años 10
    $oficial = [100, 84, 67, 56, 47, 39, 34, 28, 24, 19, 17, 13, 10];
    Afirmar::igual(count($oficial), count($d['tramos']));
    $anterior = 0;
    foreach ($d['tramos'] as $i => $t) {
        Afirmar::igual($oficial[$i], (int) round($t['coeficiente'] * 100), 'tramo ' . ($i + 1));
        if ($t['hasta_anios'] !== null) {
            Afirmar::igual($anterior + 1, $t['hasta_anios'], 'los tramos avanzan de año en año');
            $anterior = $t['hasta_anios'];
        } else {
            Afirmar::igual($i, count($d['tramos']) - 1, 'solo el último tramo (más de 12 años) no tiene límite');
        }
    }
    $i = jsonReal(dataDir() . '/fiscal/2026/iedmt.json');
    foreach ($i['regimenes'] as $nombre => $r) {
        Afirmar::igual(0, $r['tramos'][0]['desde'], "$nombre: el primer tramo debe empezar en 0 g/km");
        Afirmar::igual(0.0, (float) $r['tramos'][0]['tipo'], "$nombre: hasta 120 g/km el tipo debe ser 0");
    }
    $v = jsonReal(dataDir() . '/fiscal/2026/ivtm_tarifas.json');
    Afirmar::igual(0, $v['turismos'][0]['cvf_desde']);
    // Art. 95.1 TRLRHL: 12,62 / 34,08 / 71,94 / 89,61 / 112,00 a partir de 0, 8, 12, 16 y 20 CVF
    Afirmar::igual([[0, 12.62], [8, 34.08], [12, 71.94], [16, 89.61], [20, 112.0]],
        array_map(fn($t) => [$t['cvf_desde'], $t['cuota']], $v['turismos']));
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
    // En Madrid todas las normas que intervienen están verificadas; solo quedan las estimaciones editables
    Afirmar::falso($r['verificar_pendiente'], 'Madrid, particular, gasolina/diésel: no debería quedar nada por verificar');
    Afirmar::verdadero($r['contiene_estimaciones']);
    // En un municipio sin ordenanza verificada (Sevilla: tarifa mínima del IVTM) sí debe constar
    $sevilla = Calculadora::calcular([
        'fecha_matriculacion' => '2021-03-01', 'fecha_calculo' => '2026-10-10', 'km' => 80000,
        'cc' => 1998, 'cilindros' => 4, 'combustible' => 'diesel', 'co2_wltp' => 170, 'precio' => 18000,
        'vendedor' => 'particular', 'cp_vendedor' => '80331', 'municipio' => '41091', 'fila_boe' => ['precio' => 38000],
    ], $t);
    Afirmar::verdadero($sevilla['verificar_pendiente']);
    Afirmar::verdadero(partida($sevilla, 'ivtm')['verificar']);
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

prueba('D10. Datos oficiales verificados: tipos autonómicos del IEDMT (AEAT) y tasa de la DGT', function (): void {
    $t = Tablas::cargar(dataDir(), 2026);
    $efectivos = $t['iedmt']['incrementos_autonomicos']['efectivos'];
    // AEAT, «Tipos impositivos»: Asturias, Baleares, Cataluña y Valencia 16 %, Murcia 15,9 %, Cantabria 15 % (epígrafe 4.º)
    Afirmar::igual(['AS' => 16.0, 'IB' => 16.0, 'CB' => 15.0, 'CT' => 16.0, 'MC' => 15.9, 'VC' => 16.0],
        array_map(fn($c) => $c['200'], $efectivos));
    Afirmar::falso(isset($efectivos['AN']), 'Andalucía mantiene el tipo estatal');
    $base = [
        'fecha_matriculacion' => '2021-03-01', 'fecha_calculo' => '2026-10-10', 'km' => 80000, 'cc' => 3000, 'cilindros' => 6,
        'combustible' => 'gasolina', 'co2_wltp' => 230, 'precio' => 30000, 'vendedor' => 'particular', 'cp_vendedor' => '80331',
        'fila_boe' => ['precio' => 60000],
    ];
    $tipo = fn(string $municipio) => partida(Calculadora::calcular($base + ['municipio' => $municipio], $t), 'iedmt')['tipo'];
    Afirmar::igual(16.0, $tipo('08019'), 'Barcelona');
    Afirmar::igual(14.75, $tipo('28079'), 'Madrid');
    Afirmar::igual(14.75, $tipo('41091'), 'Sevilla');
    $tasa = array_values(array_filter($t['costes_fijos']['partidas'], fn($p) => $p['clave'] === 'tasa_dgt'))[0];
    Afirmar::igual(99.77, $tasa['importe']);
    Afirmar::falso($tasa['estimacion']);
});

/** Entrada común de los tests de datos reales: gasolina de 5 años, valor venal 14.820 € (38.000 x 39 %). */
function entradaReal(array $cambios = []): array
{
    return array_merge([
        'fecha_matriculacion' => '2021-03-01', 'fecha_calculo' => '2026-10-10', 'km' => 80000,
        'cc' => 1998, 'cilindros' => 4, 'combustible' => 'gasolina', 'co2_wltp' => 130, 'precio' => 18000,
        'vendedor' => 'particular', 'cp_vendedor' => '80331', 'municipio' => '28079', 'fila_boe' => ['precio' => 38000],
    ], $cambios);
}

prueba('D11. IVTM real de Madrid (cuotas del Ayuntamiento 2026) y Salamanca (coeficientes por tramo)', function (): void {
    $t = Tablas::cargar(dataDir(), 2026);
    $ivtm = fn(array $cambios) => partida(Calculadora::calcular(entradaReal($cambios), $t), 'ivtm');

    // Madrid: 12-15,99 CVF = 129,00 € al año; octubre = 1 trimestre = 32,25 € (cifras del portal del Ayuntamiento)
    $m = $ivtm([]);
    Afirmar::euros(32.25, $m['importe']);
    Afirmar::euros(129.0, $m['base']);
    Afirmar::falso($m['verificar'], 'Madrid está verificado');
    // Madrid, otros tramos: menos de 8 CVF = 20 €; 1.000 cm³ y 3 cilindros = 7,83 CVF -> 5 € el trimestre
    Afirmar::euros(5.0, $ivtm(['cc' => 1000, 'cilindros' => 3])['importe']);
    // Madrid, marzo: 4 trimestres = cuota entera
    Afirmar::euros(129.0, $ivtm(['fecha_calculo' => '2026-03-15'])['importe']);
    Afirmar::euros(96.75, $ivtm(['fecha_calculo' => '2026-04-15'])['importe']);   // 3 trimestres: cifra de la tabla oficial

    // Madrid, eléctrico de 100 kW (CVF 19,40 -> 179 €): trimestre 44,75 menos la bonificación del 75 % de oficio (33,56) = 11,19
    $e = $ivtm(['combustible' => 'electrico', 'co2_wltp' => null, 'cc' => null, 'cilindros' => null, 'kw' => 100]);
    Afirmar::euros(11.19, $e['importe']);
    Afirmar::euros(179.0, $e['base']);

    // Salamanca (37274): 12-15,99 CVF = 71,94 x 2,0 = 143,88 al año -> 35,97 en octubre
    $s = $ivtm(['municipio' => '37274']);
    Afirmar::euros(35.97, $s['importe']);
    Afirmar::euros(143.88, $s['base']);
    Afirmar::igual(2.0, $s['tipo']);
    Afirmar::falso($s['verificar'], 'Salamanca está verificado');
    // Menos de 8 CVF: 12,62 x 1,9908 = 25,12 (la prensa local publica 25,12) -> 6,28 el trimestre
    $chico = $ivtm(['municipio' => '37274', 'cc' => 1000, 'cilindros' => 3]);
    Afirmar::euros(25.12, $chico['base']);
    Afirmar::euros(6.28, $chico['importe']);
    // 8-11,99 CVF: 34,08 x 1,9883 = 67,76 (cifra publicada para un turismo de 11,99 CVF)
    Afirmar::euros(67.76, $ivtm(['municipio' => '37274', 'cc' => 1500])['base']);
    // Salamanca: la bonificación del 75 % es rogada, así que no se resta; se avisa
    $r = Calculadora::calcular(entradaReal(['municipio' => '37274', 'combustible' => 'electrico', 'co2_wltp' => null, 'cc' => null, 'cilindros' => null, 'kw' => 100]), $t);
    Afirmar::euros(44.81, partida($r, 'ivtm')['importe']);   // 89,61 x 2,0 = 179,22 al año; 44,805 en octubre -> 44,81
    Afirmar::verdadero(in_array('ivtm_bonificacion', codigosAviso($r), true));
    Afirmar::falso(in_array('ivtm_bonificacion_aplicada', codigosAviso($r), true));
});

prueba('D13. Canarias, Navarra, País Vasco, Ceuta y Melilla quedan fuera del alcance y se rechazan con un mensaje claro', function (): void {
    $t = Tablas::cargar(dataDir(), 2026);
    $casos = ['35016' => 'Canarias', '38038' => 'Canarias', '31201' => 'Navarra', '48020' => 'País Vasco', '01059' => 'País Vasco', '20069' => 'País Vasco', '51001' => 'Ceuta', '52001' => 'Melilla'];
    foreach ($casos as $ine => $nombre) {
        $e = Afirmar::lanza(\CalculadoraImportacion\Calculo\EntradaInvalida::class, fn() => Calculadora::calcular(entradaReal(['municipio' => (string) $ine]), $t), $nombre);
        Afirmar::contiene('no está soportada', $e->getMessage(), $nombre);
    }
    // Y ninguna comunidad soportada necesita Canarias ni el régimen foral
    foreach ($t['ccaa'] as $codigo => $c) {
        if ($c['soportada']) {
            Afirmar::igual('comun', $c['regimen'], $codigo);
        }
    }
});

prueba('D14. IVA alemán verificado (19 %, UStG § 12)', function (): void {
    $iva = Tablas::cargar(dataDir(), 2026)['iva'];
    Afirmar::igual(19.0, $iva['iva_alemania']['valor']);
    Afirmar::falso($iva['iva_alemania']['verificar']);
    Afirmar::falso(isset($iva['igic']), 'el IGIC ya no forma parte del alcance');
});

prueba('D12. ITP real: Madrid y Castilla y León (Salamanca) verificados, más Andalucía, Castilla-La Mancha y La Rioja', function (): void {
    $t = Tablas::cargar(dataDir(), 2026);
    $itp = fn(array $cambios) => partida(Calculadora::calcular(entradaReal($cambios), $t), 'itp_iva');
    // Valor venal: 38.000 x 39 % (más de 5 y hasta 6 años) = 14.820
    // Madrid 4 %: 592,80
    $md = $itp([]);
    Afirmar::euros(592.80, $md['importe']);
    Afirmar::igual(4.0, $md['tipo']);
    Afirmar::falso($md['verificar']);
    // Madrid: valor venal inferior a 500 € -> bonificación del 100 % (art. 30 ter): 1.000 x 39 % = 390
    Afirmar::euros(0.0, $itp(['fila_boe' => ['precio' => 1000]])['importe']);
    // Castilla y León (Salamanca, 37274): 5 % = 741,00; con más de 15 CVF (3.000 cm³, 6 cil. = 19,98) 8 % = 1.185,60
    $cl = $itp(['municipio' => '37274']);
    Afirmar::euros(741.0, $cl['importe']);
    Afirmar::igual(5.0, $cl['tipo']);
    Afirmar::falso($cl['verificar']);
    Afirmar::euros(1185.60, $itp(['municipio' => '37274', 'cc' => 3000, 'cilindros' => 6])['importe']);
    // Exactamente 15 CVF no es «superior a 15»: la regla usa «mayor que»
    Afirmar::euros(741.0, $itp(['municipio' => '37274', 'fila_boe' => ['precio' => 38000, 'cvf' => 15.0]])['importe']);
    // Castilla-La Mancha 6 % = 889,20 (Toledo, 45168) y La Rioja 4 % = 592,80 (Logroño, 26089)
    Afirmar::euros(889.20, $itp(['municipio' => '45168'])['importe']);
    Afirmar::euros(592.80, $itp(['municipio' => '26089'])['importe']);
    // Andalucía (Sevilla, 41091): 4 % = 592,80; más de 15 CVF 8 % = 1.185,60; eléctrico 1 % = 148,20
    Afirmar::euros(592.80, $itp(['municipio' => '41091'])['importe']);
    Afirmar::euros(1185.60, $itp(['municipio' => '41091', 'cc' => 3000, 'cilindros' => 6])['importe']);
    Afirmar::euros(148.20, $itp(['municipio' => '41091', 'combustible' => 'electrico', 'co2_wltp' => null, 'cc' => null, 'cilindros' => null, 'kw' => 100])['importe']);
    // Comunidades con reglas de vehículos pendientes: no se calcula y se explica
    foreach (['15030' => 'Galicia', '36057' => 'Galicia', '33044' => 'Asturias', '30030' => 'Murcia', '46250' => 'Comunitat Valenciana'] as $ine => $nombre) {
        $p = $itp(['municipio' => (string) $ine]);
        Afirmar::igual('no_calculable', $p['estado'], $nombre);
        Afirmar::contiene('aún no hemos incorporado', $p['motivo'], $nombre);
    }
});
