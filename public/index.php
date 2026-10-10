<?php

declare(strict_types=1);

/**
 * Único script PHP de la web. nginx le pasa todo lo que no sea un fichero estático de public/.
 * Con el servidor de desarrollo de PHP (php -S) los ficheros estáticos se sirven solos.
 */

if (PHP_SAPI === 'cli-server') {
    $ruta = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $fichero = realpath(__DIR__ . '/' . ltrim(rawurldecode((string) $ruta), '/'));
    // Solo ficheros que estén dentro de public/ (nunca un ../ hacia el código) y que no sean este script
    if ($ruta !== '/' && $fichero !== false && $fichero !== __FILE__ && is_file($fichero) && str_starts_with($fichero, __DIR__ . '/')) {
        return false;
    }
}

require __DIR__ . '/../src/autoload.php';

use CalculadoraImportacion\Web\App;
use CalculadoraImportacion\Web\Config;
use CalculadoraImportacion\Web\Peticion;

mb_internal_encoding('UTF-8');
date_default_timezone_set(Config::ZONA_HORARIA);

$peticion = Peticion::desdeGlobales();
$respuesta = (new App(Config::dirVar()))->manejar($peticion);
$respuesta->enviar($peticion->metodo !== 'HEAD');
