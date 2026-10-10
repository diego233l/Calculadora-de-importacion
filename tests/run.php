<?php

declare(strict_types=1);

/**
 * Ejecuta todas las pruebas: php tests/run.php
 * Busca tests/**\/*Test.php, ejecuta cada prueba registrada y sale con código 1 si alguna falla.
 */

error_reporting(E_ALL);
set_error_handler(static function (int $no, string $texto, string $fichero, int $linea): bool {
    throw new \ErrorException($texto, 0, $no, $fichero, $linea);
});

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/Prueba.php';
require __DIR__ . '/ayuda.php';

$filtro = $argv[1] ?? null;

$ficheros = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (str_ends_with($f->getFilename(), 'Test.php')) {
        $ficheros[] = $f->getPathname();
    }
}
sort($ficheros);
foreach ($ficheros as $f) {
    require $f;
}

$ok = 0;
$fallos = [];
foreach (Prueba::$registro as [$nombre, $cuerpo]) {
    if ($filtro !== null && stripos($nombre, $filtro) === false) {
        continue;
    }
    try {
        $cuerpo();
        $ok++;
        echo "  ok    $nombre\n";
    } catch (\Throwable $e) {
        $fallos[] = $nombre;
        echo "  FALLO $nombre\n        " . str_replace("\n", "\n        ", $e->getMessage()) . "\n";
        echo '        en ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
}

echo "\n" . $ok . ' correctas, ' . count($fallos) . " con fallo\n";
exit($fallos ? 1 : 0);
