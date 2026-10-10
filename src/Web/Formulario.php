<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Web;

use CalculadoraImportacion\Anuncio\Enlace;
use CalculadoraImportacion\Calculo\Entrada;

/**
 * Traduce lo que el usuario envía en el formulario a la entrada del motor, con un mensaje de error por campo.
 * Nunca se fía de lo recibido: longitudes recortadas, números leídos con Numero, listas cerradas y rangos.
 */
final class Formulario
{
    public const COSTES = ['tasa_dgt', 'itv_importacion', 'coc_ficha_tecnica', 'placas', 'gestoria', 'matricula_exportacion'];
    public const CAMPOS = [
        'enlace', 'municipio', 'municipio_texto', 'marca', 'modelo', 'version', 'matriculacion', 'km', 'cc', 'cilindros',
        'kw', 'cv', 'combustible', 'co2', 'precio', 'vendedor', 'iva_deducible', 'cp_vendedor', 'boe_precio',
    ];

    /**
     * @return array{valores: array<string, string>, entrada: ?array, errores: array<string, string>, candidatos: array}
     */
    public static function leerDatos(array $post, array $tablas, string $hoy): array
    {
        $v = [];
        foreach (self::CAMPOS as $campo) {
            $v[$campo] = Peticion::texto($post, $campo, in_array($campo, ['enlace'], true) ? 2100 : 80);
        }
        $v['iva_deducible'] = ($post['iva_deducible'] ?? '') === '1' ? '1' : '';
        $err = [];
        $entrada = [];
        $candidatos = [];

        // Enlace del anuncio (opcional): solo mobile.de
        if ($v['enlace'] !== '') {
            $r = Enlace::validar($v['enlace']);
            if ($r['ok']) {
                $entrada['enlace'] = $r['canonica'];
            } else {
                $err['enlace'] = $r['error'];
            }
        }

        // Municipio de destino
        $m = Municipios::resolver($tablas, $v['municipio'], $v['municipio_texto']);
        if ($m['ine'] !== null) {
            $entrada['municipio'] = $m['ine'];
            $v['municipio'] = $m['ine'];
            $v['municipio_texto'] = Municipios::etiqueta($tablas, $m['ine']);
        } else {
            $err['municipio'] = (string) $m['error'];
            $candidatos = $m['candidatos'];
            $v['municipio'] = '';
        }

        $entrada['marca'] = $v['marca'];
        $entrada['modelo'] = $v['modelo'];
        $entrada['version'] = $v['version'];
        $entrada['fecha_calculo'] = $hoy;

        // Primera matriculación: MM/AAAA, AAAA-MM o AAAA-MM-DD
        $fecha = self::fechaMatriculacion($v['matriculacion']);
        if ($fecha === null) {
            $err['matriculacion'] = 'Indica el mes y el año de la primera matriculación, por ejemplo 03/2019.';
        } elseif ($fecha > $hoy) {
            $err['matriculacion'] = 'La primera matriculación no puede ser posterior a hoy.';
        } elseif ($fecha < '1950-01-01') {
            $err['matriculacion'] = 'Esa fecha es demasiado antigua: comprueba el año.';
        } else {
            $entrada['fecha_matriculacion'] = $fecha;
        }

        $combustible = $v['combustible'];
        if (!in_array($combustible, Entrada::COMBUSTIBLES, true)) {
            $err['combustible'] = 'Elige el combustible.';
        } else {
            $entrada['combustible'] = $combustible;
        }
        $electrico = $combustible === 'electrico';

        self::entero($v, 'km', 0, 2_000_000, 'Indica los kilómetros que marca el anuncio (un número entre 0 y 2.000.000).', true, $entrada, $err);
        self::entero($v, 'cc', 1, 20000, 'La cilindrada debe estar en cm³ (por ejemplo 1.998).', !$electrico, $entrada, $err, 'Indica la cilindrada del motor en cm³ (por ejemplo 1.998).');
        self::entero($v, 'cilindros', 1, 16, 'El número de cilindros debe estar entre 1 y 16.', !$electrico, $entrada, $err, 'Indica el número de cilindros (la mayoría de coches tienen 4). Con él calculamos los caballos fiscales.');
        self::decimal($v, 'kw', 1, 4000, 'La potencia en kW debe estar entre 1 y 4.000.', $electrico, $entrada, $err, 'En un eléctrico, indica la potencia en kW: con ella calculamos los caballos fiscales.');
        self::decimal($v, 'cv', 1, 5000, 'La potencia en CV debe estar entre 1 y 5.000.', false, $entrada, $err);
        if ($electrico) {
            // Un eléctrico no tiene cilindrada ni cilindros ni emisiones
            unset($entrada['cc'], $entrada['cilindros']);
        }
        if (!$electrico) {
            self::decimal($v, 'co2', 0, 1000, 'Las emisiones de CO₂ deben estar en g/km, entre 0 y 1.000.', false, $entrada, $err);
            if (isset($entrada['co2'])) {
                $entrada['co2_wltp'] = $entrada['co2'];
            }
        }
        unset($entrada['co2']);

        self::decimal($v, 'precio', 1, 10_000_000, 'Indica el precio del anuncio en euros.', true, $entrada, $err, 'Indica el precio del anuncio en euros.');

        if (!in_array($v['vendedor'], Entrada::VENDEDORES, true)) {
            $err['vendedor'] = 'Indica si el vendedor es particular o profesional.';
        } else {
            $entrada['vendedor'] = $v['vendedor'];
        }
        $entrada['iva_deducible'] = $v['iva_deducible'] === '1' && ($v['vendedor'] === 'profesional');

        $cp = preg_replace('/\s+/', '', $v['cp_vendedor']) ?? '';
        if (!preg_match('/^\d{5}$/', $cp)) {
            $err['cp_vendedor'] = 'Indica el código postal alemán del vendedor (5 cifras). Aparece en el anuncio, junto a la ubicación.';
        } else {
            $entrada['cp_vendedor'] = $cp;
            $v['cp_vendedor'] = $cp;
        }

        if ($v['boe_precio'] !== '') {
            $boe = Numero::desdeTexto($v['boe_precio']);
            if ($boe === null || $boe < 100 || $boe > 5_000_000) {
                $err['boe_precio'] = 'El precio medio del BOE debe ser un importe en euros (por ejemplo 38.000).';
            } else {
                $entrada['fila_boe'] = ['precio' => $boe];
            }
        }

        // Control final del motor, para no depender solo de estas comprobaciones
        if (!$err) {
            try {
                Entrada::normalizar($entrada, $tablas);
            } catch (\CalculadoraImportacion\Calculo\EntradaInvalida $e) {
                $err['general'] = implode(' ', $e->errores);
            }
        }

        return ['valores' => $v, 'entrada' => $err ? null : $entrada, 'errores' => $err, 'candidatos' => $candidatos];
    }

    /**
     * Ajustes de costes fijos y transporte de la página de resultado. Un campo vacío mantiene el valor por defecto.
     * @return array{entrada: ?array, errores: array<string, string>, valores: array<string, string>}
     */
    public static function leerAjustes(array $post, array $entradaBase): array
    {
        $v = [];
        $err = [];
        $costes = [];
        foreach (self::COSTES as $clave) {
            $campo = 'cf_' . $clave;
            $v[$campo] = Peticion::texto($post, $campo, 20);
            if ($v[$campo] === '') {
                continue;
            }
            $n = Numero::desdeTexto($v[$campo]);
            if ($n === null || $n < 0 || $n > 100000) {
                $err[$campo] = 'Debe ser un importe en euros entre 0 y 100.000.';
            } else {
                $costes[$clave] = $n;
            }
        }
        $t = [];
        $modo = Peticion::texto($post, 't_modo', 12);
        $v['t_modo'] = $modo;
        if ($modo !== '') {
            if (!in_array($modo, Entrada::MODOS_TRANSPORTE, true)) {
                $err['t_modo'] = 'Elige cómo vas a traer el coche.';
            } else {
                $t['modo'] = $modo;
            }
        }
        $limites = [
            't_peajes' => ['peajes', 0, 100000], 't_billete_vuelta' => ['billete_vuelta', 0, 100000], 't_alojamiento' => ['alojamiento', 0, 100000],
            't_consumo' => ['consumo_por_100km', 0.1, 100], 't_precio_unidad' => ['precio_por_unidad', 0, 20], 't_precio_km' => ['precio_por_km', 0, 20],
            't_factor' => ['factor_carretera', 1, 3], 't_velocidad' => ['velocidad_kmh', 20, 130],
        ];
        foreach ($limites as $campo => [$clave, $min, $max]) {
            $v[$campo] = Peticion::texto($post, $campo, 20);
            if ($v[$campo] === '') {
                continue;
            }
            $n = Numero::desdeTexto($v[$campo]);
            if ($n === null || $n < $min || $n > $max) {
                $err[$campo] = 'Debe ser un número entre ' . Vista::num((float) $min, 1) . ' y ' . Vista::num((float) $max, 0) . '.';
            } else {
                $t[$clave] = $n;
            }
        }
        if ($err) {
            return ['entrada' => null, 'errores' => $err, 'valores' => $v];
        }
        $entrada = $entradaBase;
        $entrada['costes_fijos'] = $costes;
        $entrada['transporte'] = $t;
        return ['entrada' => $entrada, 'errores' => [], 'valores' => $v];
    }

    /** Valores del formulario de datos a partir de una entrada guardada (para editarla). @return array<string, string> */
    public static function valoresDesdeEntrada(array $entrada, array $tablas): array
    {
        $num = static fn($x): string => $x === null || $x === '' ? '' : rtrim(rtrim(number_format((float) $x, 2, ',', ''), '0'), ',');
        $fecha = (string) ($entrada['fecha_matriculacion'] ?? '');
        $mmaaaa = preg_match('/^(\d{4})-(\d{2})/', $fecha, $m) ? $m[2] . '/' . $m[1] : '';
        $ine = (string) ($entrada['municipio'] ?? '');
        return [
            'enlace' => (string) ($entrada['enlace'] ?? ''),
            'municipio' => $ine,
            'municipio_texto' => $ine !== '' ? Municipios::etiqueta($tablas, $ine) : '',
            'marca' => (string) ($entrada['marca'] ?? ''),
            'modelo' => (string) ($entrada['modelo'] ?? ''),
            'version' => (string) ($entrada['version'] ?? ''),
            'matriculacion' => $mmaaaa,
            'km' => $num($entrada['km'] ?? null),
            'cc' => $num($entrada['cc'] ?? null),
            'cilindros' => $num($entrada['cilindros'] ?? null),
            'kw' => $num($entrada['kw'] ?? null),
            'cv' => $num($entrada['cv'] ?? null),
            'combustible' => (string) ($entrada['combustible'] ?? ''),
            'co2' => $num($entrada['co2_wltp'] ?? null),
            'precio' => $num($entrada['precio'] ?? null),
            'vendedor' => (string) ($entrada['vendedor'] ?? ''),
            'iva_deducible' => !empty($entrada['iva_deducible']) ? '1' : '',
            'cp_vendedor' => (string) ($entrada['cp_vendedor'] ?? ''),
            'boe_precio' => $num($entrada['fila_boe']['precio'] ?? null),
        ];
    }

    private static function fechaMatriculacion(string $texto): ?string
    {
        $t = trim($texto);
        if (preg_match('#^(\d{1,2})\s*[/.-]\s*(\d{4})$#', $t, $m)) {
            [$mes, $anio] = [(int) $m[1], (int) $m[2]];
        } elseif (preg_match('#^(\d{4})\s*[-/]\s*(\d{1,2})(?:\s*[-/]\s*\d{1,2})?$#', $t, $m)) {
            [$anio, $mes] = [(int) $m[1], (int) $m[2]];
        } else {
            return null;
        }
        if ($mes < 1 || $mes > 12 || $anio < 1900 || $anio > 2100) {
            return null;
        }
        return sprintf('%04d-%02d-01', $anio, $mes);
    }

    private static function entero(array &$v, string $campo, int $min, int $max, string $msgRango, bool $obligatorio, array &$entrada, array &$err, ?string $msgFalta = null): void
    {
        if ($v[$campo] === '') {
            if ($obligatorio) {
                $err[$campo] = $msgFalta ?? $msgRango;
            }
            return;
        }
        $n = Numero::desdeTexto($v[$campo]);
        if ($n === null || $n < $min || $n > $max || floor($n) !== $n) {
            $err[$campo] = $msgRango;
            return;
        }
        $entrada[$campo] = (int) $n;
    }

    private static function decimal(array &$v, string $campo, float $min, float $max, string $msgRango, bool $obligatorio, array &$entrada, array &$err, ?string $msgFalta = null): void
    {
        if ($v[$campo] === '') {
            if ($obligatorio) {
                $err[$campo] = $msgFalta ?? $msgRango;
            }
            return;
        }
        $n = Numero::desdeTexto($v[$campo]);
        if ($n === null || $n < $min || $n > $max) {
            $err[$campo] = $msgRango;
            return;
        }
        $entrada[$campo] = $n;
    }
}
