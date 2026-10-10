<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Boe;

/** Texto de marcas, modelos y versiones comparable entre el anuncio, el usuario y la Orden del BOE. */
final class Normalizador
{
    private const SIN_ACENTOS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c', 'š' => 's', 'ž' => 'z', 'ß' => 'ss', 'ø' => 'o', 'æ' => 'ae',
    ];

    /** Palabras que no distinguen una versión de otra (cambio, puertas, rellenos): no cuentan ni a favor ni en contra. */
    private const RELLENO = [
        'aut', 'auto', 'automatico', 'automatica', 'automatic', 'automatik', 'man', 'manual', 'cambio', 'vel', 'velocidades',
        'de', 'del', 'la', 'el', 'con', 'y', 'and', 'mit', 'und', 'ptas', 'puertas', 'turer', 'tur', 'door', 'doors',
        'hibrido', 'hybrid', 'mhev', 'diesel', 'gasolina', 'benzin', 'electrico', 'elektro',
        'edition', 'aniversario', 'serie', 'paquete', 'pack', 'euro', 'e6', 'e6d', 'e6dtemp', 'e6dfinal', 'kw', 'cv', 'ps', 'hp',
    ];

    /** Cómo llama el anuncio (en alemán o inglés) a lo que el BOE llama de otra forma. Se aplica a los dos lados de la comparación. */
    private const SINONIMOS = [
        '/\bt modell\b|\btmodell\b/' => 'estate',
        '/\blimousine\b/' => 'berlina',
        '/\blong range\b/' => 'gran autonomia',
        '/\bstandard range\b/' => 'autonomia estandar',
    ];

    /** Minúsculas, sin acentos y con todo lo que no sea letra, cifra o punto decimal convertido en espacios. */
    public static function texto(string $texto): string
    {
        $t = mb_strtolower($texto, 'UTF-8');
        $t = strtr($t, self::SIN_ACENTOS);
        $t = preg_replace('/(?<=\d),(?=\d)/', '.', $t) ?? $t;                 // 1,8 -> 1.8
        $t = preg_replace('/[^a-z0-9.]+/', ' ', $t) ?? $t;
        $t = preg_replace('/(?<!\d)\.|\.(?!\d)/', ' ', $t) ?? $t;             // puntos que no son decimales
        $t = preg_replace('/\b(\d{2,3}) ([a-z])\b/', '$1$2', $t) ?? $t;        // «320 d» -> «320d», «c 220 d» -> «c 220d»
        $t = preg_replace(array_keys(self::SINONIMOS), array_values(self::SINONIMOS), $t) ?? $t;
        return trim(preg_replace('/\s+/', ' ', $t) ?? $t);
    }

    /** Marca para buscar en el diccionario: solo letras y cifras, sin separadores («Mercedes-Benz» = «mercedes benz»). */
    public static function marca(string $marca): string
    {
        return self::texto($marca);
    }

    /**
     * Palabras que identifican la versión. Quita los rellenos y repeticiones.
     *
     * @return list<string>
     */
    public static function palabras(string $texto): array
    {
        $resultado = [];
        foreach (explode(' ', self::texto($texto)) as $p) {
            if ($p === '' || in_array($p, self::RELLENO, true) || isset($resultado[$p])) {
                continue;
            }
            // «5p», «3p»: número de puertas
            if (preg_match('/^[2-7]p$/', $p) === 1) {
                continue;
            }
            $resultado[$p] = $p;
        }
        return array_values($resultado);
    }
}
