<?php
declare(strict_types=1);

use function CalculadoraImportacion\Web\e;

/** @var array $guias */
$resumen = [
    'importar-coche-de-alemania' => 'Del anuncio a la matrícula: qué papeles pedir, qué impuestos se pagan y en qué orden.',
    'impuesto-de-matriculacion' => 'Cómo se calcula el IEDMT: tipos por emisiones de CO₂, base imponible y el descuento para coches ya matriculados fuera.',
    'itp' => 'Cuándo se paga, sobre qué valor y a qué tipo según la comunidad autónoma.',
];
?>
<div class="contenedor seccion">
  <h1>Guías para importar un coche</h1>
  <p class="suave estrecho">Explicaciones prácticas, con la norma al lado de cada cifra.</p>
  <div class="tarjetas-guias">
    <?php foreach ($guias as $slug => [$titulo]): ?>
      <a href="/guias/<?= e($slug) ?>"><article class="tarjeta">
        <h2><?= e($titulo) ?></h2>
        <p><?= e($resumen[$slug] ?? '') ?></p>
      </article></a>
    <?php endforeach; ?>
  </div>
</div>
