<?php

declare(strict_types=1);

use CalculadoraImportacion\Calculo\Calculadora;

/*
 * Escenarios completos con tablas de prueba. Cada uno lleva su cálculo hecho a mano en el comentario.
 * Los números esperados se contrastaron con un cálculo independiente fuera de PHP.
 *
 * Constantes comunes (ver tests/ayuda.php): costes fijos 500 €; IVA ES 20 %; IVTM de 28900 con coeficiente 2.
 * Transporte a Madrid desde 80xxx (50°N -3°E a 40°N -3°E): 10° de latitud = 1.111,9493 km en recta
 * (6.371 x 10 x pi/180) x 1,25 = 1.389,9366 km por carretera.
 */

prueba('1. Gasolina usada de particular en Madrid: IEDMT con minoración + ITP', function (): void {
    // Antigüedad: 15/06/2022 -> 10/10/2026 = 4 años completos -> 50 %.
    // Valor venal: 26.000 x 50 % = 13.000.
    // IEDMT: 170 g/km -> 10 %. Base = 13.000 / (1 + (20 + 10)/100) = 13.000 / 1,30 = 10.000. Cuota = 1.000.
    // ITP (Madrid 4 %): 13.000 x 4 % = 520.
    // CVF = 0,08 x (1.998/4)^0,6 x 4 = 13,31 -> tarifa 70 x coef 2 = 140 al año; octubre = 1 trimestre -> 35.
    // Transporte: 1.389,9366 km x 7/100 x 1,50 = 145,94.
    // Total: 14.000 + 1.000 + 520 + 35 + 500 + 145,94 = 16.200,94.
    $r = Calculadora::calcular(entradaBase(), tablasPrueba());
    Afirmar::euros(13000, partida($r, 'valor_venal')['importe']);
    Afirmar::euros(1000, partida($r, 'iedmt')['importe']);
    Afirmar::euros(10000, partida($r, 'iedmt')['base']);
    Afirmar::euros(520, partida($r, 'itp_iva')['importe']);
    Afirmar::euros(35, partida($r, 'ivtm')['importe']);
    Afirmar::euros(140, partida($r, 'ivtm')['base']);
    Afirmar::euros(145.94, partida($r, 'transporte')['importe']);
    Afirmar::euros(16200.94, $r['total']);
    Afirmar::euros(2200.94, $r['coste_sobre_precio']);
    Afirmar::verdadero($r['completo']);
    Afirmar::falso($r['derivados']['nuevo']);
});

prueba('2. Diésel usado de 12 años en Cataluña: reducción de ITP por antigüedad y coeficiente IVTM sin verificar', function (): void {
    // Antigüedad: 20/01/2014 -> 10/10/2026 = 12 años -> 20 %. Valor venal: 20.000 x 20 % = 4.000.
    // IEDMT: 150 g/km -> 5 %. Base = 4.000 / 1,25 = 3.200. Cuota = 160.
    // ITP Cataluña: regla de 10 o más años -> 3 % (en vez del 6 % general): 4.000 x 3 % = 120.
    // CVF = 0,08 x (1.968/4)^0,6 x 4 = 13,19 -> tarifa 70; el municipio 08900 no tiene coeficiente -> 1,0
    //   -> 70 al año; 1 trimestre -> 17,50.
    // Transporte: desde 803xx (48°N) a 41°N: 7° -> 972,9556 km x 6/100 x 1,40 = 81,73.
    // Total: 3.000 + 160 + 120 + 17,50 + 500 + 81,73 = 3.879,23.
    $r = Calculadora::calcular(entradaBase([
        'fecha_matriculacion' => '2014-01-20', 'km' => 150000, 'cc' => 1968, 'combustible' => 'diesel',
        'co2_wltp' => 150, 'precio' => 3000, 'cp_vendedor' => '80312', 'municipio' => '08900',
        'fila_boe' => ['precio' => 20000],
    ]), tablasPrueba());
    Afirmar::euros(4000, partida($r, 'valor_venal')['importe']);
    Afirmar::euros(160, partida($r, 'iedmt')['importe']);
    Afirmar::euros(120, partida($r, 'itp_iva')['importe']);
    Afirmar::igual(3.0, partida($r, 'itp_iva')['tipo']);
    Afirmar::euros(17.5, partida($r, 'ivtm')['importe']);
    Afirmar::euros(81.73, partida($r, 'transporte')['importe']);
    Afirmar::euros(3879.23, $r['total']);
    Afirmar::verdadero(in_array('ivtm_tarifa_minima', codigosAviso($r), true));
    // 150 g/km es el tramo del 5 %: el incremento autonómico de Cataluña solo afecta al tramo de 200 g/km o más
    Afirmar::igual(5.0, partida($r, 'iedmt')['tipo']);
});

prueba('3. Eléctrico usado de particular: IEDMT 0 %, CVF por kW', function (): void {
    // Antigüedad: 01/09/2023 -> 10/10/2026 = 3 años -> 50 %. Valor venal: 40.000 x 50 % = 20.000.
    // IEDMT: eléctrico = 0 %. Base = 20.000 / 1,20 = 16.666,67. Cuota = 0.
    // ITP Madrid 4 %: 800.
    // CVF = 100 kW / 5,152 = 19,40 (truncado) -> tarifa 90 x coef 2 = 180 al año -> 1 trimestre = 45.
    // Transporte: 1.389,9366 km x 20 kWh/100 x 0,30 = 83,40.
    // Total: 25.000 + 0 + 800 + 45 + 500 + 83,40 = 26.428,40.
    $r = Calculadora::calcular(entradaBase([
        'fecha_matriculacion' => '2023-09-01', 'km' => 30000, 'cc' => null, 'cilindros' => null, 'kw' => 100,
        'combustible' => 'electrico', 'co2_wltp' => null, 'precio' => 25000, 'fila_boe' => ['precio' => 40000],
    ]), tablasPrueba());
    Afirmar::euros(0, partida($r, 'iedmt')['importe']);
    Afirmar::euros(16666.67, partida($r, 'iedmt')['base']);
    Afirmar::euros(800, partida($r, 'itp_iva')['importe']);
    Afirmar::euros(19.40, $r['derivados']['cvf']);
    Afirmar::euros(45, partida($r, 'ivtm')['importe']);
    Afirmar::euros(83.40, partida($r, 'transporte')['importe']);
    Afirmar::euros(26428.40, $r['total']);
});

prueba('4. Híbrido usado de particular: CVF con la cilindrada del motor térmico, CO2 bajo', function (): void {
    // Antigüedad: 10/02/2024 -> 2 años -> 60 %. Valor venal: 30.000 x 60 % = 18.000.
    // IEDMT: 110 g/km -> 0 %. Base = 18.000 / 1,20 = 15.000. Cuota = 0.
    // ITP Madrid 4 %: 720.
    // CVF = 0,08 x (1.798/4)^0,6 x 4 = 12,49 (truncado) -> tarifa 70 x 2 = 140 -> 35.
    // Transporte híbrido: 1.389,9366 x 5/100 x 1,50 = 104,25.
    // Total: 21.000 + 0 + 720 + 35 + 500 + 104,25 = 22.359,25.
    $r = Calculadora::calcular(entradaBase([
        'fecha_matriculacion' => '2024-02-10', 'km' => 40000, 'cc' => 1798, 'combustible' => 'hibrido',
        'co2_wltp' => 110, 'precio' => 21000, 'fila_boe' => ['precio' => 30000],
    ]), tablasPrueba());
    Afirmar::euros(18000, partida($r, 'valor_venal')['importe']);
    Afirmar::euros(0, partida($r, 'iedmt')['importe']);
    Afirmar::euros(15000, partida($r, 'iedmt')['base']);
    Afirmar::euros(720, partida($r, 'itp_iva')['importe']);
    Afirmar::euros(12.49, $r['derivados']['cvf']);
    Afirmar::euros(104.25, partida($r, 'transporte')['importe']);
    Afirmar::euros(22359.25, $r['total']);
});

prueba('5. Híbrido enchufable (PHEV) usado de profesional: sin ITP ni IVA', function (): void {
    // Antigüedad: 10/03/2023 -> 3 años -> 50 %. Valor venal: 36.000 x 50 % = 18.000.
    // IEDMT: 40 g/km -> 0 %. Base 18.000 / 1,20 = 15.000. Cuota 0.
    // ITP/IVA: profesional y usado -> 0.
    // CVF = 0,08 x (1.598/4)^0,6 x 4 = 11,64 -> tarifa 30 x 2 = 60 -> 15.
    // Transporte PHEV: 1.389,9366 x 6/100 x 1,50 = 125,09.
    // Total: 20.000 + 0 + 0 + 15 + 500 + 125,09 = 20.640,09.
    $r = Calculadora::calcular(entradaBase([
        'fecha_matriculacion' => '2023-03-10', 'km' => 50000, 'cc' => 1598, 'combustible' => 'phev',
        'co2_wltp' => 40, 'precio' => 20000, 'vendedor' => 'profesional', 'fila_boe' => ['precio' => 36000],
    ]), tablasPrueba());
    Afirmar::euros(0, partida($r, 'iedmt')['importe']);
    Afirmar::euros(0, partida($r, 'itp_iva')['importe']);
    Afirmar::euros(15, partida($r, 'ivtm')['importe']);
    Afirmar::euros(125.09, partida($r, 'transporte')['importe']);
    Afirmar::euros(20640.09, $r['total']);
    Afirmar::verdadero(in_array('usado_profesional', codigosAviso($r), true));
});

prueba('6. Nuevo por antigüedad (< 6 meses) de profesional con IVA desglosable: IVA español sobre el neto', function (): void {
    // 15/06/2026 -> 10/10/2026: 3 meses -> nuevo, aunque tenga 12.000 km. Precio 33.000 con 10 % de IVA alemán
    //   -> neto 33.000 / 1,10 = 30.000 (lo que se paga al vendedor).
    // IVA español: 30.000 x 20 % = 6.000. IEDMT (nuevo: sobre el precio neto, sin minoración): 150 g/km -> 5 % = 1.500.
    // CVF = 0,08 x (1.500/4)^0,6 x 4 = 11,20 (truncado) -> tarifa 30 x 2 = 60 -> 15.
    // Transporte gasolina: 145,94.
    // Total: 30.000 + 1.500 + 6.000 + 15 + 500 + 145,94 = 38.160,94.
    $r = Calculadora::calcular(entradaBase([
        'fecha_matriculacion' => '2026-06-15', 'km' => 12000, 'cc' => 1500, 'co2_wltp' => 150,
        'precio' => 33000, 'vendedor' => 'profesional', 'iva_deducible' => true, 'fila_boe' => ['precio' => 36000],
    ]), tablasPrueba());
    Afirmar::verdadero($r['derivados']['nuevo']);
    Afirmar::verdadero($r['derivados']['nuevo_por_meses']);
    Afirmar::falso($r['derivados']['nuevo_por_km']);
    Afirmar::euros(30000, partida($r, 'precio')['importe']);
    Afirmar::euros(33000, partida($r, 'precio')['base']);
    Afirmar::euros(6000, partida($r, 'itp_iva')['importe']);
    Afirmar::euros(1500, partida($r, 'iedmt')['importe']);
    Afirmar::euros(38160.94, $r['total']);
});

prueba('7. Nuevo por kilómetros (5.500 km) con casi dos años: IVA sobre el precio de un particular', function (): void {
    // 10/01/2025 -> 21 meses (no es nuevo por tiempo) pero 5.500 km <= 6.000 -> nuevo.
    // Particular: sin IVA alemán, la base es el precio: 20.000 x 20 % = 4.000.
    // IEDMT: 190 g/km -> 10 %, base 20.000 -> 2.000.
    // CVF = 0,08 x (1.332/4)^0,6 x 4 = 10,43 (truncado) -> tarifa 30 x 2 = 60 -> 15.
    // Total: 20.000 + 2.000 + 4.000 + 15 + 500 + 145,94 = 26.660,94.
    $r = Calculadora::calcular(entradaBase([
        'fecha_matriculacion' => '2025-01-10', 'km' => 5500, 'cc' => 1332, 'co2_wltp' => 190,
        'precio' => 20000, 'fila_boe' => ['precio' => 30000],
    ]), tablasPrueba());
    Afirmar::verdadero($r['derivados']['nuevo']);
    Afirmar::falso($r['derivados']['nuevo_por_meses']);
    Afirmar::verdadero($r['derivados']['nuevo_por_km']);
    Afirmar::euros(4000, partida($r, 'itp_iva')['importe']);
    Afirmar::euros(2000, partida($r, 'iedmt')['importe']);
    Afirmar::euros(26660.94, $r['total']);
    Afirmar::falso(in_array('iva_nuevo_sin_desglose', codigosAviso($r), true));
});

prueba('8. Usado de profesional con "MwSt. ausweisbar": no se paga ITP ni IVA y el IVA alemán no se recupera', function (): void {
    // Antigüedad 5 años -> 40 %. Valor venal: 30.000 x 40 % = 12.000.
    // IEDMT: 130 g/km -> 5 %. Base = 12.000 / 1,25 = 9.600. Cuota = 480.
    // CVF 13,31 -> tarifa 70 x 2 = 140 -> 35. Transporte 145,94.
    // Total: 15.000 + 480 + 0 + 35 + 500 + 145,94 = 16.160,94 (el precio NO se reduce: es un usado).
    $r = Calculadora::calcular(entradaBase([
        'fecha_matriculacion' => '2021-05-05', 'km' => 90000, 'co2_wltp' => 130, 'precio' => 15000,
        'vendedor' => 'profesional', 'iva_deducible' => true, 'fila_boe' => ['precio' => 30000],
    ]), tablasPrueba());
    Afirmar::falso($r['derivados']['nuevo']);
    Afirmar::euros(15000, partida($r, 'precio')['importe']);
    Afirmar::euros(480, partida($r, 'iedmt')['importe']);
    Afirmar::euros(0, partida($r, 'itp_iva')['importe']);
    Afirmar::euros(16160.94, $r['total']);
    Afirmar::verdadero(in_array('iva_no_recuperable', codigosAviso($r), true));
});

prueba('10. ITP de una comunidad con tipos por caballos fiscales (Comunitat Valenciana)', function (): void {
    // Valor venal 13.000. CVF >= 15 -> 10 %; si no, 8 % general.
    // 2.500 cm³ y 6 cilindros -> CVF 17,91 -> 10 % -> 1.300. 1.500 cm³ y 4 cilindros -> CVF 11,20 -> 8 % -> 1.040.
    $grande = Calculadora::calcular(entradaBase(['municipio' => '46900', 'cc' => 2500, 'cilindros' => 6]), tablasPrueba());
    Afirmar::euros(17.91, $grande['derivados']['cvf']);
    Afirmar::euros(1300, partida($grande, 'itp_iva')['importe']);
    $pequeno = Calculadora::calcular(entradaBase(['municipio' => '46900', 'cc' => 1500]), tablasPrueba());
    Afirmar::euros(1040, partida($pequeno, 'itp_iva')['importe']);
});

prueba('11. ITP con cuota fija por antigüedad (Illes Balears, 15 o más años)', function (): void {
    // 01/01/2010 -> 10/10/2026 = 16 años -> la regla fija 50 € en lugar del 4 %.
    $r = Calculadora::calcular(entradaBase(['municipio' => '07900', 'fecha_matriculacion' => '2010-01-01']), tablasPrueba());
    Afirmar::euros(50, partida($r, 'itp_iva')['importe']);
    Afirmar::igual(null, partida($r, 'itp_iva')['tipo']);
});

prueba('12. ITP pendiente de verificar (Andalucía, fuentes en conflicto): no se inventa y el total es parcial', function (): void {
    $r = Calculadora::calcular(entradaBase(['municipio' => '41900']), tablasPrueba());
    $p = partida($r, 'itp_iva');
    Afirmar::igual('no_calculable', $p['estado']);
    Afirmar::igual(null, $p['importe']);
    Afirmar::contiene('4 % (fuente A)', $p['motivo']);
    Afirmar::contiene('8 % (fuente B)', $p['motivo']);
    Afirmar::falso($r['completo']);
    // El total suma solo lo calculable. IVTM: el municipio 41900 no tiene coeficiente -> 70 al año -> 17,50.
    // 14.000 + 1.000 + 17,50 + 500 + 145,94 = 15.663,44
    Afirmar::euros(15663.44, $r['total']);
    Afirmar::verdadero(in_array('total_parcial', codigosAviso($r), true));
});

prueba('13. Sin fila del BOE no hay valor venal: IEDMT e ITP no se calculan, el resto sí', function (): void {
    $e = entradaBase();
    unset($e['fila_boe']);
    $r = Calculadora::calcular($e, tablasPrueba());
    Afirmar::igual('no_calculable', partida($r, 'valor_venal')['estado']);
    Afirmar::igual('no_calculable', partida($r, 'iedmt')['estado']);
    Afirmar::igual('no_calculable', partida($r, 'itp_iva')['estado']);
    Afirmar::igual('ok', partida($r, 'ivtm')['estado']);   // CVF por fórmula, no necesita el BOE
    Afirmar::falso($r['completo']);
});

prueba('14. Sin CO2 en un coche de combustión el IEDMT no se calcula (no se inventa el tramo)', function (): void {
    $r = Calculadora::calcular(entradaBase(['co2_wltp' => null]), tablasPrueba());
    Afirmar::igual('no_calculable', partida($r, 'iedmt')['estado']);
    Afirmar::contiene('CO2', partida($r, 'iedmt')['motivo']);
    Afirmar::igual('ok', partida($r, 'itp_iva')['estado']);
});

prueba('15. Transporte en camión portavehículos: precio por km', function (): void {
    // 1.389,9366 km x 0,50 €/km = 694,97. Total: 14.000 + 1.000 + 520 + 35 + 500 + 694,97 = 16.749,97.
    $r = Calculadora::calcular(entradaBase(['transporte' => ['modo' => 'camion']]), tablasPrueba());
    $p = partida($r, 'transporte');
    Afirmar::euros(694.97, $p['importe']);
    Afirmar::igual('€/km', $p['tipo_unidad']);
    Afirmar::euros(16749.97, $r['total']);
    Afirmar::euros(1389.9, $r['derivados']['distancia']['carretera_km']);   // 1.389,9366 redondeado a 1 decimal
    Afirmar::euros(17.4, $r['derivados']['distancia']['horas']);            // 1.389,9366 / 80 km/h
});

prueba('16. Transporte conduciendo con parámetros editados y otros gastos del viaje', function (): void {
    // Factor de carretera 1,0 -> 1.111,9493 km; 8 l/100 km a 2 €/l = 177,91; otros: 60 + 120 + 80 = 260.
    // Duración a 100 km/h: 11,1 h.
    $r = Calculadora::calcular(entradaBase(['transporte' => [
        'factor_carretera' => 1.0, 'consumo_por_100km' => 8, 'precio_por_unidad' => 2.0, 'velocidad_kmh' => 100,
        'peajes' => 60, 'billete_vuelta' => 120, 'alojamiento' => 80,
    ]]), tablasPrueba());
    Afirmar::euros(177.91, partida($r, 'transporte')['importe']);
    Afirmar::euros(260, partida($r, 'otros_gastos_viaje')['importe']);
    Afirmar::euros(11.1, $r['derivados']['distancia']['horas']);
});

prueba('17. Costes fijos editables: el importe del usuario sustituye al de por defecto', function (): void {
    // Gestoría 200 -> 350; tasa de la DGT 100 -> 0. Costes fijos: 0 + 50 + 0 + 30 + 350 + 120 = 550.
    $r = Calculadora::calcular(entradaBase(['costes_fijos' => ['gestoria' => 350, 'tasa_dgt' => 0]]), tablasPrueba());
    Afirmar::euros(350, partida($r, 'gestoria')['importe']);
    Afirmar::euros(0, partida($r, 'tasa_dgt')['importe']);
    Afirmar::euros(16250.94, $r['total']);   // 16.200,94 + 150 (gestoría) - 100 (tasa DGT)
    Afirmar::contiene('indicado por ti', partida($r, 'gestoria')['explicacion']);
});

prueba('18. Destino con ubicación aproximada y código postal alemán desconocido', function (): void {
    $r = Calculadora::calcular(entradaBase(['municipio' => '28901']), tablasPrueba());
    Afirmar::verdadero(in_array('destino_aproximado', codigosAviso($r), true));

    $sin = Calculadora::calcular(entradaBase(['cp_vendedor' => '99999']), tablasPrueba());
    Afirmar::igual('no_calculable', partida($sin, 'transporte')['estado']);
    Afirmar::igual(null, $sin['derivados']['distancia']);
});

prueba('19. Coche matriculado antes de 2019: aviso por si el CO2 es del ciclo NEDC', function (): void {
    $r = Calculadora::calcular(entradaBase(['fecha_matriculacion' => '2017-05-01']), tablasPrueba());
    Afirmar::verdadero(in_array('iedmt_co2_nedc', codigosAviso($r), true));
    $nuevo = Calculadora::calcular(entradaBase(), tablasPrueba());
    Afirmar::falso(in_array('iedmt_co2_nedc', codigosAviso($nuevo), true));
});

prueba('20. El resultado es una función pura: mismas entradas, mismo resultado', function (): void {
    $a = Calculadora::calcular(entradaBase(), tablasPrueba());
    $b = Calculadora::calcular(entradaBase(), tablasPrueba());
    Afirmar::igual($a, $b);
    foreach ($a['partidas'] as $p) {
        Afirmar::igual(
            ['clave', 'concepto', 'importe', 'base', 'tipo', 'tipo_unidad', 'fuente', 'url', 'explicacion', 'verificar', 'estimacion', 'estado', 'motivo'],
            array_keys($p),
            'Claves de la partida ' . $p['clave']
        );
    }
});

prueba('21. IEDMT: la comunidad autónoma puede fijar un tipo propio en el tramo de 200 g/km o más', function (): void {
    // Valor venal 4.000 (diésel de 12 años). 210 g/km -> tramo de >= 200 g/km.
    // Cataluña (tipo de prueba 20 % frente al estatal 15 %): base = 4.000 / (1 + (20 + 20)/100) = 4.000 / 1,40 = 2.857,14
    //   cuota = 2.857,14 x 20 % = 571,43.
    // Madrid (sin tipo propio, 15 %): base = 4.000 / 1,35 = 2.962,96 -> cuota = 444,44.
    $comun = ['fecha_matriculacion' => '2014-01-20', 'co2_wltp' => 210, 'fila_boe' => ['precio' => 20000]];
    $ct = Calculadora::calcular(entradaBase($comun + ['municipio' => '08900']), tablasPrueba());
    Afirmar::euros(2857.14, partida($ct, 'iedmt')['base']);
    Afirmar::euros(571.43, partida($ct, 'iedmt')['importe']);
    Afirmar::igual(20.0, partida($ct, 'iedmt')['tipo']);
    Afirmar::contiene('el estatal es 15 %', partida($ct, 'iedmt')['explicacion']);
    $md = Calculadora::calcular(entradaBase($comun), tablasPrueba());
    Afirmar::euros(2962.96, partida($md, 'iedmt')['base']);
    Afirmar::euros(444.44, partida($md, 'iedmt')['importe']);
    Afirmar::igual(15.0, partida($md, 'iedmt')['tipo']);
});

prueba('22. ITP con reglas por combustible y por caballos fiscales (más de 15 CVF, eléctricos al 1 %)', function (): void {
    // Valor venal 13.000. País Vasco de prueba: general 4 %; más de 15 CVF 8 %; eléctrico 1 %.
    $gasolina = Calculadora::calcular(entradaBase(['municipio' => '20900']), tablasPrueba());          // CVF 13,31 -> 4 % = 520
    Afirmar::euros(520, partida($gasolina, 'itp_iva')['importe']);
    $grande = Calculadora::calcular(entradaBase(['municipio' => '20900', 'cc' => 2500, 'cilindros' => 6]), tablasPrueba());   // CVF 17,91 -> 8 % = 1.040
    Afirmar::euros(1040, partida($grande, 'itp_iva')['importe']);
    $electrico = Calculadora::calcular(entradaBase(['municipio' => '20900', 'combustible' => 'electrico', 'co2_wltp' => null,
        'cc' => null, 'cilindros' => null, 'kw' => 100]), tablasPrueba());                                // eléctrico -> 1 % = 130
    Afirmar::euros(130, partida($electrico, 'itp_iva')['importe']);
    Afirmar::igual(1.0, partida($electrico, 'itp_iva')['tipo']);
});

prueba('23. Estimaciones: los valores por defecto editables se marcan; las cifras oficiales y las del usuario, no', function (): void {
    $r = Calculadora::calcular(entradaBase(), tablasPrueba());
    Afirmar::verdadero(partida($r, 'itv_importacion')['estimacion']);
    Afirmar::verdadero(partida($r, 'transporte')['estimacion']);
    Afirmar::falso(partida($r, 'tasa_dgt')['estimacion'], 'la tasa de la DGT es una cifra oficial');
    Afirmar::falso(partida($r, 'precio')['estimacion']);
    Afirmar::falso(partida($r, 'iedmt')['estimacion']);
    Afirmar::verdadero($r['contiene_estimaciones']);
    $editado = Calculadora::calcular(entradaBase(['costes_fijos' => ['itv_importacion' => 45]]), tablasPrueba());
    Afirmar::falso(partida($editado, 'itv_importacion')['estimacion'], 'si el usuario lo cambia, ya no es una estimación nuestra');
});

prueba('24. IVTM con cuotas propias del ayuntamiento (como Madrid) y con coeficientes distintos por tramo (como Salamanca)', function (): void {
    // CVF 13,31 (1.998 cm³, 4 cilindros). Municipio con cuotas en euros: tramo 12-15,99 = 125 € al año; octubre = 1 trimestre -> 31,25.
    $cuotas = Calculadora::calcular(entradaBase(['municipio' => '46901']), tablasPrueba());
    $p = partida($cuotas, 'ivtm');
    Afirmar::euros(31.25, $p['importe']);
    Afirmar::euros(125, $p['base']);
    Afirmar::igual(null, $p['tipo']);
    Afirmar::falso(in_array('ivtm_tarifa_minima', codigosAviso($cuotas), true));
    // Coeficientes por tramo: 12-15,99 CVF -> tarifa 70 x 2,0 = 140 -> 35; menos de 12 CVF (1.500 cm³ -> 11,20) -> tarifa 30 x 1,8 = 54 -> 13,50.
    $a = Calculadora::calcular(entradaBase(['municipio' => '20901']), tablasPrueba());
    Afirmar::euros(35, partida($a, 'ivtm')['importe']);
    Afirmar::igual(2.0, partida($a, 'ivtm')['tipo']);
    $b = Calculadora::calcular(entradaBase(['municipio' => '20901', 'cc' => 1500]), tablasPrueba());
    Afirmar::euros(13.5, partida($b, 'ivtm')['importe']);
    Afirmar::euros(54, partida($b, 'ivtm')['base']);
    Afirmar::igual(1.8, partida($b, 'ivtm')['tipo']);
    // Prorrateo: en abril son 3 trimestres. 125 x 3/4 = 93,75
    $abril = Calculadora::calcular(entradaBase(['municipio' => '46901', 'fecha_calculo' => '2026-04-01']), tablasPrueba());
    Afirmar::euros(93.75, partida($abril, 'ivtm')['importe']);
});

prueba('25. Bonificación ambiental del IVTM: se descuenta si el ayuntamiento la concede de oficio; si hay que pedirla, solo se avisa', function (): void {
    $electrico = ['combustible' => 'electrico', 'co2_wltp' => null, 'cc' => null, 'cilindros' => null, 'kw' => 100];
    // Eléctrico, CVF 19,40: cuota de 175 € -> octubre: 43,75. Bonificación de oficio del 75 %: -32,81 -> 10,94.
    $r = Calculadora::calcular(entradaBase($electrico + ['municipio' => '46901']), tablasPrueba());
    Afirmar::euros(10.94, partida($r, 'ivtm')['importe']);
    Afirmar::euros(175, partida($r, 'ivtm')['base'], 'la base sigue siendo la cuota anual sin bonificar');
    Afirmar::verdadero(in_array('ivtm_bonificacion_aplicada', codigosAviso($r), true));
    Afirmar::contiene('-32,81', partida($r, 'ivtm')['explicacion']);
    // Híbrido (ECO) en el mismo municipio: también se descuenta. Gasolina: no.
    $hib = Calculadora::calcular(entradaBase(['combustible' => 'hibrido', 'municipio' => '46901']), tablasPrueba());
    Afirmar::euros(7.81, partida($hib, 'ivtm')['importe']);   // 31,25 - 23,44
    $gas = Calculadora::calcular(entradaBase(['municipio' => '46901']), tablasPrueba());
    Afirmar::euros(31.25, partida($gas, 'ivtm')['importe']);
    // PHEV: el distintivo (CERO o ECO) depende de la autonomía eléctrica y no se sabe: solo aviso
    $phev = Calculadora::calcular(entradaBase(['combustible' => 'phev', 'municipio' => '46901']), tablasPrueba());
    Afirmar::euros(31.25, partida($phev, 'ivtm')['importe']);
    Afirmar::verdadero(in_array('ivtm_bonificacion', codigosAviso($phev), true));
    // Ayuntamiento que la concede a petición: no se descuenta, pero se avisa con el ahorro posible
    $rogada = Calculadora::calcular(entradaBase(['combustible' => 'hibrido', 'municipio' => '20901']), tablasPrueba());
    Afirmar::euros(35, partida($rogada, 'ivtm')['importe']);
    $aviso = array_values(array_filter($rogada['avisos'], fn($a) => $a['codigo'] === 'ivtm_bonificacion'))[0];
    Afirmar::contiene('unos 26,25 € menos', $aviso['texto']);   // 35 x 75 %
    Afirmar::contiene('Hay que solicitarla.', $aviso['texto']);
    // Sin ordenanza verificada: aviso genérico
    $generico = Calculadora::calcular(entradaBase(['combustible' => 'electrico', 'co2_wltp' => null, 'cc' => null, 'cilindros' => null, 'kw' => 100, 'municipio' => '28900']), tablasPrueba());
    $texto = array_values(array_filter($generico['avisos'], fn($a) => $a['codigo'] === 'ivtm_bonificacion'))[0]['texto'];
    Afirmar::contiene('la ley permite hasta un 75 %', $texto);
});

prueba('26. ITP: comunidad con tipos especiales para vehículos sin incorporar -> no se calcula; bonificación de Madrid por valor bajo', function (): void {
    $r = Calculadora::calcular(entradaBase(['municipio' => '15900']), tablasPrueba());
    $p = partida($r, 'itp_iva');
    Afirmar::igual('no_calculable', $p['estado']);
    Afirmar::contiene('8 %', $p['motivo']);
    Afirmar::contiene('cuota fija en función de la cilindrada', $p['motivo']);
    Afirmar::falso($r['completo']);
    // Madrid de prueba: bonificación del 100 % si el valor venal es inferior a 500 €. Antigüedad 4 años -> 50 %.
    // Fila BOE 999 -> valor venal 499,50 -> cuota 0. Fila BOE 1.000 -> 500 -> no es inferior -> 4 % = 20.
    $bajo = Calculadora::calcular(entradaBase(['fila_boe' => ['precio' => 999]]), tablasPrueba());
    Afirmar::euros(0, partida($bajo, 'itp_iva')['importe']);
    $justo = Calculadora::calcular(entradaBase(['fila_boe' => ['precio' => 1000]]), tablasPrueba());
    Afirmar::euros(20, partida($justo, 'itp_iva')['importe']);
});
