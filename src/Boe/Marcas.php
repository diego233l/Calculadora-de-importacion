<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Boe;

/** Diccionario de marcas (data/diccionarios/marcas.json): del nombre que se escribe en un anuncio al nombre que usa el BOE. */
final class Marcas
{
    /** @var array<string, string> alias normalizado => marca del BOE */
    private array $alias = [];

    /** @param array<string, array{nombre: string, alias: list<string>}> $marcas */
    private function __construct(private readonly array $marcas)
    {
        foreach ($marcas as $boe => $m) {
            $this->alias[Normalizador::marca($boe)] = $boe;
            $this->alias[Normalizador::marca($m['nombre'])] = $boe;
            foreach ($m['alias'] as $a) {
                $this->alias[Normalizador::marca($a)] = $boe;
            }
        }
    }

    public static function cargar(string $dirData): self
    {
        $texto = @file_get_contents($dirData . '/diccionarios/marcas.json');
        $datos = $texto !== false ? json_decode($texto, true) : null;
        return new self(is_array($datos) && is_array($datos['marcas'] ?? null) ? $datos['marcas'] : []);
    }

    /** Marca del BOE para lo que ha escrito el usuario o dice el anuncio («Mercedes-Benz» -> «MERCEDES»). */
    public function resolver(string $texto): ?string
    {
        return $this->alias[Normalizador::marca($texto)] ?? null;
    }

    /**
     * Separa la marca del principio de un título: «Mercedes-Benz C 220 d T-Modell» -> [MERCEDES, «C 220 d T-Modell»].
     * Gana el alias más largo («alfa romeo» antes que «alfa»).
     *
     * @return array{marca: string, resto: string}|null
     */
    public function desdeTitulo(string $titulo): ?array
    {
        $t = Normalizador::texto($titulo);
        $mejor = null;
        foreach ($this->alias as $alias => $boe) {
            if ($alias === '') {
                continue;
            }
            if ($t === $alias || str_starts_with($t, $alias . ' ')) {
                if ($mejor === null || strlen($alias) > strlen($mejor[0])) {
                    $mejor = [$alias, $boe];
                }
            }
        }
        if ($mejor === null) {
            return null;
        }
        return ['marca' => $mejor[1], 'resto' => trim(substr($t, strlen($mejor[0])))];
    }

    public function nombreVisible(string $marcaBoe): string
    {
        return $this->marcas[$marcaBoe]['nombre'] ?? $marcaBoe;
    }

    /** @return list<string> nombres visibles, para la lista de sugerencias del formulario */
    public function nombresVisibles(): array
    {
        $n = array_map(static fn(array $m): string => $m['nombre'], array_values($this->marcas));
        sort($n, SORT_NATURAL | SORT_FLAG_CASE);
        return $n;
    }
}
