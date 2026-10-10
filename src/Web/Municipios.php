<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Web;

/** Búsqueda y resolución de municipios de destino (solo los de comunidades soportadas). */
final class Municipios
{
    private const ACENTOS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c', '·' => '',
    ];

    /** Minúsculas, sin acentos y con los espacios y guiones unificados. */
    public static function normalizar(string $texto): string
    {
        $t = strtr(mb_strtolower($texto, 'UTF-8'), self::ACENTOS);
        $t = preg_replace('/[^a-z0-9]+/', ' ', $t) ?? '';
        return trim($t);
    }

    /** ¿El municipio pertenece a una comunidad soportada? */
    public static function soportado(array $tablas, string $ine): bool
    {
        $ccaa = $tablas['provincias'][substr($ine, 0, 2)]['ccaa'] ?? null;
        return $ccaa !== null && !empty($tablas['ccaa'][$ccaa]['soportada']);
    }

    /** "Madrid (Madrid)": el nombre del municipio y, entre paréntesis, su provincia. */
    public static function etiqueta(array $tablas, string $ine): string
    {
        $m = $tablas['municipios'][$ine] ?? null;
        if ($m === null) {
            return '';
        }
        $prov = $tablas['provincias'][substr($ine, 0, 2)]['nombre'] ?? '';
        return $m['nombre'] . (self::mismoNombre($m['nombre'], $prov) ? '' : ' (' . $prov . ')');
    }

    /** «Madrid (Madrid)» sobra: el paréntesis solo se pone si la provincia se llama distinto. */
    public static function mismoNombre(string $municipio, string $provincia): bool
    {
        $m = array_map([self::class, 'normalizar'], explode('/', $municipio));
        $p = array_map([self::class, 'normalizar'], explode('/', $provincia));
        return array_intersect($m, $p) !== [];
    }

    /**
     * @return array<int, array{ine: string, nombre: string, provincia: string}>
     */
    public static function buscar(array $tablas, string $consulta, int $max = 8): array
    {
        $q = self::normalizar($consulta);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $empieza = [];
        $contiene = [];
        // Las grandes ciudades (la lista de municipios del IVTM va ordenada por población) salen primero
        $populares = array_flip(array_map('strval', array_keys($tablas['ivtm_por_ine'] ?? [])));
        foreach ($tablas['municipios'] as $ine => $m) {
            $ine = (string) $ine;
            $n = self::normalizar($m['nombre']);
            $pos = strpos(' ' . $n, ' ' . $q);
            if ($pos === false && !str_contains($n, $q)) {
                continue;
            }
            if (!self::soportado($tablas, $ine)) {
                continue;
            }
            $fila = [
                'ine' => $ine,
                'nombre' => $m['nombre'],
                'provincia' => $tablas['provincias'][substr($ine, 0, 2)]['nombre'] ?? '',
                'popular' => $populares[$ine] ?? 9999,
                'orden' => strlen($n),
            ];
            if (str_starts_with($n, $q) || $pos !== false) {
                $empieza[] = $fila;
            } else {
                $contiene[] = $fila;
            }
            // Con muchos candidatos ya no hace falta seguir: se ordenan los que haya y se recortan
            if (count($empieza) >= 400) {
                break;
            }
        }
        $orden = static fn(array $a, array $b): int => [$a['popular'], $a['orden'], $a['nombre']] <=> [$b['popular'], $b['orden'], $b['nombre']];
        usort($empieza, $orden);
        usort($contiene, $orden);
        $salida = [];
        foreach (array_merge($empieza, $contiene) as $f) {
            unset($f['orden'], $f['popular']);
            $salida[] = $f;
            if (count($salida) >= $max) {
                break;
            }
        }
        return $salida;
    }

    /**
     * Resuelve lo que el usuario ha elegido o escrito.
     * @return array{ine: ?string, candidatos: array, error: ?string}
     */
    public static function resolver(array $tablas, string $ine, string $texto): array
    {
        if ($ine !== '' && preg_match('/^\d{5}$/', $ine) && isset($tablas['municipios'][$ine])) {
            return self::soportado($tablas, $ine)
                ? ['ine' => $ine, 'candidatos' => [], 'error' => null]
                : ['ine' => null, 'candidatos' => [], 'error' => self::mensajeNoSoportado($tablas, $ine)];
        }
        $texto = trim($texto);
        if ($texto === '') {
            return ['ine' => null, 'candidatos' => [], 'error' => 'Elige el municipio de España donde vas a matricular el coche.'];
        }
        // Texto escrito sin elegir de la lista (o sin JavaScript): se quita el «(Provincia)» y se busca el nombre exacto
        $nombre = trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', $texto));
        $buscado = self::normalizar($nombre);
        $exactos = [];
        foreach ($tablas['municipios'] as $codigo => $m) {
            $partes = array_map([self::class, 'normalizar'], explode('/', $m['nombre']));
            if (in_array($buscado, $partes, true) || self::normalizar($m['nombre']) === $buscado) {
                $exactos[] = (string) $codigo;
            }
        }
        $soportados = array_values(array_filter($exactos, static fn(string $c): bool => self::soportado($tablas, $c)));
        if (count($soportados) === 1) {
            return ['ine' => $soportados[0], 'candidatos' => [], 'error' => null];
        }
        if (count($soportados) > 1) {
            $cands = array_map(static fn(string $c): array => ['ine' => $c, 'etiqueta' => self::etiqueta($tablas, $c)], $soportados);
            return ['ine' => null, 'candidatos' => $cands, 'error' => 'Hay varios municipios con ese nombre: elige el tuyo.'];
        }
        if ($exactos) {
            return ['ine' => null, 'candidatos' => [], 'error' => self::mensajeNoSoportado($tablas, $exactos[0])];
        }
        $cands = array_map(
            static fn(array $f): array => ['ine' => $f['ine'], 'etiqueta' => $f['nombre'] . (self::mismoNombre($f['nombre'], $f['provincia']) ? '' : ' (' . $f['provincia'] . ')')],
            self::buscar($tablas, $nombre, 6)
        );
        return [
            'ine' => null,
            'candidatos' => $cands,
            'error' => $cands ? 'No encuentro ese municipio exacto. ¿Es alguno de estos?' : 'No encuentro ese municipio. Escribe las primeras letras y elígelo de la lista.',
        ];
    }

    private static function mensajeNoSoportado(array $tablas, string $ine): string
    {
        $ccaa = $tablas['provincias'][substr($ine, 0, 2)]['ccaa'] ?? null;
        $nombre = $ccaa !== null ? ($tablas['ccaa'][$ccaa]['nombre'] ?? 'esa comunidad') : 'esa comunidad';
        return 'De momento no calculamos importaciones para ' . $nombre . ': su régimen fiscal es distinto del común.';
    }
}
