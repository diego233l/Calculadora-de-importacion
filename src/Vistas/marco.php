<?php
declare(strict_types=1);

use CalculadoraImportacion\Web\Vista;
use function CalculadoraImportacion\Web\e;

/** @var string $contenido */
/** @var array $meta */
$activa = $meta['activa'] ?? '';
$enlaceNav = static function (string $ruta, string $texto, string $clave) use ($activa): string {
    $actual = $activa === $clave ? ' aria-current="page"' : '';
    return '<a href="' . e($ruta) . '"' . $actual . '>' . e($texto) . '</a>';
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
<meta name="theme-color" content="#f6f2e9" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#14181f" media="(prefers-color-scheme: dark)">
<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/app.css?v=<?= e(Vista::version('/assets/app.css')) ?>">
<script src="/assets/app.js?v=<?= e(Vista::version('/assets/app.js')) ?>" defer></script>
</head>
<body class="<?= e($meta['clase'] ?? '') ?>">
<a class="saltar" href="#contenido">Saltar al contenido</a>
<header class="cabecera">
  <div class="contenedor cabecera__fila">
    <a class="marca" href="/" aria-label="Calculadora de importación, ir al inicio">
      <svg class="marca__sello" viewBox="0 0 32 32" width="32" height="32" aria-hidden="true" focusable="false">
        <rect width="32" height="32" rx="8" fill="currentColor"/>
        <path d="M7 22 C 11 10, 17 22, 25 10" fill="none" stroke="#fffdf8" stroke-width="2.4" stroke-linecap="round" stroke-dasharray="1 4.2"/>
        <circle cx="7" cy="22" r="2.6" fill="#fffdf8"/><circle cx="25" cy="10" r="2.6" fill="#fffdf8"/>
      </svg>
      <span class="marca__texto">Calculadora<span> de importación</span></span>
    </a>
    <nav class="nav" aria-label="Principal">
      <?= $enlaceNav('/', 'Calcular', 'inicio') ?>
      <?= $enlaceNav('/guias', 'Guías', 'guias') ?>
      <?= $enlaceNav('/historial', 'Historial', 'historial') ?>
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
