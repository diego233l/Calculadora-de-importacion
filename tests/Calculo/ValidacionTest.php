<?php

declare(strict_types=1);

use CalculadoraImportacion\Calculo\Calculadora;
use CalculadoraImportacion\Calculo\EntradaInvalida;

prueba('V1. Una entrada vacía devuelve todos los errores a la vez, en español', function (): void {
    $e = Afirmar::lanza(EntradaInvalida::class, fn() => Calculadora::calcular([], tablasPrueba()));
    $texto = implode(' | ', $e->errores);
    foreach (['primera matriculación', 'fecha de cálculo', 'kilómetros', 'combustible', 'precio', 'vendedor', 'municipio'] as $campo) {
        Afirmar::contiene($campo, $texto);
    }
});

prueba('V2. Valores fuera de rango o con formato incorrecto se rechazan', function (): void {
    $malos = [
        'fecha_matriculacion' => ['10/10/2020', '2020-13-01', '', 20200101],
        'fecha_calculo' => ['hoy'],
        'km' => [-1, 'mucho', 2500000, 100.5],
        'combustible' => ['nafta', 'DIESEL', null],
        'precio' => [0, -5, 'gratis', 99999999],
        'vendedor' => ['empresa', 'Particular'],
        'municipio' => ['99999', '28', 28900],
        'co2_wltp' => [-1, 5000, 'x'],
        'cc' => [0, 'abc'],
    ];
    foreach ($malos as $campo => $valores) {
        foreach ($valores as $v) {
            Afirmar::lanza(EntradaInvalida::class, fn() => Calculadora::calcular(entradaBase([$campo => $v]), tablasPrueba()),
                "$campo = " . var_export($v, true));
        }
    }
});

prueba('V3. La fecha de matriculación no puede ser posterior a la del cálculo', function (): void {
    $e = Afirmar::lanza(EntradaInvalida::class, fn() => Calculadora::calcular(entradaBase(['fecha_matriculacion' => '2026-10-11']), tablasPrueba()));
    Afirmar::contiene('posterior', $e->getMessage());
    // El mismo día sí vale
    $r = Calculadora::calcular(entradaBase(['fecha_matriculacion' => '2026-10-10']), tablasPrueba());
    Afirmar::verdadero($r['derivados']['nuevo']);
});

prueba('V4. Una comunidad no soportada (Ceuta) se rechaza por su nombre y la CCAA indicada debe coincidir con el municipio', function (): void {
    $e = Afirmar::lanza(EntradaInvalida::class, fn() => Calculadora::calcular(entradaBase(['municipio' => '51900']), tablasPrueba()));
    Afirmar::contiene('Ceuta no está soportada', $e->getMessage());
    $e = Afirmar::lanza(EntradaInvalida::class, fn() => Calculadora::calcular(entradaBase(['ccaa' => 'CT']), tablasPrueba()));
    Afirmar::contiene('no coincide', $e->getMessage());
    $r = Calculadora::calcular(entradaBase(['ccaa' => 'MD']), tablasPrueba());
    Afirmar::igual('MD', $r['derivados']['ccaa']);
});

prueba('V5. Costes fijos y parámetros de transporte desconocidos o fuera de rango se rechazan', function (): void {
    Afirmar::lanza(EntradaInvalida::class, fn() => Calculadora::calcular(entradaBase(['costes_fijos' => ['inventado' => 5]]), tablasPrueba()));
    Afirmar::lanza(EntradaInvalida::class, fn() => Calculadora::calcular(entradaBase(['costes_fijos' => ['gestoria' => -1]]), tablasPrueba()));
    Afirmar::lanza(EntradaInvalida::class, fn() => Calculadora::calcular(entradaBase(['costes_fijos' => ['gestoria' => 1e9]]), tablasPrueba()));
    Afirmar::lanza(EntradaInvalida::class, fn() => Calculadora::calcular(entradaBase(['transporte' => ['modo' => 'avion']]), tablasPrueba()));
    Afirmar::lanza(EntradaInvalida::class, fn() => Calculadora::calcular(entradaBase(['transporte' => ['velocidad_kmh' => 0]]), tablasPrueba()));
    Afirmar::lanza(EntradaInvalida::class, fn() => Calculadora::calcular(entradaBase(['transporte' => ['factor_carretera' => 0.5]]), tablasPrueba()));
    Afirmar::lanza(EntradaInvalida::class, fn() => Calculadora::calcular(entradaBase(['transporte' => 'camion']), tablasPrueba()));
});

prueba('V6. La fila del BOE debe traer un precio positivo', function (): void {
    Afirmar::lanza(EntradaInvalida::class, fn() => Calculadora::calcular(entradaBase(['fila_boe' => ['precio' => 0]]), tablasPrueba()));
    Afirmar::lanza(EntradaInvalida::class, fn() => Calculadora::calcular(entradaBase(['fila_boe' => ['cvf' => 12]]), tablasPrueba()));
    Afirmar::lanza(EntradaInvalida::class, fn() => Calculadora::calcular(entradaBase(['fila_boe' => 'caro']), tablasPrueba()));
});

prueba('V7. Los números en texto se aceptan; el texto no numérico no', function (): void {
    $r = Calculadora::calcular(entradaBase(['km' => '60000', 'precio' => '14000', 'co2_wltp' => '170', 'cc' => '1998']), tablasPrueba());
    Afirmar::euros(16200.94, $r['total']);
    Afirmar::lanza(EntradaInvalida::class, fn() => Calculadora::calcular(entradaBase(['precio' => '14.000,00']), tablasPrueba()));
});
