<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Anuncio;

/** Valores propuestos para cada dato del anuncio por las distintas fuentes; elige el mejor y avisa si se contradicen. */
final class Candidatos
{
    /** @var array<string, list<array{valor: string, confianza: float, origen: string, nota: ?string, orden: int}>> */
    private array $porCampo = [];
    /** @var array<string, string> */
    private array $extras = [];
    private int $orden = 0;

    public function proponer(string $campo, string $valor, float $confianza, string $origen, ?string $nota = null): void
    {
        if ($valor === '') {
            return;
        }
        $this->porCampo[$campo][] = ['valor' => $valor, 'confianza' => $confianza, 'origen' => $origen, 'nota' => $nota, 'orden' => $this->orden++];
    }

    public function extraer(string $clave, string $valor): void
    {
        $this->extras[$clave] ??= $valor;
    }

    public function extra(string $clave): ?string
    {
        return $this->extras[$clave] ?? null;
    }

    /**
     * La propuesta de mayor confianza (a igualdad, la primera que se encontró). Si otra fuente igual de fiable dice
     * otra cosa, la confianza baja a 0,7 y se explica en la nota.
     *
     * @return array{valor: string, confianza: float, origen: string, nota: ?string}|null
     */
    public function mejor(string $campo): ?array
    {
        $lista = $this->porCampo[$campo] ?? [];
        if ($lista === []) {
            return null;
        }
        usort($lista, static fn(array $a, array $b): int => [$b['confianza'], $a['orden']] <=> [$a['confianza'], $b['orden']]);
        $m = $lista[0];
        foreach (array_slice($lista, 1) as $otra) {
            if ($otra['valor'] !== $m['valor'] && $otra['confianza'] >= $m['confianza'] - 0.1 && !self::compatibles($campo, $m['valor'], $otra['valor'])) {
                return [
                    'valor' => $m['valor'], 'confianza' => min($m['confianza'], 0.7), 'origen' => $m['origen'],
                    'nota' => 'El anuncio da dos valores distintos (' . $m['valor'] . ' y ' . $otra['valor'] . '): comprueba cuál es el correcto.',
                ];
            }
        }
        return ['valor' => $m['valor'], 'confianza' => $m['confianza'], 'origen' => $m['origen'], 'nota' => $m['nota']];
    }

    /** Dos textos de marca/modelo/versión que difieren solo en mayúsculas o espacios no son una contradicción. */
    private static function compatibles(string $campo, string $a, string $b): bool
    {
        if (in_array($campo, ['marca', 'modelo', 'version'], true)) {
            $n = static fn(string $t): string => preg_replace('/[^a-z0-9]+/', '', mb_strtolower($t)) ?? '';
            return $n($a) === $n($b) || str_contains($n($a), $n($b)) || str_contains($n($b), $n($a));
        }
        return false;
    }
}
