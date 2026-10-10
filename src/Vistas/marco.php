<?php
declare(strict_types=1);

use CalculadoraImportacion\Web\Vista;
use function CalculadoraImportacion\Web\e;
use function CalculadoraImportacion\Web\icono;

/** @var string $contenido */
/** @var array $meta */
$activa = $meta['activa'] ?? '';
$enlaceNav = static function (string $ruta, string $texto, string $clave, string $icono) use ($activa): string {
    $actual = $activa === $clave ? ' aria-current="page"' : '';
    return '<a href="' . e($ruta) . '"' . $actual . '>' . icono($icono) . '<span>' . e($texto) . '</span></a>';
};
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($meta['titulo']) ?></title>
<?php if (!empty($meta['descripcion'])): ?>
<meta name="description" content="<?= e($meta['descripcion']) ?>">
<?php endif; ?>
<?php if (!empty($meta['robots'])): ?>
<meta name="robots" content="<?= e($meta['robots']) ?>">
<?php endif; ?>
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#0d2841">
<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
<link rel="preload" href="/assets/fonts/inter-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/assets/app.css?v=<?= e(Vista::version('/assets/app.css')) ?>">
<script src="/assets/app.js?v=<?= e(Vista::version('/assets/app.js')) ?>" defer></script>
</head>
<body class="<?= e($meta['clase'] ?? '') ?>">
<?= Vista::renderizar('sprite') ?>
<a class="saltar" href="#contenido">Saltar al contenido</a>
<header class="cabecera">
  <div class="contenedor cabecera__fila">
    <a class="marca" href="/" aria-label="Calculadora de importación, ir al inicio">
      <span class="marca__sello"><?= icono('coche') ?></span>
      <span class="marca__texto">Calculadora<span> de importación</span></span>
    </a>
    <nav class="nav" aria-label="Principal">
      <?= $enlaceNav('/', 'Calculadora', 'inicio', 'calculadora') ?>
      <?= $enlaceNav('/guias', 'Guías', 'guias', 'libro') ?>
      <?= $enlaceNav('/historial', 'Historial', 'historial', 'historial') ?>
    </nav>
  </div>
</header>

<main id="contenido" tabindex="-1">
<?= $contenido ?>
</main>

<footer class="pie">
  <div class="contenedor">
    <p class="pie__aviso"><strong>Estimación orientativa; confirma con tu gestoría o Hacienda.</strong>
      Esta web no es asesoramiento fiscal ni está vinculada a mobile.de ni a ninguna administración.</p>
    <nav class="pie__enlaces" aria-label="Información legal y recursos">
      <a href="/aviso-legal">Aviso legal</a>
      <a href="/privacidad">Privacidad</a>
      <a href="/guias">Guías</a>
    </nav>
    <p class="pie__creditos">Coordenadas: <a href="https://www.geonames.org/" rel="noopener noreferrer">GeoNames</a> (CC BY 4.0) y municipios del INE.
      Normativa consultada en el <a href="https://www.boe.es/" rel="noopener noreferrer">BOE</a>.</p>
  </div>
</footer>
</body>
</html>
