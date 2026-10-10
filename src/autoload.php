<?php

declare(strict_types=1);

// Cargador de clases mínimo (sin Composer): CalculadoraImportacion\A\B -> src/A/B.php
spl_autoload_register(static function (string $clase): void {
    $prefijo = 'CalculadoraImportacion\\';
    if (strncmp($clase, $prefijo, strlen($prefijo)) !== 0) {
        return;
    }
    $ruta = __DIR__ . '/' . str_replace('\\', '/', substr($clase, strlen($prefijo))) . '.php';
    if (is_file($ruta)) {
        require $ruta;
    }
});
