<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Calculo;

/** Tasa de la DGT, ITV de importación, COC, placas, gestoría y matrícula de exportación (valores editables). */
final class CostesFijos
{
    /** @return array{partidas: array} */
    public static function calcular(array $n, array $tablas): array
    {
        $t = $tablas['costes_fijos'];
        $partidas = [];
        foreach ($t['partidas'] as $p) {
            $editado = array_key_exists($p['clave'], $n['costes_fijos']);
            $importe = Dinero::redondear($editado ? $n['costes_fijos'][$p['clave']] : (float) $p['importe']);
            $origen = $editado
                ? 'Importe indicado por ti.'
                : ($p['estimacion'] ? 'Valor por defecto: estimación orientativa, editable.' : 'Valor por defecto, editable.');
            $partidas[] = Partida::ok(
                $p['clave'], $p['concepto'], $importe, null, null, null,
                $p['fuente'], $origen, !$editado && (Partida::pendiente($p) || Partida::pendiente($t)), $t['url'] ?? null
            );
        }
        return ['partidas' => $partidas];
    }
}
