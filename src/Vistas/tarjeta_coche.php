<?php
declare(strict_types=1);

use function CalculadoraImportacion\Web\e;
use function CalculadoraImportacion\Web\eur;
use function CalculadoraImportacion\Web\icono;

/** @var array $coche resultado de TarjetaCoche::desdeValores() */
?>
<section class="coche" aria-label="Resumen del coche">
  <h2 class="coche__titulo"><?= icono('coche') ?><span><?= e($coche['titulo']) ?></span></h2>
  <?php if ($coche['version'] !== ''): ?><p class="coche__version"><?= e($coche['version']) ?></p><?php endif; ?>
  <?php if ($coche['precio'] !== null): ?><p class="coche__precio"><?= eur($coche['precio']) ?></p><?php endif; ?>
  <ul class="datos-coche">
    <?php foreach ($coche['datos'] as $d): ?>
      <li class="dato<?= $d['valor'] === null ? ' dato--vacio' : '' ?><?= $d['revisar'] ? ' dato--revisar' : '' ?>">
        <?= icono($d['icono']) ?>
        <div>
          <span class="dato__etiqueta"><?= e($d['etiqueta']) ?></span>
          <span class="dato__valor"><?php if ($d['valor'] === null): ?>Falta este dato<?php else: ?><?= e($d['valor']) ?><?php endif; ?><?php if ($d['revisar'] && $d['valor'] !== null): ?> <span class="solo-lectores">(conviene revisarlo)</span><?php endif; ?></span>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
