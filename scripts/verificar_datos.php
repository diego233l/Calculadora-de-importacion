<?php

declare(strict_types=1);

/**
 * Lista todo lo que sigue marcado VERIFICAR en data/fiscal/<año>/.
 *   php scripts/verificar_datos.php [año]
 * Sale con código 1 si queda algo pendiente (útil como recordatorio, no como bloqueo).
 */

$anio = $argv[1] ?? '2026';
$dir = dirname(__DIR__) . '/data/fiscal/' . basename($anio);
if (!is_dir($dir)) {
    fwrite(STDERR, "No existe $dir\n");
    exit(2);
}

$pendientes = 0;
foreach (glob($dir . '/*.json') as $fichero) {
    $d = json_decode(file_get_contents($fichero), true, 512, JSON_THROW_ON_ERROR);
    $lineas = [];

    if (!empty($d['verificar'])) {
        $lineas[] = '  · ' . ($d['verificar_motivo'] ?? 'Sin motivo indicado');
    }
    // Huecos que el motor no rellena por falta de una fuente fiable
    foreach ($d['ccaa'] ?? [] as $codigo => $fila) {
        if (is_array($fila) && array_key_exists('tipo_general', $fila) && $fila['tipo_general'] === null) {
            $cand = implode('; ', array_map(static fn($c) => $c['tipo'] . ' % (' . $c['origen'] . ')', $fila['candidatos'] ?? []));
            $lineas[] = "  · ITP $codigo sin tipo: $cand";
        }
    }
    $sinCoef = 0;
    foreach ($d['municipios'] ?? [] as $m) {
        if (is_array($m) && array_key_exists('coeficiente', $m) && $m['coeficiente'] === null) {
            $sinCoef++;
        }
    }
    if ($sinCoef > 0) {
        $lineas[] = "  · $sinCoef municipios sin coeficiente IVTM (se aplica la tarifa mínima legal)";
    }
    foreach ($d['incrementos_autonomicos']['candidatos_sin_verificar'] ?? [] as $codigo => $texto) {
        $lineas[] = "  · IEDMT $codigo: $texto";
    }

    if ($lineas) {
        echo basename($fichero) . "\n" . implode("\n", $lineas) . "\n\n";
        $pendientes += count($lineas);
    }
}

echo $pendientes === 0 ? "Todo verificado.\n" : "$pendientes puntos pendientes de verificar.\n";
exit($pendientes === 0 ? 0 : 1);
