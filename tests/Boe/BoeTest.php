<?php

declare(strict_types=1);

use CalculadoraImportacion\Boe\Emparejador;
use CalculadoraImportacion\Boe\Marcas;
use CalculadoraImportacion\Boe\Normalizador;
use CalculadoraImportacion\Boe\Repositorio;

const DIR_DATA_BOE = __DIR__ . '/../../data';

function repoBoe(): Repositorio
{
    static $repo = null;
    $repo ??= Repositorio::abrir(DIR_DATA_BOE, 2026) ?? throw new \RuntimeException('Falta data/boe/2026.sqlite');
    return $repo;
}

function emparejador(): Emparejador
{
    static $e = null;
    $e ??= new Emparejador(repoBoe(), Marcas::cargar(DIR_DATA_BOE));
    return $e;
}

prueba('B1. El normalizador quita acentos y signos, junta «320 d» y descarta rellenos', function (): void {
    Afirmar::igual('citroen c4 1.6 hdi', Normalizador::texto('Citroën C4, 1,6 HDi'));
    Afirmar::igual('320d touring', Normalizador::texto('320 d Touring'));
    Afirmar::igual('c 220d', Normalizador::texto('C 220 d'));
    Afirmar::igual('estate', Normalizador::texto('T-Modell'));
    Afirmar::igual('1.0 ecoboost titanium', Normalizador::texto('1.0 EcoBoost; Titanium.'));
    Afirmar::igual(['golf', 'vii', '2.0', 'tdi', 'highline'], Normalizador::palabras('Golf VII 2.0 TDI Highline Aut. 5p'));
    Afirmar::igual('mercedes benz', Normalizador::marca('Mercedes-Benz'));
});

prueba('B2. El diccionario de marcas traduce los nombres de mobile.de a los del BOE', function (): void {
    $m = Marcas::cargar(DIR_DATA_BOE);
    foreach (['Mercedes-Benz' => 'MERCEDES', 'mercedes benz' => 'MERCEDES', 'VW' => 'VOLKSWAGEN', 'Škoda' => 'SKODA', 'Citroën' => 'CITROEN',
        'SsangYong' => 'SSANYONG', 'Alfa Romeo' => 'ALFA ROMEO', 'land-rover' => 'LAND ROVER', 'SEAT' => 'SEAT', 'BMW' => 'BMW'] as $entrada => $boe) {
        Afirmar::igual($boe, $m->resolver($entrada), $entrada);
    }
    Afirmar::igual(null, $m->resolver('Ora'), 'una marca que no está en la Orden');
    $t = $m->desdeTitulo('Mercedes-Benz C 220 d T-Modell');
    Afirmar::igual('MERCEDES', $t['marca'] ?? null);
    Afirmar::igual('c 220d estate', $t['resto'] ?? null);
    Afirmar::igual('ALFA ROMEO', $m->desdeTitulo('Alfa Romeo Giulia 2.2')['marca'] ?? null, 'gana el alias más largo');
    Afirmar::igual('Mercedes-Benz', $m->nombreVisible('MERCEDES'));
});

prueba('B3. La base del BOE trae las filas del anexo I y se abre solo para leer', function (): void {
    $meta = repoBoe()->meta();
    Afirmar::igual('2026', $meta['anio']);
    Afirmar::igual('61634', $meta['filas']);
    Afirmar::verdadero(count(repoBoe()->marcas()) >= 100);
    // Primera fila del anexo (p. 4 de la Orden): 124 Spider de Abarth
    $f = repoBoe()->filasDeMarca('ABARTH')[0];
    Afirmar::igual('124 1.4 Spider / TB Multiair 6V Aut.', $f['modelo_tipo']);
    Afirmar::igual([2016, 2021, 1368, 4, 'gasolina', 125.0, 10.61, 170, 33400], [$f['inicio'], $f['fin'], $f['cc'], $f['cilindros'], $f['combustible'], $f['kw'], $f['cvf'], $f['cv'], $f['valor']]);
    // Un eléctrico no tiene cilindrada ni periodo de fin
    $e = repoBoe()->fila(52597);
    Afirmar::igual(['MODEL 3 Gran Autonomía AWD', null, 'electrico', 51800], [$e['modelo_tipo'], $e['cc'], $e['combustible'], $e['valor']]);
    Afirmar::igual(null, repoBoe()->fila(99999999));
    // Una fila cuyo nombre ocupa dos líneas en el PDF se ha leído entera
    $nombres = array_column(repoBoe()->filasDeMarca('AUDI'), 'modelo_tipo');
    Afirmar::verdadero(in_array('A3 1.8 T. Ambition Aut Plus / T Tipt. Ambition Plus / T Quattro Ambition', $nombres, true));
});

prueba('B4. Ninguna fila de la base está incompleta o es absurda', function (): void {
    $malas = [];
    foreach (repoBoe()->marcas() as $marca) {
        foreach (repoBoe()->filasDeMarca($marca) as $f) {
            $ok = $f['valor'] >= 1000 && $f['valor'] <= 1_000_000 && $f['cv'] > 0 && $f['kw'] > 0
                && ($f['inicio'] === null || ($f['inicio'] >= 1980 && $f['inicio'] <= 2027))
                && ($f['fin'] === null || $f['inicio'] === null || $f['fin'] >= $f['inicio'])
                && ($f['cvf'] === null || ($f['cvf'] > 0 && $f['cvf'] < 300))
                && trim($f['modelo_tipo']) !== '';
            if (!$ok) {
                $malas[] = $f['id'] . ' ' . $f['marca'] . ' ' . $f['modelo_tipo'];
            }
        }
    }
    Afirmar::igual([], array_slice($malas, 0, 10), count($malas) . ' filas raras');
});

prueba('B5. Un Focus 1.0 EcoBoost de 2019 se empareja con su fila exacta, con puntuación alta', function (): void {
    $r = emparejador()->buscar(['marca' => 'Ford', 'modelo' => 'Focus 1.0 EcoBoost Titanium', 'anio' => 2019, 'cc' => 999, 'cv' => 125, 'combustible' => 'gasolina']);
    Afirmar::igual('FORD', $r['marca_boe']);
    Afirmar::igual(3, count($r['candidatas']));
    $mejor = $r['candidatas'][0];
    Afirmar::igual('FOCUS 1.0 Ecoboost Titanium', $mejor['fila']['modelo_tipo']);
    Afirmar::igual(19600, $mejor['fila']['valor']);
    Afirmar::igual('alta', $mejor['confianza']);
    Afirmar::verdadero($mejor['puntuacion'] >= $r['candidatas'][1]['puntuacion']);
    Afirmar::verdadero($r['candidatas'][1]['puntuacion'] >= $r['candidatas'][2]['puntuacion'], 'ordenadas de mayor a menor');
    Afirmar::verdadero(count($mejor['motivos']) === 5, 'explica cada criterio');
});

prueba('B6. Una marca escrita a la alemana y un modelo con otro nombre en el BOE también se encuentran', function (): void {
    $r = emparejador()->buscar(['marca' => 'Mercedes-Benz', 'modelo' => 'C 220 d T-Modell', 'anio' => 2018, 'cc' => 1950, 'cv' => 194, 'combustible' => 'diesel']);
    Afirmar::igual('MERCEDES', $r['marca_boe']);
    Afirmar::contiene('Estate', $r['candidatas'][0]['fila']['modelo_tipo']);
    Afirmar::igual('D', $r['candidatas'][0]['fila']['motor_codigo']);
});

prueba('B7. Un eléctrico se empareja sin cilindrada y su kW manda sobre otros Model de la misma marca', function (): void {
    $r = emparejador()->buscar(['marca' => 'Tesla', 'modelo' => 'Model 3 Long Range', 'anio' => 2021, 'kw' => 350, 'combustible' => 'electrico']);
    $f = $r['candidatas'][0]['fila'];
    Afirmar::igual('MODEL 3 Gran Autonomía AWD', $f['modelo_tipo']);
    Afirmar::igual(null, $f['cc']);
    Afirmar::verdadero(array_key_exists('cc', $r['candidatas'][0]['detalle']) && $r['candidatas'][0]['detalle']['cc'] === null, 'sin cilindrada en el anuncio no cuenta');
    // Con cilindrada inventada para un eléctrico, ese criterio puntúa 0
    $s = emparejador()->buscar(['marca' => 'Tesla', 'modelo' => 'Model 3', 'anio' => 2021, 'cc' => 1998, 'kw' => 350, 'combustible' => 'electrico']);
    Afirmar::igual(0.0, $s['candidatas'][0]['detalle']['cc']);
});

prueba('B8. El combustible distinto penaliza y la potencia se compara con tolerancias de 1, 5 y 10 %', function (): void {
    $diesel = emparejador()->buscar(['marca' => 'Volkswagen', 'modelo' => 'Golf VII 2.0 TDI', 'anio' => 2019, 'cc' => 1968, 'cv' => 150, 'combustible' => 'diesel']);
    $gasolina = emparejador()->buscar(['marca' => 'Volkswagen', 'modelo' => 'Golf VII 2.0 TDI', 'anio' => 2019, 'cc' => 1968, 'cv' => 150, 'combustible' => 'gasolina']);
    Afirmar::verdadero($diesel['candidatas'][0]['puntuacion'] > $gasolina['candidatas'][0]['puntuacion'] + 0.1);
    Afirmar::igual(1.0, $diesel['candidatas'][0]['detalle']['potencia']);
    $fila = ['inicio' => 2016, 'fin' => 2021, 'cc' => 1968, 'kw' => 110.0, 'cv' => 150, 'motor_codigo' => 'D', 'modelo_tipo' => 'X', 'id' => 1];
    $q = ['palabras' => [], 'anio' => null, 'cc' => null, 'cv' => null, 'kw' => null, 'combustible' => null];
    foreach ([150 => 1.0, 151 => 1.0, 155 => 0.7, 157 => 0.7, 160 => 0.3, 165 => 0.3, 170 => 0.0, 90 => 0.0] as $cv => $esperado) {
        Afirmar::igual($esperado, emparejador()->puntuar($fila, ['cv' => (float) $cv] + $q)['detalle']['potencia'], "$cv CV");
    }
    Afirmar::igual(1.0, emparejador()->puntuar($fila, ['kw' => 110.0] + $q)['detalle']['potencia'], 'también por kW');
    Afirmar::igual(1.0, emparejador()->puntuar($fila, ['kw' => 90.0, 'cv' => 150.0] + $q)['detalle']['potencia'], 'si una de las dos medidas coincide, vale');
    Afirmar::igual(0.7, emparejador()->puntuar($fila, ['cc' => 1900] + $q)['detalle']['cc']);
    Afirmar::igual(0.0, emparejador()->puntuar($fila, ['combustible' => 'gasolina'] + $q)['detalle']['combustible']);
    Afirmar::igual(0.8, emparejador()->puntuar(['motor_codigo' => 'DyE'] + $fila, ['combustible' => 'diesel'] + $q)['detalle']['combustible'], 'diésel con mild hybrid');
});

prueba('B9. El año fuera del periodo comercial resta según la distancia; sin periodo no decide', function (): void {
    $e = emparejador();
    $fila = ['inicio' => 2016, 'fin' => 2021, 'cc' => 1000, 'kw' => 70.0, 'cv' => 95, 'motor_codigo' => 'G', 'modelo_tipo' => 'X', 'id' => 1];
    $q = ['palabras' => [], 'anio' => 2019, 'cc' => null, 'cv' => null, 'kw' => null, 'combustible' => null];
    Afirmar::igual(1.0, $e->puntuar($fila, $q)['detalle']['anio']);
    Afirmar::igual(1.0, $e->puntuar($fila, ['anio' => 2021] + $q)['detalle']['anio'], 'el último año cuenta');
    Afirmar::igual(0.7, $e->puntuar($fila, ['anio' => 2022] + $q)['detalle']['anio']);
    Afirmar::igual(0.4, $e->puntuar($fila, ['anio' => 2014] + $q)['detalle']['anio']);
    Afirmar::igual(0.0, $e->puntuar($fila, ['anio' => 2000] + $q)['detalle']['anio']);
    Afirmar::igual(1.0, $e->puntuar(['fin' => null] + $fila, ['anio' => 2026] + $q)['detalle']['anio'], 'sin fin sigue a la venta');
    Afirmar::igual(0.5, $e->puntuar(['inicio' => null, 'fin' => null] + $fila, $q)['detalle']['anio']);
    Afirmar::igual(null, $e->puntuar($fila, ['anio' => null] + $q)['detalle']['anio'], 'sin año en el coche no cuenta');
});

prueba('B10. Una marca que no está en la Orden o sin datos devuelve una lista vacía, no un error', function (): void {
    $r = emparejador()->buscar(['marca' => 'Ora', 'modelo' => 'Funky Cat', 'anio' => 2023]);
    Afirmar::igual(['marca_boe' => null, 'candidatas' => []], $r);
    $r = emparejador()->buscar(['marca' => 'Ford', 'modelo' => 'zzzzzz qqqq', 'anio' => 1960, 'cc' => 50, 'cv' => 3, 'combustible' => 'diesel']);
    Afirmar::igual('FORD', $r['marca_boe']);
    Afirmar::igual([], $r['candidatas'], 'nada se parece lo bastante');
    Afirmar::igual(['marca_boe' => null, 'candidatas' => []], emparejador()->buscar([]));
});

prueba('B11. El emparejamiento tarda poco incluso con la marca más grande', function (): void {
    $t = microtime(true);
    emparejador()->buscar(['marca' => 'Mercedes-Benz', 'modelo' => 'E 220 d', 'anio' => 2019, 'cc' => 1950, 'cv' => 194, 'combustible' => 'diesel']);
    $ms = (microtime(true) - $t) * 1000;
    Afirmar::verdadero($ms < 1500, "tardó $ms ms");
});

prueba('B12. Una versión con designación propia no se confunde con otra de la misma familia (Model S 85D no es 90D)', function (): void {
    $coche = ['marca' => 'Tesla', 'anio' => 2016, 'kw' => 315, 'cv' => 428, 'combustible' => 'electrico'];
    $r = emparejador()->buscar($coche + ['modelo' => 'Model S 85D'], 5);
    foreach ($r['candidatas'] as $c) {
        Afirmar::verdadero($c['confianza'] !== 'alta', $c['fila']['modelo_tipo'] . ': ninguna fila de otra versión puede ser una coincidencia alta');
    }
    $noventa = array_values(array_filter($r['candidatas'], static fn(array $c): bool => $c['fila']['modelo_tipo'] === 'MODEL S 90D'))[0];
    Afirmar::verdadero(in_array('la versión del anuncio («85D») no aparece en esta fila, que es la «90D»', $noventa['motivos'], true), 'explica el porqué: ' . json_encode($noventa['motivos']));
    Afirmar::verdadero($noventa['detalle']['nombre'] <= 0.4, 'el nombre se castiga');
    // La misma fila con la versión que sí es la suya sigue siendo una coincidencia alta
    $b = emparejador()->buscar(['kw' => 310, 'cv' => 421] + $coche + ['modelo' => 'Model S 90D'], 1)['candidatas'][0];
    Afirmar::igual('MODEL S 90D', $b['fila']['modelo_tipo']);
    Afirmar::igual('alta', $b['confianza']);
    foreach ($b['motivos'] as $m) {
        Afirmar::falso(str_starts_with($m, 'la versión'), 'sin contradicción no hay aviso');
    }
    // «P100D» y «100D» son versiones distintas; «85D» sí encaja con una fila «85 kWh» (mismo número)
    $p = emparejador()->buscar(['kw' => 515, 'cv' => 700] + $coche + ['modelo' => 'Model S P100D'], 1)['candidatas'][0];
    Afirmar::igual('MODEL S P100D', $p['fila']['modelo_tipo']);
    $filaOchentaYCinco = ['id' => 1, 'marca' => 'TESLA', 'modelo_tipo' => 'MODEL S 85 kWh', 'inicio' => 2013, 'fin' => 2016, 'cc' => null, 'kw' => 270.0, 'cv' => 367, 'motor_codigo' => 'Elc'];
    $q = ['palabras' => Normalizador::palabras('Model S 85D'), 'anio' => 2016, 'cc' => null, 'cv' => null, 'kw' => null, 'combustible' => 'electrico'];
    $o = emparejador()->puntuar($filaOchentaYCinco, $q);
    Afirmar::verdadero($o['detalle']['nombre'] > 0.5, 'el 85 de «85 kWh» es el 85 de «85D»');
});

prueba('B13. Letras y cifras: «320d» no es «318d»; «500X» no es «500L»; los decimales y los años no cuentan como versión', function (): void {
    $bmw = emparejador()->buscar(['marca' => 'BMW', 'modelo' => '318d', 'anio' => 2017, 'cc' => 1995, 'kw' => 110, 'combustible' => 'diesel'], 3)['candidatas'];
    Afirmar::igual('318 d', explode(' (', $bmw[0]['fila']['modelo_tipo'])[0]);
    foreach ($bmw as $c) {
        if (str_starts_with($c['fila']['modelo_tipo'], '320')) {
            Afirmar::verdadero($c['confianza'] !== 'alta', '320 d no es una coincidencia alta de 318d');
        }
    }
    $fiat = emparejador()->buscar(['marca' => 'Fiat', 'modelo' => '500L 1.3 Multijet', 'anio' => 2018, 'cc' => 1248, 'kw' => 70, 'combustible' => 'diesel'], 1)['candidatas'][0];
    Afirmar::contiene('500L', $fiat['fila']['modelo_tipo']);
    // Los números sueltos de una cifra, los decimales y los años no son «designaciones»: la versión «2.0 TDI» sigue encajando
    $golf = emparejador()->buscar(['marca' => 'Volkswagen', 'modelo' => 'Golf VII 2.0 TDI Highline 2019', 'anio' => 2019, 'cc' => 1968, 'cv' => 150, 'combustible' => 'diesel'], 1)['candidatas'][0];
    Afirmar::igual('alta', $golf['confianza']);
});
