<?php
declare(strict_types=1);

use function CalculadoraImportacion\Web\e;
use function CalculadoraImportacion\Web\eur;

/** @var array $calculos */
/** @var int $pedidos */
$nombre = static function (array $c): string {
    $t = trim(($c['entrada']['marca'] ?? '') . ' ' . ($c['entrada']['modelo'] ?? ''));
    return $t !== '' ? $t : 'Coche ' . substr($c['id'], 0, 4);
};
// Filas: todas las partidas que aparezcan en algún cálculo, en el orden del primero
$filas = [];
foreach ($calculos as $c) {
    foreach ($c['resultado']['partidas'] as $p) {
        $filas[$p['clave']] ??= $p['concepto'];
    }
}
$importe = static function (array $c, string $clave): ?array {
    foreach ($c['resultado']['partidas'] as $p) {
        if ($p['clave'] === $clave) {
            return $p;
        }
    }
    return null;
};
$totales = array_map(static fn(array $c): float => (float) $c['resultado']['total'], $calculos);
$mejor = $totales ? min($totales) : null;
$hayParciales = (bool) array_filter($calculos, static fn(array $c): bool => !$c['resultado']['completo']);
?>
<div class="contenedor">
  <nav class="migas" aria-label="Migas de pan"><a href="/">Inicio</a> › <a href="/historial">Historial</a> › Comparar</nav>
  <h1>Comparar coches</h1>
  <?php if (!$calculos): ?>
    <p>No hay cálculos que comparar. Ve a <a href="/historial">tu historial</a>, marca hasta 3 y pulsa «Comparar», o añade sus identificadores a la dirección: <code>/comparar?c=ID1,ID2</code>.</p>
  <?php else: ?>
    <?php if ($pedidos > count($calculos)): ?><p class="alerta alerta--atencion"><span class="alerta__icono" aria-hidden="true">⚠</span><span>Alguno de los cálculos no existe o ha caducado.</span></p><?php endif; ?>
    <div class="tabla-scroll">
      <table class="tabla-comparar">
        <caption class="solo-lectores">Comparación del coste de importación</caption>
        <thead>
          <tr><th scope="col">Concepto</th>
            <?php foreach ($calculos as $c): ?><th scope="col"><a href="/c/<?= e($c['id']) ?>"><?= e($nombre($c)) ?></a></th><?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($filas as $clave => $concepto): ?>
            <tr><th scope="row"><?= e($concepto) ?></th>
              <?php foreach ($calculos as $c): $p = $importe($c, $clave); ?>
                <td class="num"><?= $p === null ? '—' : ($p['estado'] === 'ok' ? eur((float) $p['importe']) : 'No calculable') ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr><th scope="row">Extra sobre el precio</th>
            <?php foreach ($calculos as $c): ?><td class="num"><?= eur((float) $c['resultado']['coste_sobre_precio']) ?></td><?php endforeach; ?>
          </tr>
          <tr><th scope="row">Total</th>
            <?php foreach ($calculos as $c): $t = (float) $c['resultado']['total']; ?>
              <td class="num<?= count($calculos) > 1 && $t === $mejor ? ' mejor' : '' ?>"><?= eur($t) ?><?= $c['resultado']['completo'] ? '' : ' *' ?><?= count($calculos) > 1 && $t === $mejor ? '<span class="solo-lectores"> (el más barato)</span>' : '' ?></td>
            <?php endforeach; ?>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php if ($hayParciales): ?><p class="pista">* Total parcial: falta alguna partida por calcular, así que no es comparable del todo.</p><?php endif; ?>
    <p class="pista"><strong>Estimación orientativa; confirma con tu gestoría o Hacienda.</strong></p>
    <p class="botones no-imprimir"><button class="boton boton--secundario" type="button" data-imprimir hidden>Imprimir o guardar en PDF</button></p>
  <?php endif; ?>
</div>
