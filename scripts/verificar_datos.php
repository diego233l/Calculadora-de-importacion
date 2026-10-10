<?php

declare(strict_types=1);

/**
 * Lista todo lo que sigue marcado VERIFICAR en data/fiscal/<año>/.
 *   php scripts/verificar_datos.php [año]
 * Recorre cada fichero y muestra, por ruta, los nodos con "verificar": true y el motivo o la fuente.
 * Sale con código 1 si queda algo pendiente (útil como recordatorio, no como bloqueo).
 */

$anio = $argv[1] ?? '2026';
$dir = dirname(__DIR__) . '/data/fiscal/' . basename($anio);
if (!is_dir($dir)) {
    fwrite(STDERR, "No existe $dir\n");
    exit(2);
}

/** @return string[] */
function pendientes(array $nodo, string $ruta): array
{
    $lineas = [];
    if (($nodo['verificar'] ?? false) === true) {
        $texto = $nodo['verificar_motivo'] ?? $nodo['nota'] ?? $nodo['fuente'] ?? 'sin descripción';
        if (array_key_exists('tipo_general', $nodo) && $nodo['tipo_general'] === null) {
            $cand = implode('; ', array_map(static fn($c) => $c['tipo'] . ' % (' . $c['origen'] . ')', $nodo['candidatos'] ?? []));
            $texto = 'SIN TIPO. Candidatos: ' . $cand;
        } elseif (array_key_exists('coeficiente', $nodo) && $nodo['coeficiente'] === null) {
            return [];   // los coeficientes IVTM vacíos se resumen aparte
        }
        $lineas[] = "  · $ruta: $texto";
    }
    foreach ($nodo as $clave => $hijo) {
        if (is_array($hijo)) {
            array_push($lineas, ...pendientes($hijo, $ruta === '' ? (string) $clave : $ruta . '.' . $clave));
        }
    }
    return $lineas;
}

$total = 0;
foreach (glob($dir . '/*.json') as $fichero) {
    $d = json_decode(file_get_contents($fichero), true, 512, JSON_THROW_ON_ERROR);
    $lineas = pendientes($d, '');
    $sinCoef = count(array_filter($d['municipios'] ?? [], static fn($m) => is_array($m) && array_key_exists('coeficiente', $m) && $m['coeficiente'] === null));
    if ($sinCoef > 0) {
        $lineas[] = "  · $sinCoef municipios sin coeficiente IVTM (se aplica la tarifa mínima legal y se avisa)";
    }
    if ($lineas) {
        echo basename($fichero) . "\n" . implode("\n", $lineas) . "\n\n";
        $total += count($lineas);
    }
}

echo $total === 0 ? "Todo verificado.\n" : "$total puntos pendientes de verificar.\n";
exit($total === 0 ? 0 : 1);
