<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Calculo;

/**
 * Valida y normaliza la entrada del cálculo. Función pura: no lee el reloj ni el disco.
 *
 * Claves de entrada (todas en un array asociativo):
 *   marca, modelo, version            texto informativo (opcional)
 *   fecha_matriculacion               AAAA-MM-DD (obligatoria)
 *   fecha_calculo                     AAAA-MM-DD, fecha de devengo (obligatoria: el motor no lee el reloj)
 *   km                                entero >= 0 (obligatorio)
 *   cc, cilindros                     cilindrada en cm³ y nº de cilindros (opcionales si fila_boe trae el CVF)
 *   cv, kw                            potencia; si falta uno se calcula con el otro
 *   combustible                       gasolina|diesel|electrico|hibrido|phev|glp|gnc
 *   co2_wltp                          g/km (opcional en eléctricos)
 *   precio                            precio bruto del anuncio en euros
 *   vendedor                          particular|profesional
 *   iva_deducible                     true si el anuncio indica "MwSt. ausweisbar"
 *   cp_vendedor                       código postal alemán del vendedor (opcional; sin él no hay transporte)
 *   municipio                         código INE (5 cifras) del municipio de destino
 *   ccaa                              opcional; si viene debe coincidir con la del municipio
 *   fila_boe                          {precio, cvf?, cilindros?}: fila de la Orden elegida (la aporta la Fase 2)
 *   costes_fijos                      {clave: importe} para sustituir los valores por defecto
 *   transporte                        {modo, factor_carretera, velocidad_kmh, consumo_por_100km,
 *                                      precio_por_unidad, precio_por_km, peajes, billete_vuelta, alojamiento}
 */
final class Entrada
{
    public const COMBUSTIBLES = ['gasolina', 'diesel', 'electrico', 'hibrido', 'phev', 'glp', 'gnc'];
    public const VENDEDORES = ['particular', 'profesional'];
    public const MODOS_TRANSPORTE = ['conduciendo', 'camion'];
    private const KW_POR_CV = 0.7355;

    /** @throws EntradaInvalida */
    public static function normalizar(array $e, array $tablas): array
    {
        $err = [];

        $fechaMat = self::fecha($e, 'fecha_matriculacion', 'La fecha de primera matriculación', $err);
        $fechaCalc = self::fecha($e, 'fecha_calculo', 'La fecha de cálculo', $err);
        if ($fechaMat && $fechaCalc && $fechaMat > $fechaCalc) {
            $err[] = 'La fecha de primera matriculación no puede ser posterior a la fecha de cálculo.';
        }

        $km = self::numero($e['km'] ?? null);
        if ($km === null || $km < 0 || $km > 2000000 || floor($km) !== $km) {
            $err[] = 'Los kilómetros deben ser un número entero entre 0 y 2.000.000.';
        }

        $combustible = $e['combustible'] ?? null;
        if (!is_string($combustible) || !in_array($combustible, self::COMBUSTIBLES, true)) {
            $err[] = 'El combustible debe ser uno de: ' . implode(', ', self::COMBUSTIBLES) . '.';
        }

        $precio = self::numero($e['precio'] ?? null);
        if ($precio === null || $precio <= 0 || $precio > 10000000) {
            $err[] = 'El precio debe ser un importe mayor que 0.';
        }

        $vendedor = $e['vendedor'] ?? null;
        if (!is_string($vendedor) || !in_array($vendedor, self::VENDEDORES, true)) {
            $err[] = 'El tipo de vendedor debe ser "particular" o "profesional".';
        }

        $cc = self::opcional($e, 'cc', 1, 20000, 'La cilindrada', $err);
        $cilindros = self::opcional($e, 'cilindros', 1, 16, 'El número de cilindros', $err);
        $cv = self::opcional($e, 'cv', 1, 5000, 'Los CV', $err);
        $kw = self::opcional($e, 'kw', 1, 4000, 'Los kW', $err);
        $co2 = self::opcional($e, 'co2_wltp', 0, 1000, 'El CO2', $err);
        if ($kw === null && $cv !== null) {
            $kw = round($cv * self::KW_POR_CV, 1);
        } elseif ($cv === null && $kw !== null) {
            $cv = round($kw / self::KW_POR_CV, 0);
        }

        $filaBoe = null;
        if (isset($e['fila_boe'])) {
            $f = $e['fila_boe'];
            $precioBoe = is_array($f) ? self::numero($f['precio'] ?? null) : null;
            if ($precioBoe === null || $precioBoe <= 0) {
                $err[] = 'La fila del BOE debe incluir un precio medio mayor que 0.';
            } else {
                $cvfBoe = self::numero($f['cvf'] ?? null);
                $cilBoe = self::numero($f['cilindros'] ?? null);
                $filaBoe = [
                    'precio' => $precioBoe,
                    'cvf' => ($cvfBoe !== null && $cvfBoe > 0) ? $cvfBoe : null,
                    'cilindros' => ($cilBoe !== null && $cilBoe >= 1) ? (int) $cilBoe : null,
                ];
            }
        }

        // Destino: el municipio manda; la CCAA se deduce de su provincia.
        $municipio = $e['municipio'] ?? null;
        $ccaa = null;
        if (!is_string($municipio) || !isset($tablas['municipios'][$municipio])) {
            $err[] = 'El municipio de destino no existe: usa el código INE de 5 cifras.';
        } else {
            $prov = substr($municipio, 0, 2);
            $ccaa = $tablas['provincias'][$prov]['ccaa'] ?? null;
            if ($ccaa === null || !isset($tablas['ccaa'][$ccaa])) {
                $err[] = 'No se ha podido determinar la comunidad autónoma del municipio.';
                $ccaa = null;
            } elseif (isset($e['ccaa']) && $e['ccaa'] !== $ccaa) {
                $err[] = 'La comunidad autónoma indicada no coincide con la del municipio.';
            } elseif (!$tablas['ccaa'][$ccaa]['soportada']) {
                $err[] = $tablas['ccaa'][$ccaa]['nombre'] . ' no está soportada: su régimen fiscal es distinto del común.';
            }
        }

        $cp = $e['cp_vendedor'] ?? null;
        $cp = is_string($cp) || is_int($cp) ? preg_replace('/\D/', '', (string) $cp) : '';

        $costesFijos = [];
        if (isset($e['costes_fijos'])) {
            $claves = array_column($tablas['costes_fijos']['partidas'], 'clave');
            if (!is_array($e['costes_fijos'])) {
                $err[] = 'Los costes fijos deben indicarse como lista de clave e importe.';
            } else {
                foreach ($e['costes_fijos'] as $clave => $valor) {
                    $v = self::numero($valor);
                    if (!in_array($clave, $claves, true)) {
                        $err[] = 'Coste fijo desconocido: ' . (is_string($clave) ? $clave : '?') . '.';
                    } elseif ($v === null || $v < 0 || $v > 100000) {
                        $err[] = "El coste fijo \"$clave\" debe ser un importe entre 0 y 100.000.";
                    } else {
                        $costesFijos[$clave] = $v;
                    }
                }
            }
        }

        $transporte = self::transporte($e['transporte'] ?? [], $err);

        if ($err) {
            throw new EntradaInvalida($err);
        }

        return [
            'marca' => self::texto($e['marca'] ?? null),
            'modelo' => self::texto($e['modelo'] ?? null),
            'version' => self::texto($e['version'] ?? null),
            'fecha_matriculacion' => $fechaMat,
            'fecha_calculo' => $fechaCalc,
            'km' => (int) $km,
            'cc' => $cc !== null ? (int) $cc : null,
            'cilindros' => $cilindros !== null ? (int) $cilindros : null,
            'cv' => $cv,
            'kw' => $kw,
            'combustible' => $combustible,
            'co2_wltp' => $co2,
            'precio' => $precio,
            'vendedor' => $vendedor,
            'iva_deducible' => !empty($e['iva_deducible']),
            'cp_vendedor' => $cp,
            'municipio' => $municipio,
            'ccaa' => $ccaa,
            'fila_boe' => $filaBoe,
            'costes_fijos' => $costesFijos,
            'transporte' => $transporte,
        ];
    }

    private static function transporte($t, array &$err): array
    {
        if (!is_array($t)) {
            $err[] = 'Los parámetros de transporte deben indicarse como lista de clave y valor.';
            return [];
        }
        $salida = [];
        if (isset($t['modo'])) {
            if (!in_array($t['modo'], self::MODOS_TRANSPORTE, true)) {
                $err[] = 'El modo de transporte debe ser "conduciendo" o "camion".';
            } else {
                $salida['modo'] = $t['modo'];
            }
        }
        $limites = [
            'factor_carretera' => [1, 3],
            'velocidad_kmh' => [20, 130],
            'consumo_por_100km' => [0.1, 100],
            'precio_por_unidad' => [0, 20],
            'precio_por_km' => [0, 20],
            'peajes' => [0, 100000],
            'billete_vuelta' => [0, 100000],
            'alojamiento' => [0, 100000],
        ];
        foreach ($limites as $clave => [$min, $max]) {
            if (!isset($t[$clave])) {
                continue;
            }
            $v = self::numero($t[$clave]);
            if ($v === null || $v < $min || $v > $max) {
                $err[] = "El parámetro de transporte \"$clave\" debe estar entre $min y $max.";
            } else {
                $salida[$clave] = $v;
            }
        }
        return $salida;
    }

    private static function fecha(array $e, string $clave, string $nombre, array &$err): ?\DateTimeImmutable
    {
        $valor = $e[$clave] ?? null;
        $fecha = is_string($valor) ? Fechas::parsear($valor) : null;
        if ($fecha === null) {
            $err[] = "$nombre es obligatoria y debe tener el formato AAAA-MM-DD.";
        }
        return $fecha;
    }

    private static function opcional(array $e, string $clave, float $min, float $max, string $nombre, array &$err): ?float
    {
        if (!isset($e[$clave]) || $e[$clave] === '') {
            return null;
        }
        $v = self::numero($e[$clave]);
        if ($v === null || $v < $min || $v > $max) {
            $err[] = "$nombre debe estar entre $min y $max.";
            return null;
        }
        return $v;
    }

    /** Acepta int, float o texto numérico (con punto decimal). Rechaza NaN e infinitos. */
    private static function numero($valor): ?float
    {
        if (is_int($valor) || is_float($valor) || (is_string($valor) && is_numeric($valor))) {
            $f = (float) $valor;
            return is_finite($f) ? $f : null;
        }
        return null;
    }

    private static function texto($valor): string
    {
        return is_string($valor) ? mb_substr(trim($valor), 0, 120) : '';
    }
}
