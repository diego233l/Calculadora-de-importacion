<?php
declare(strict_types=1);

use function CalculadoraImportacion\Web\e;

/** @var int $estado */
/** @var string $titulo */
/** @var string $mensaje */
?>
<div class="contenedor estrecho seccion">
  <p class="suave num">Error <?= e($estado) ?></p>
  <h1><?= e($titulo) ?></h1>
  <p><?= e($mensaje) ?></p>
  <p class="botones"><a class="boton" href="/">Volver al inicio</a><a class="boton boton--secundario" href="/guias">Ver las guías</a></p>
</div>
