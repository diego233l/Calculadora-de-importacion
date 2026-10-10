<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Datos;

/**
 * Carga las tablas de referencia del repositorio (data/) y las deja en el array que consume el motor.
 * Es la única parte del cálculo que toca el disco; el motor (src/Calculo) solo recibe el array.
 *
 * Claves del array resultante:
 *   anio, ccaa, depreciacion, iva, iedmt, itp, ivtm_tarifas, ivtm_por_ine, costes_fijos, transporte  (data/fiscal/<año>/)
 *   plz         prefijo alemán => [lat, lon]                (data/plz_de.json)
 *   municipios  código INE => {nombre, lat, lon, aprox}     (data/municipios_es.json)
 *   provincias  código de provincia => {nombre, ccaa}
 */
final class Tablas
{
    private const FICHEROS_FISCALES = [
        'ccaa' => 'ccaa.json',
        'depreciacion' => 'depreciacion.json',
        'iva' => 'iva.json',
        'iedmt' => 'iedmt.json',
        'itp' => 'itp.json',
        'ivtm_tarifas' => 'ivtm_tarifas.json',
        'ivtm_municipios' => 'ivtm_municipios.json',
        'costes_fijos' => 'costes_fijos.json',
        'transporte' => 'transporte.json',
    ];

    /** Carga data/fiscal/<año> más los ficheros geográficos de data/. */
    public static function cargar(string $dirData, int $anio): array
    {
        $anio = self::anioDisponible($dirData, $anio);
        return self::cargarDesde(
            $dirData . '/fiscal/' . $anio,
            $dirData . '/plz_de.json',
            $dirData . '/municipios_es.json'
        );
    }

    /** Año fiscal más reciente que no supere $anio (si no hay ninguno anterior, el más antiguo disponible). */
    public static function anioDisponible(string $dirData, int $anio): int
    {
        $anios = [];
        foreach (glob($dirData . '/fiscal/[0-9][0-9][0-9][0-9]', GLOB_ONLYDIR) ?: [] as $dir) {
            $anios[] = (int) basename($dir);
        }
        if (!$anios) {
            throw new \RuntimeException('No hay tablas fiscales en ' . $dirData . '/fiscal');
        }
        sort($anios);
        $elegido = $anios[0];
        foreach ($anios as $a) {
            if ($a <= $anio) {
                $elegido = $a;
            }
        }
        return $elegido;
    }

    /** Variante con rutas explícitas; la usan los tests con tablas de prueba. */
    public static function cargarDesde(string $dirFiscal, string $ficheroPlz, string $ficheroMunicipios): array
    {
        $t = [];
        foreach (self::FICHEROS_FISCALES as $clave => $fichero) {
            $t[$clave] = self::leer($dirFiscal . '/' . $fichero);
        }
        $t['anio'] = (int) $t['depreciacion']['anio'];
        $t['ccaa'] = $t['ccaa']['ccaa'];

        $t['ivtm_por_ine'] = [];
        foreach ($t['ivtm_municipios']['municipios'] as $m) {
            $t['ivtm_por_ine'][$m['ine']] = $m;
        }

        $plz = self::leer($ficheroPlz);
        $t['plz'] = $plz['prefijos'];

        $geo = self::leer($ficheroMunicipios);
        $t['provincias'] = $geo['provincias'];
        $t['municipios'] = [];
        foreach ($geo['municipios'] as $m) {
            $t['municipios'][(string) $m[0]] = [
                'nombre' => $m[1],
                'lat' => (float) $m[2],
                'lon' => (float) $m[3],
                'aprox' => !empty($m[4]),
            ];
        }
        return $t;
    }

    private static function leer(string $ruta): array
    {
        $texto = @file_get_contents($ruta);
        if ($texto === false) {
            throw new \RuntimeException('No se puede leer el fichero de datos: ' . $ruta);
        }
        try {
            $datos = json_decode($texto, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('JSON no válido en ' . $ruta . ': ' . $e->getMessage());
        }
        if (!is_array($datos)) {
            throw new \RuntimeException('El fichero de datos no contiene un objeto JSON: ' . $ruta);
        }
        return $datos;
    }
}
