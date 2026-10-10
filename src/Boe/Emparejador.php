<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Boe;

/**
 * Busca en la Orden del BOE las filas que más se parecen a un coche. Compara cinco criterios, cada uno de 0 a 1,
 * y los pondera (si falta un dato del coche, ese criterio no cuenta):
 *   nombre (modelo y versión), año de matriculación dentro del periodo comercial, cilindrada, potencia y combustible.
 * Devuelve las mejores filas con su puntuación y el porqué; nunca decide por el usuario.
 */
final class Emparejador
{
    public const PESOS = ['nombre' => 0.40, 'anio' => 0.20, 'cc' => 0.10, 'potencia' => 0.20, 'combustible' => 0.10];
    public const CV_POR_KW = 1.35962;
    /** Puntuación a partir de la cual se considera una coincidencia fiable. */
    public const UMBRAL_ALTA = 0.85;
    public const UMBRAL_MEDIA = 0.65;
    /** Por debajo no se propone la fila. */
    public const UMBRAL_MINIMO = 0.35;

    /** Qué puntúa cada tipo de motor del BOE para un combustible del anuncio (el resto puntúa 0). */
    private const COMBUSTIBLES = [
        'gasolina' => ['G' => 1.0, 'GyE' => 0.8, 'M' => 0.7, 'S' => 0.5, 'PHEV' => 0.2],
        'diesel' => ['D' => 1.0, 'DyE' => 0.8, 'PHEV' => 0.2],
        'electrico' => ['Elc' => 1.0],
        'hibrido' => ['GyE' => 1.0, 'DyE' => 1.0, 'SyE' => 1.0, 'PHEV' => 0.5, 'G' => 0.4, 'D' => 0.4],
        'phev' => ['PHEV' => 1.0, 'GyE' => 0.3, 'DyE' => 0.3],
        'glp' => ['S' => 1.0, 'G' => 0.4],
        'gnc' => ['G' => 0.6, 'S' => 0.4],
        'hidrogeno' => ['H' => 1.0, 'Elc' => 0.2],
    ];

    private const PREFIJOS_DE_MODELO = ['model', 'serie', 'clase', 'range', 'grand', 'land', 'gr', 'id', 'new', 'nuevo', 'nvo'];

    public function __construct(private readonly Repositorio $repositorio, private readonly Marcas $marcas)
    {
    }

    /**
     * @param array{marca?: string, modelo?: string, anio?: int|null, cc?: int|null, cv?: float|int|null, kw?: float|int|null, combustible?: string|null} $coche
     *        «modelo» es el texto libre de modelo y versión («Golf VII 2.0 TDI Highline»).
     * @return array{marca_boe: ?string, candidatas: list<array{fila: array<string, mixed>, puntuacion: float, confianza: string, detalle: array<string, ?float>, motivos: list<string>}>}
     */
    public function buscar(array $coche, int $max = 3): array
    {
        $marcaBoe = $this->marcas->resolver((string) ($coche['marca'] ?? ''));
        if ($marcaBoe === null) {
            return ['marca_boe' => null, 'candidatas' => []];
        }
        $consulta = $this->preparar($coche, $marcaBoe);
        $puntuadas = [];
        foreach ($this->repositorio->filasDeMarca($marcaBoe) as $fila) {
            $r = $this->puntuar($fila, $consulta);
            if ($r['puntuacion'] >= self::UMBRAL_MINIMO) {
                $puntuadas[] = $r;
            }
        }
        usort($puntuadas, static fn(array $a, array $b): int
            => [$b['puntuacion'], $a['fila']['id']] <=> [$a['puntuacion'], $b['fila']['id']]);
        return ['marca_boe' => $marcaBoe, 'candidatas' => array_slice($puntuadas, 0, $max)];
    }

    /**
     * Puntúa una fila concreta contra un coche (para enseñar la fila ya elegida junto a las alternativas).
     *
     * @param array<string, mixed> $coche
     * @param array<string, mixed> $fila
     * @return array{fila: array<string, mixed>, puntuacion: float, confianza: string, detalle: array<string, ?float>, motivos: list<string>}
     */
    public function evaluar(array $coche, array $fila): array
    {
        return $this->puntuar($fila, $this->preparar($coche, (string) $fila['marca']));
    }

    /**
     * @param array<string, mixed> $coche
     * @return array{palabras: list<string>, anio: ?int, cc: ?int, cv: ?float, kw: ?float, combustible: ?string}
     */
    private function preparar(array $coche, string $marcaBoe): array
    {
        $texto = (string) ($coche['modelo'] ?? '');
        // Si el texto repite la marca al principio («Volkswagen Golf…»), se quita
        $separado = $this->marcas->desdeTitulo($texto);
        if ($separado !== null && $separado['marca'] === $marcaBoe) {
            $texto = $separado['resto'];
        }
        $kw = isset($coche['kw']) ? (float) $coche['kw'] : null;
        $cv = isset($coche['cv']) ? (float) $coche['cv'] : null;
        return [
            'palabras' => Normalizador::palabras($texto),
            'anio' => isset($coche['anio']) ? (int) $coche['anio'] : null,
            'cc' => isset($coche['cc']) ? (int) $coche['cc'] : null,
            'cv' => $cv,
            'kw' => $kw,
            'combustible' => isset($coche['combustible']) && is_string($coche['combustible']) ? $coche['combustible'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $fila
     * @param array{palabras: list<string>, anio: ?int, cc: ?int, cv: ?float, kw: ?float, combustible: ?string} $q
     * @return array{fila: array<string, mixed>, puntuacion: float, confianza: string, detalle: array<string, ?float>, motivos: list<string>}
     */
    public function puntuar(array $fila, array $q): array
    {
        $detalle = [
            'nombre' => $this->nombre($fila, $q['palabras']),
            'anio' => $this->anio($fila, $q['anio']),
            'cc' => $this->cilindrada($fila, $q['cc']),
            'potencia' => $this->potencia($fila, $q['cv'], $q['kw']),
            'combustible' => $this->combustible($fila, $q['combustible']),
        ];
        $suma = 0.0;
        $peso = 0.0;
        foreach ($detalle as $criterio => $valor) {
            if ($valor !== null) {
                $suma += self::PESOS[$criterio] * $valor;
                $peso += self::PESOS[$criterio];
            }
        }
        $puntuacion = $peso > 0 ? $suma / $peso : 0.0;
        if (($detalle['combustible'] ?? 1.0) <= 0.1) {
            $puntuacion *= 0.7;                       // un diésel no es un gasolina aunque se llamen igual
        }
        $puntuacion = round($puntuacion, 3);
        return [
            'fila' => $fila,
            'puntuacion' => $puntuacion,
            'confianza' => $puntuacion >= self::UMBRAL_ALTA ? 'alta' : ($puntuacion >= self::UMBRAL_MEDIA ? 'media' : 'baja'),
            'detalle' => $detalle,
            'motivos' => $this->motivos($fila, $q, $detalle),
        ];
    }

    /** @param list<string> $palabras */
    private function nombre(array $fila, array $palabras): ?float
    {
        if ($palabras === []) {
            return null;
        }
        $filaPalabras = Normalizador::palabras($fila['modelo_tipo']);
        if ($filaPalabras === []) {
            return 0.0;
        }
        $recuerdo = $this->cobertura($palabras, $filaPalabras);
        $precision = $this->cobertura($filaPalabras, $palabras);
        $valor = 0.6 * $recuerdo + 0.4 * $precision;
        // El modelo es lo primero que dice el BOE («Golf», «Model 3», «C 220»): si el anuncio no lo nombra, es otro coche
        foreach ($this->nucleo($filaPalabras) as $p) {
            if ($this->mejorCoincidencia($p, $palabras) < 0.6) {
                $valor *= 0.5;
                break;
            }
        }
        return round($valor, 3);
    }

    /**
     * Las palabras que identifican el modelo: la primera, y la segunda si la primera sola no basta
     * («Model» + «3», «C» + «220», «Range» + «Rover»).
     *
     * @param list<string> $palabras
     * @return list<string>
     */
    private function nucleo(array $palabras): array
    {
        if (isset($palabras[1]) && (strlen($palabras[0]) === 1 || in_array($palabras[0], self::PREFIJOS_DE_MODELO, true))) {
            return [$palabras[0], $palabras[1]];
        }
        return [$palabras[0]];
    }

    /**
     * Qué parte de $a aparece en $b (0 a 1): cada palabra cuenta 1 si está igual y 0,6 si una contiene a la otra
     * («carrera» dentro de «cabriocarrera», que el BOE escribe pegado).
     *
     * @param list<string> $a
     * @param list<string> $b
     */
    private function cobertura(array $a, array $b): float
    {
        $total = 0.0;
        foreach ($a as $p) {
            $total += $this->mejorCoincidencia($p, $b);
        }
        return $total / count($a);
    }

    /** @param list<string> $conjunto */
    private function mejorCoincidencia(string $palabra, array $conjunto): float
    {
        $mejor = 0.0;
        foreach ($conjunto as $otra) {
            if ($otra === $palabra) {
                return 1.0;
            }
            if (strlen($palabra) >= 4 && strlen($otra) >= 4 && (str_contains($otra, $palabra) || str_contains($palabra, $otra))) {
                $mejor = max($mejor, 0.6);
            }
        }
        return $mejor;
    }

    private function anio(array $fila, ?int $anio): ?float
    {
        if ($anio === null) {
            return null;
        }
        if ($fila['inicio'] === null) {
            return 0.5;
        }
        $fin = $fila['fin'] ?? PHP_INT_MAX;
        if ($anio >= $fila['inicio'] && $anio <= $fin) {
            return 1.0;
        }
        $distancia = $anio < $fila['inicio'] ? $fila['inicio'] - $anio : $anio - $fin;
        return max(0.0, round(1 - 0.3 * $distancia, 3));
    }

    private function cilindrada(array $fila, ?int $cc): ?float
    {
        if ($cc === null) {
            return null;
        }
        if ($fila['cc'] === null) {
            return 0.0;                              // eléctricos e hidrógeno no tienen cilindrada
        }
        return $this->cercania(abs($cc - $fila['cc']) / $fila['cc']);
    }

    private function potencia(array $fila, ?float $cv, ?float $kw): ?float
    {
        $mejor = null;
        if ($kw !== null && $fila['kw'] > 0) {
            $mejor = $this->cercania(abs($kw - $fila['kw']) / $fila['kw']);
        }
        if ($cv !== null && $fila['cv'] > 0) {
            $c = $this->cercania(abs($cv - $fila['cv']) / $fila['cv']);
            $mejor = $mejor === null ? $c : max($mejor, $c);
        }
        return $mejor;
    }

    /** Diferencia relativa -> puntuación: hasta 1 % igual, hasta 5 % bastante cerca, hasta 10 % lejos, más no vale. */
    private function cercania(float $diferencia): float
    {
        return match (true) {
            $diferencia <= 0.01 => 1.0,
            $diferencia <= 0.05 => 0.7,
            $diferencia <= 0.10 => 0.3,
            default => 0.0,
        };
    }

    private function combustible(array $fila, ?string $combustible): ?float
    {
        if ($combustible === null || !isset(self::COMBUSTIBLES[$combustible])) {
            return null;
        }
        return self::COMBUSTIBLES[$combustible][$fila['motor_codigo']] ?? 0.0;
    }

    /**
     * @param array<string, mixed> $fila
     * @param array<string, mixed> $q
     * @param array<string, ?float> $d
     * @return list<string>
     */
    private function motivos(array $fila, array $q, array $d): array
    {
        $m = [];
        if ($d['nombre'] !== null) {
            $m[] = $d['nombre'] >= 0.75 ? 'el nombre coincide' : ($d['nombre'] >= 0.4 ? 'el nombre se parece' : 'el nombre se parece poco');
        }
        if ($d['anio'] !== null) {
            $periodo = $fila['inicio'] === null ? 'sin periodo comercial'
                : ($fila['fin'] === null ? 'desde ' . $fila['inicio'] : $fila['inicio'] . '–' . $fila['fin']);
            $m[] = $d['anio'] >= 1.0 ? "el año {$q['anio']} está dentro del periodo comercial ($periodo)"
                : "el año {$q['anio']} queda fuera del periodo comercial ($periodo)";
        }
        if ($d['cc'] !== null) {
            $m[] = $d['cc'] >= 0.7 ? 'la cilindrada coincide' : 'la cilindrada no coincide';
        }
        if ($d['potencia'] !== null) {
            $m[] = $d['potencia'] >= 0.7 ? 'la potencia coincide' : 'la potencia no coincide';
        }
        if ($d['combustible'] !== null) {
            $m[] = $d['combustible'] >= 0.7 ? 'el combustible coincide' : 'el combustible no coincide';
        }
        return $m;
    }
}
