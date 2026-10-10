<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Anuncio;

/** Diccionarios del lector de anuncios (data/diccionarios/*.json): rótulos de los datos y textos de combustible. */
final class Etiquetas
{
    /** @var array<string, string> rótulo normalizado => campo */
    private array $campos = [];
    /** @var array<string, string> texto normalizado => combustible */
    private array $combustibles = [];
    /** @var list<string> */
    private array $profesional;
    /** @var list<string> */
    private array $particular;
    /** @var list<string> */
    private array $iva;

    private function __construct(array $etiquetas, array $combustibles)
    {
        foreach ($etiquetas['campos'] ?? [] as $campo => $rotulos) {
            foreach ($rotulos as $r) {
                $this->campos[self::rotulo($r)] = $campo;
            }
        }
        foreach ($combustibles['combustibles'] ?? [] as $texto => $clave) {
            $this->combustibles[self::sinAcentos($texto)] = $clave;
        }
        uksort($this->combustibles, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $this->profesional = array_map([self::class, 'sinAcentos'], $etiquetas['vendedor_profesional'] ?? []);
        $this->particular = array_map([self::class, 'sinAcentos'], $etiquetas['vendedor_particular'] ?? []);
        $this->iva = array_map([self::class, 'sinAcentos'], $etiquetas['iva_deducible'] ?? []);
    }

    public static function cargar(string $dirData): self
    {
        $leer = static function (string $f) use ($dirData): array {
            $t = @file_get_contents($dirData . '/diccionarios/' . $f);
            $d = $t !== false ? json_decode($t, true) : null;
            return is_array($d) ? $d : [];
        };
        return new self($leer('etiquetas.json'), $leer('combustibles.json'));
    }

    /** Minúsculas y sin tildes (la ñ se conserva como n). */
    public static function sinAcentos(string $t): string
    {
        $t = mb_strtolower(trim($t), 'UTF-8');
        return strtr($t, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i',
            'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ß' => 'ss',
        ]);
    }

    /** Forma comparable de un rótulo: sin notas al pie, sin paréntesis, sin dos puntos, subíndices como cifras. */
    public static function rotulo(string $texto): string
    {
        $t = strtr($texto, ['₂' => '2', '¹' => '', '²' => '', '³' => '', '*' => '', "\u{00a0}" => ' ']);
        $t = preg_replace('/\([^)]*\)/u', ' ', $t) ?? $t;
        $t = self::sinAcentos($t);
        $t = preg_replace('/[:：]+\s*$/u', '', trim($t)) ?? $t;
        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }

    /** ¿A qué dato corresponde este rótulo? Null si no es ninguno de los conocidos. */
    public function campo(string $texto): ?string
    {
        if ($texto === '' || mb_strlen($texto) > 60) {
            return null;
        }
        return $this->campos[self::rotulo($texto)] ?? null;
    }

    /**
     * Línea que empieza por un rótulo conocido y sigue con el valor («Kilometerstand 105.000 km», «EZ 03/2019»).
     *
     * @return array{campo: string, rotulo: string, resto: string}|null
     */
    public function prefijo(string $linea): ?array
    {
        static $ordenados = [];
        $clave = spl_object_id($this);
        $ordenados[$clave] ??= (function (): array {
            $r = $this->campos;
            uksort($r, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
            return $r;
        })();
        $n = self::sinAcentos(strtr($linea, ['₂' => '2']));
        foreach ($ordenados[$clave] as $rotulo => $campo) {
            $largo = mb_strlen($rotulo);
            if (str_starts_with($n, $rotulo . ' ') && mb_strlen($linea) > $largo + 1) {
                $resto = trim(mb_substr($linea, $largo + 1));
                if ($resto !== '') {
                    return ['campo' => $campo, 'rotulo' => mb_substr($linea, 0, $largo), 'resto' => $resto];
                }
            }
        }
        return null;
    }

    /** Combustible de la calculadora para el texto del anuncio, y si es una coincidencia exacta o aproximada. @return array{clave: string, exacto: bool}|null */
    public function combustible(string $texto): ?array
    {
        $t = self::sinAcentos(preg_replace('/\s+/u', ' ', $texto) ?? $texto);
        if ($t === '') {
            return null;
        }
        if (isset($this->combustibles[$t])) {
            return ['clave' => $this->combustibles[$t], 'exacto' => true];
        }
        // «Elektro/Benzin (Plug-in-Hybrid)»: gana la entrada más específica que aparezca dentro del texto
        foreach (['plug-in', 'plugin', 'phev', 'enchufable'] as $pista) {
            if (str_contains($t, $pista)) {
                return ['clave' => 'phev', 'exacto' => false];
            }
        }
        foreach ($this->combustibles as $clave => $valor) {
            if (strlen($clave) >= 4 && preg_match('/(?<![a-z])' . preg_quote($clave, '/') . '(?![a-z])/', $t)) {
                return ['clave' => $valor, 'exacto' => false];
            }
        }
        return null;
    }

    /** @return 'profesional'|'particular'|null */
    public function vendedor(string $texto): ?string
    {
        $t = self::sinAcentos($texto);
        foreach ($this->profesional as $p) {
            if ($p !== '' && preg_match('/(?<![a-z])' . preg_quote($p, '/') . '/', $t)) {
                return 'profesional';
            }
        }
        foreach ($this->particular as $p) {
            if ($p !== '' && preg_match('/(?<![a-z])' . preg_quote($p, '/') . '(?![a-z])/', $t)) {
                return 'particular';
            }
        }
        return null;
    }

    /**
     * Una línea que es SOLO el tipo de vendedor («Privatanbieter», «Händler», «Particular»…). Una frase que contiene la
     * palabra («Händler-Suche», «Für Händler») no cuenta: está en los menús de cualquier página. «Privat» a secas
     * tampoco, porque también es una pestaña de los buscadores.
     *
     * @return 'profesional'|'particular'|null
     */
    public function vendedorEnLinea(string $linea): ?string
    {
        if (mb_strlen($linea) > 40) {
            return null;
        }
        $t = self::sinAcentos(trim(preg_replace('/^(?:anbieter|verkaufer|seller|vendedor)\s*:?\s*/u', '', self::sinAcentos($linea)) ?? '', ' :'));
        if ($t === '' || in_array($t, ['privat', 'private'], true)) {
            return null;
        }
        if (in_array($t, $this->profesional, true) || preg_match('/^autohaus [\w .-]{1,30}$/u', $t)) {
            return 'profesional';
        }
        return in_array($t, $this->particular, true) ? 'particular' : null;
    }

    public function mencionaIvaDeducible(string $texto): bool
    {
        $t = self::sinAcentos(preg_replace('/\s+/u', ' ', $texto) ?? $texto);
        foreach ($this->iva as $p) {
            if ($p !== '' && str_contains($t, $p)) {
                return true;
            }
        }
        return false;
    }
}
