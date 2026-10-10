<?php

declare(strict_types=1);

/**
 * Herramienta de desarrollo: enseña qué datos saca el lector de anuncios de un fichero (HTML guardado desde el navegador
 * o texto copiado de la página), con la confianza y el origen de cada uno.
 *
 *   php scripts/analizar_anuncio.php anuncio.html            # tabla legible
 *   php scripts/analizar_anuncio.php anuncio.html --json     # el resultado completo, para escribir la prueba esperada
 *
 * Sirve para añadir páginas reales a tests/fixtures/anuncios/ y para ajustar data/diccionarios/etiquetas.json y
 * data/mobile_de_selectores.json cuando mobile.de cambie su página. No hace ninguna petición de red.
 */

require __DIR__ . '/../src/autoload.php';

use CalculadoraImportacion\Anuncio\Analizador;

$fichero = $argv[1] ?? null;
if ($fichero === null || !is_file($fichero)) {
    fwrite(STDERR, "Uso: php scripts/analizar_anuncio.php <fichero.html|fichero.txt> [--json]\n");
    exit(2);
}
$contenido = (string) file_get_contents($fichero);
$r = Analizador::crear(__DIR__ . '/../data')->analizarTexto($contenido, date('Y-m-d'));
if (in_array('--json', $argv, true)) {
    echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}
printf("Fuente: %s · Título: %s\n\n", $r['fuente'], $r['titulo'] ?? '—');
foreach ($r['campos'] as $campo => $f) {
    printf("  %-14s %-22s %.2f %-12s%s\n", $campo, $f['valor'], $f['confianza'], $f['origen'], $f['nota'] ? '  · ' . $f['nota'] : '');
}
echo "\nRevisar (confianza < 0,8): ", $r['dudosos'] ? implode(', ', $r['dudosos']) : '—', "\n";
echo 'Faltan: ', $r['faltan'] ? implode(', ', $r['faltan']) : '—', "\n";
foreach ($r['avisos'] as $a) {
    echo "Aviso: $a\n";
}
foreach ($r['desconocidos'] as $d) {
    echo "No reconocido: $d\n";
}
