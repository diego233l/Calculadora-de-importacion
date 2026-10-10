<?php

declare(strict_types=1);

use CalculadoraImportacion\Datos\Tablas;

/**
 * Tablas de PRUEBA (tests/fixtures/calculo): números redondos para poder comprobar los cálculos a mano.
 * Resumen de lo que valen:
 *   depreciación (años completos)  0:100 %  1:80 %  2:60 %  3-4:50 %  5-9:40 %  10+:20 %
 *   IEDMT península: <=120 g/km 0 %, >120 5 %, >=160 10 %, >=200 15 %   · Canarias: 0 / 4 / 8 / 12 %
 *   IVA España 20 %, IGIC 5 %, IVA alemán 10 %
 *   IVTM: <8 CVF 10 €, 8-12 30 €, 12-16 70 €, 16-20 90 €, >=20 110 €; municipio 28900 coef 2,0
 *   costes fijos: 100 + 50 + 0 + 30 + 200 + 120 = 500 €
 *   transporte: gasolina 7 l/100 km a 1,50 €, diésel 6 a 1,40, híbrido 5 a 1,50, eléctrico 20 kWh a 0,30; camión 0,50 €/km
 *   código postal alemán 80xxx = (50, -3); 803xx = (48, -3); destino 28900 = (40, -3)
 */
function tablasPrueba(): array
{
    static $t = null;
    if ($t === null) {
        $dir = __DIR__ . '/fixtures/calculo';
        $t = Tablas::cargarDesde($dir . '/fiscal', $dir . '/plz_de.json', $dir . '/municipios_es.json');
    }
    return $t;
}

/** Usado de particular, gasolina, en Madrid (ver EscenariosTest). Se sustituyen las claves de $cambios. */
function entradaBase(array $cambios = []): array
{
    return array_merge([
        'marca' => 'Marca', 'modelo' => 'Modelo', 'version' => 'Versión',
        'fecha_matriculacion' => '2022-06-15',
        'fecha_calculo' => '2026-10-10',
        'km' => 60000,
        'cc' => 1998,
        'cilindros' => 4,
        'kw' => 110,
        'combustible' => 'gasolina',
        'co2_wltp' => 170,
        'precio' => 14000,
        'vendedor' => 'particular',
        'iva_deducible' => false,
        'cp_vendedor' => '80123',
        'municipio' => '28900',
        'fila_boe' => ['precio' => 26000],
    ], $cambios);
}

/** Devuelve la partida con esa clave o falla. */
function partida(array $resultado, string $clave): array
{
    foreach (array_merge($resultado['partidas'], $resultado['intermedios']) as $p) {
        if ($p['clave'] === $clave) {
            return $p;
        }
    }
    throw new \AssertionError("No hay partida «$clave»");
}

function codigosAviso(array $resultado): array
{
    return array_column($resultado['avisos'], 'codigo');
}
