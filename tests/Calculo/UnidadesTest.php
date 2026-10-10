<?php

declare(strict_types=1);

use CalculadoraImportacion\Calculo\CaballosFiscales;
use CalculadoraImportacion\Calculo\Dinero;
use CalculadoraImportacion\Calculo\Entrada;
use CalculadoraImportacion\Calculo\Fechas;
use CalculadoraImportacion\Calculo\Geo;
use CalculadoraImportacion\Calculo\Iedmt;
use CalculadoraImportacion\Calculo\ItpIva;
use CalculadoraImportacion\Calculo\ValorVenal;

/* Fronteras y fórmulas sueltas. Tablas de prueba: ver tests/ayuda.php. */

prueba('U1. IEDMT: fronteras de los tramos de CO2 (120, 160 y 200 g/km)', function (): void {
    $tramos = tablasPrueba()['iedmt']['regimenes']['peninsula_baleares']['tramos'];
    // <= 120 -> 0 %; > 120 -> 5 %; >= 160 -> 10 %; >= 200 -> 15 %
    Afirmar::igual(0.0, Iedmt::tipoPorCo2($tramos, 0.0));
    Afirmar::igual(0.0, Iedmt::tipoPorCo2($tramos, 120.0));
    Afirmar::igual(5.0, Iedmt::tipoPorCo2($tramos, 120.1));
    Afirmar::igual(5.0, Iedmt::tipoPorCo2($tramos, 159.9));
    Afirmar::igual(10.0, Iedmt::tipoPorCo2($tramos, 160.0));
    Afirmar::igual(10.0, Iedmt::tipoPorCo2($tramos, 199.9));
    Afirmar::igual(15.0, Iedmt::tipoPorCo2($tramos, 200.0));
    Afirmar::igual(15.0, Iedmt::tipoPorCo2($tramos, 350.0));
});

prueba('U2. Depreciación (anexo IV): «hasta N años» incluye el día del aniversario', function (): void {
    $caso = function (string $matriculacion): array {
        $n = Entrada::normalizar(entradaBase(['fecha_matriculacion' => $matriculacion]), tablasPrueba());
        return ValorVenal::calcular($n, tablasPrueba());
    };
    // Fecha de cálculo: 10/10/2026. Tramos de prueba: hasta 1 año 100 %, hasta 2 80 %, hasta 3 60 %,
    // hasta 5 50 %, hasta 10 40 %, más de 10 20 %.
    Afirmar::igual(1.00, $caso('2025-10-11')['coeficiente']);   // 11 meses y 29 días
    Afirmar::igual(1.00, $caso('2025-10-10')['coeficiente']);   // exactamente 1 año: «hasta 1 año» todavía es 100 %
    Afirmar::igual(0.80, $caso('2025-10-09')['coeficiente']);   // 1 año y 1 día: «más de 1 año, hasta 2»
    Afirmar::igual(0.80, $caso('2024-10-10')['coeficiente']);   // exactamente 2 años
    Afirmar::igual(0.60, $caso('2024-10-09')['coeficiente']);
    Afirmar::igual(0.60, $caso('2023-10-10')['coeficiente']);   // exactamente 3 años
    Afirmar::igual(0.50, $caso('2023-10-09')['coeficiente']);   // 3 años y 1 día: ya «hasta 5»
    Afirmar::igual(0.50, $caso('2021-10-10')['coeficiente']);   // exactamente 5 años
    Afirmar::igual(0.40, $caso('2021-10-09')['coeficiente']);
    Afirmar::igual(0.40, $caso('2016-10-10')['coeficiente']);   // exactamente 10 años
    Afirmar::igual(0.20, $caso('2016-10-09')['coeficiente']);   // más de 10 años
    Afirmar::igual(0.20, $caso('1990-01-01')['coeficiente']);   // muy antiguo: el último tramo
    Afirmar::igual(36, $caso('1990-01-01')['antiguedad_anios']);
    // Año bisiesto: 29/02/2020 + 1 año = 28/02/2021
    $bis = fn(string $calculo) => ValorVenal::coeficiente(tablasPrueba()['depreciacion']['tramos'],
        Fechas::parsear('2020-02-29'), Fechas::parsear($calculo));
    Afirmar::igual(1.00, $bis('2021-02-28'));
    Afirmar::igual(0.80, $bis('2021-03-01'));
});

prueba('U3. Caballos fiscales: fórmula de combustión, eléctricos y CVF del BOE', function (): void {
    $cvf = function (array $cambios) {
        $n = Entrada::normalizar(entradaBase($cambios), tablasPrueba());
        return CaballosFiscales::calcular($n, tablasPrueba());
    };
    // 0,08 x (cc/cilindros)^0,6 x cilindros, con dos decimales «aproximada por defecto» (se trunca, no se redondea)
    Afirmar::euros(13.31, $cvf(['cc' => 1998, 'cilindros' => 4])['cvf']);   // 13,31286
    Afirmar::euros(12.49, $cvf(['cc' => 1798, 'cilindros' => 4])['cvf']);   // 12,49648: redondeando saldría 12,50
    Afirmar::euros(7.83, $cvf(['cc' => 1000, 'cilindros' => 3])['cvf']);
    Afirmar::euros(17.91, $cvf(['cc' => 2500, 'cilindros' => 6])['cvf']);
    // Eléctricos: kW / 5,152
    Afirmar::euros(19.40, $cvf(['combustible' => 'electrico', 'kw' => 100, 'cc' => null, 'cilindros' => null])['cvf']);   // 19,40994
    Afirmar::euros(11.64, $cvf(['combustible' => 'electrico', 'kw' => 60, 'cc' => null, 'cilindros' => null])['cvf']);    // 11,64596
    // El CVF de la fila del BOE manda sobre la fórmula
    $boe = $cvf(['fila_boe' => ['precio' => 20000, 'cvf' => 14.5]]);
    Afirmar::euros(14.5, $boe['cvf']);
    Afirmar::igual('boe', $boe['origen']);
    // Los cilindros pueden venir de la fila del BOE
    Afirmar::euros(13.31, $cvf(['cilindros' => null, 'fila_boe' => ['precio' => 20000, 'cilindros' => 4]])['cvf']);
    // Sin cilindros ni CVF no se inventa
    $falta = $cvf(['cilindros' => null]);
    Afirmar::igual(null, $falta['cvf']);
    Afirmar::contiene('cilindros', $falta['motivo']);
});

prueba('U4. Reglas de ITP por CVF y antigüedad: límites inclusivos/exclusivos', function (): void {
    Afirmar::verdadero(ItpIva::reglaCumple(['cvf_min' => 15], 15.0, 0));
    Afirmar::falso(ItpIva::reglaCumple(['cvf_min' => 15], 14.99, 0));
    Afirmar::falso(ItpIva::reglaCumple(['cvf_max' => 12], 12.0, 0));
    Afirmar::verdadero(ItpIva::reglaCumple(['cvf_max' => 12], 11.99, 0));
    Afirmar::verdadero(ItpIva::reglaCumple(['antiguedad_min' => 10], null, 10));
    Afirmar::falso(ItpIva::reglaCumple(['antiguedad_min' => 10], null, 9));
    Afirmar::falso(ItpIva::reglaCumple(['antiguedad_max' => 3], null, 3));
    Afirmar::igual(null, ItpIva::reglaCumple(['cvf_min' => 15], null, 0), 'sin CVF no se puede decidir');
    Afirmar::verdadero(ItpIva::reglaCumple([], null, 0), 'regla sin condiciones');
    // «Superen los 15 caballos»: estrictamente más de 15
    Afirmar::falso(ItpIva::reglaCumple(['cvf_mayor_que' => 15], 15.0, 0));
    Afirmar::verdadero(ItpIva::reglaCumple(['cvf_mayor_que' => 15], 15.01, 0));
    Afirmar::verdadero(ItpIva::reglaCumple(['cvf_menor_igual' => 15], 15.0, 0));
    Afirmar::falso(ItpIva::reglaCumple(['cvf_menor_igual' => 15], 15.01, 0));
    // Valor venal (bonificación de Madrid para bienes de menos de 500 €)
    Afirmar::verdadero(ItpIva::reglaCumple(['valor_menor_que' => 500], null, 0, null, 499.99));
    Afirmar::falso(ItpIva::reglaCumple(['valor_menor_que' => 500], null, 0, null, 500.0));
    Afirmar::igual(null, ItpIva::reglaCumple(['valor_menor_que' => 500], null, 0, null, null));
    // Combustible
    Afirmar::verdadero(ItpIva::reglaCumple(['combustible_en' => ['electrico']], null, 0, 'electrico'));
    Afirmar::falso(ItpIva::reglaCumple(['combustible_en' => ['electrico']], null, 0, 'diesel'));
    Afirmar::igual(null, ItpIva::reglaCumple(['combustible_en' => ['electrico']], null, 0, null));
});

prueba('U5. Medio de transporte nuevo: 6 meses O 6.000 km, con sus fronteras', function (): void {
    $nuevo = function (string $matriculacion, int $km, string $calculo = '2026-10-10'): array {
        $n = Entrada::normalizar(entradaBase(['fecha_matriculacion' => $matriculacion, 'km' => $km, 'fecha_calculo' => $calculo]), tablasPrueba());
        return ItpIva::clasificar($n, tablasPrueba());
    };
    // «Antes de los seis meses siguientes» (art. 13 LIVA): 10/04/2026 + 6 meses = 10/10/2026 ya NO cuenta
    Afirmar::verdadero($nuevo('2026-04-11', 20000)['nuevo']);
    Afirmar::falso($nuevo('2026-04-10', 20000)['nuevo'], 'el día del sexto mes ya no es nuevo por antigüedad');
    Afirmar::falso($nuevo('2026-04-09', 20000)['nuevo']);
    Afirmar::verdadero($nuevo('2024-01-01', 6000)['nuevo'], '6.000 km exactos: «no hayan recorrido más de 6.000»');
    Afirmar::falso($nuevo('2024-01-01', 6001)['nuevo']);
    Afirmar::verdadero($nuevo('2024-01-01', 0)['nuevo'], 'cero km aunque sea antiguo');
    Afirmar::igual('2026-12-30', $nuevo('2026-06-30', 90000, '2026-10-10')['fecha_limite_meses']);   // 30/06 + 6 meses
});

prueba('U6. Fechas: sumar meses sin desbordar, trimestres y años completos', function (): void {
    $f = fn(string $s) => Fechas::parsear($s);
    Afirmar::igual('2026-02-28', Fechas::sumarMeses($f('2025-08-31'), 6)->format('Y-m-d'));
    Afirmar::igual('2024-02-29', Fechas::sumarMeses($f('2023-08-31'), 6)->format('Y-m-d'), 'año bisiesto');
    Afirmar::igual('2027-01-15', Fechas::sumarMeses($f('2026-07-15'), 6)->format('Y-m-d'));
    Afirmar::igual(4, Fechas::trimestresRestantes($f('2026-01-01')));
    Afirmar::igual(4, Fechas::trimestresRestantes($f('2026-03-31')));
    Afirmar::igual(3, Fechas::trimestresRestantes($f('2026-04-01')));
    Afirmar::igual(2, Fechas::trimestresRestantes($f('2026-07-15')));
    Afirmar::igual(1, Fechas::trimestresRestantes($f('2026-12-31')));
    Afirmar::igual(null, Fechas::parsear('2026-02-30'));
    Afirmar::igual(null, Fechas::parsear('10/10/2026'));
    Afirmar::igual(0, Fechas::aniosCompletos($f('2020-02-29'), $f('2021-02-28')));
    Afirmar::igual(1, Fechas::aniosCompletos($f('2020-02-29'), $f('2021-03-01')));
});

prueba('U7. Haversine y búsqueda de código postal por prefijo', function (): void {
    // 10° de latitud sobre el mismo meridiano: 6.371 x 10 x pi/180 = 1.111,95 km
    Afirmar::euros(1111.95, Geo::haversine(50.0, -3.0, 40.0, -3.0));
    Afirmar::euros(0.0, Geo::haversine(40.0, -3.0, 40.0, -3.0));
    // Dos puntos del ecuador separados 90° de longitud: un cuarto de la circunferencia = 10.007,54 km
    Afirmar::euros(10007.54, Geo::haversine(0.0, 0.0, 0.0, 90.0));
    $plz = tablasPrueba()['plz'];
    Afirmar::igual([48.0, -3.0, '803'], Geo::coordenadasPlz('80312', $plz), 'gana el prefijo de 3 cifras');
    Afirmar::igual([50.0, -3.0, '80'], Geo::coordenadasPlz('80999', $plz), 'cae al de 2 cifras');
    Afirmar::igual(null, Geo::coordenadasPlz('99999', $plz));
    Afirmar::igual(null, Geo::coordenadasPlz('8031', $plz), 'debe tener 5 cifras');
    Afirmar::igual(null, Geo::coordenadasPlz('80A12', $plz));
});

prueba('U8. Redondeo a céntimos: mitad hacia arriba, sin errores de representación', function (): void {
    Afirmar::igual(17.99, Dinero::redondear(17.985));
    Afirmar::igual(1.01, Dinero::redondear(1.005));
    Afirmar::igual(2.68, Dinero::redondear(2.675));
    Afirmar::igual(0.0, Dinero::redondear(0.004));
});

prueba('U9. Potencia: se calcula el valor que falta (kW <-> CV)', function (): void {
    $solo = Entrada::normalizar(entradaBase(['kw' => null, 'cv' => 150]), tablasPrueba());
    Afirmar::euros(110.3, $solo['kw']);     // 150 x 0,7355
    $otro = Entrada::normalizar(entradaBase(['kw' => 110, 'cv' => null]), tablasPrueba());
    Afirmar::euros(150.0, $otro['cv']);     // 110 / 0,7355
});
