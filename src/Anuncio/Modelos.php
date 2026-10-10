<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Anuncio;

use CalculadoraImportacion\Boe\Normalizador;

/**
 * Familias de modelo de cada marca (data/diccionarios/modelos/<marca>.json, generadas desde el BOE), para separar
 * en un título «BMW 320d Touring Sport Line» el modelo («320d») de la versión («Touring Sport Line»).
 */
final class Modelos
{
    /** @var array<string, array<string, int>> marca del BOE => [familia normalizada => filas] */
    private array $cache = [];

    public function __construct(private readonly string $dirData)
    {
    }

    /**
     * Cuántas palabras del principio de $palabras forman el modelo, y si es un modelo conocido de la marca.
     *
     * @param list<string> $palabras palabras del título, sin la marca, tal como están escritas
     * @return array{n: int, exacto: bool}|null  null si no se reconoce ninguna
     */
    public function reconocer(string $marcaBoe, array $palabras): ?array
    {
        $familias = $this->familias($marcaBoe);
        if ($familias === [] || $palabras === []) {
            return null;
        }
        $mejor = null;
        for ($n = min(4, count($palabras)); $n >= 1; $n--) {
            $texto = Normalizador::texto(implode(' ', array_slice($palabras, 0, $n)));
            if (isset($familias[$texto])) {
                $mejor = ['n' => $n, 'exacto' => true];
                // Una clase de una letra va con su número: «C 220», «E 350», «A 180»
                if (strlen($texto) === 1 && isset($palabras[$n]) && preg_match('/^\d{2,3}$/', $palabras[$n])) {
                    $mejor['n']++;
                }
                break;
            }
        }
        if ($mejor === null) {
            // «320d» cuando el BOE solo conoce «320» (los BMW se nombran por el motor)
            $primera = Normalizador::texto($palabras[0]);
            if (preg_match('/^(\d{2,4})[a-z]{1,3}$/', $primera, $m) && isset($familias[$m[1]])) {
                return ['n' => 1, 'exacto' => false];
            }
        }
        return $mejor;
    }

    /** @return array<string, int> */
    private function familias(string $marcaBoe): array
    {
        if (!isset($this->cache[$marcaBoe])) {
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', Normalizador::texto($marcaBoe)), '_');
            $texto = $slug !== '' ? @file_get_contents($this->dirData . '/diccionarios/modelos/' . $slug . '.json') : false;
            $datos = $texto !== false ? json_decode($texto, true) : null;
            $f = [];
            foreach (is_array($datos) ? ($datos['modelos'] ?? []) : [] as $m) {
                $f[Normalizador::texto((string) $m['modelo'])] = (int) $m['filas'];
            }
            $this->cache[$marcaBoe] = $f;
        }
        return $this->cache[$marcaBoe];
    }
}
