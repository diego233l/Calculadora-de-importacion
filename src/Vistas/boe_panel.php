<?php
declare(strict_types=1);

use function CalculadoraImportacion\Web\e;
use function CalculadoraImportacion\Web\eur;
use function CalculadoraImportacion\Web\num;

/** @var string $id */
/** @var string $csrf */
/** @var array $panel */
/** @var float $totalActual */

$actual = $panel['actual'];
$filas = $panel['filas'];
$periodo = static function (array $f): string {
    if ($f['inicio'] === null) {
        return 'sin periodo comercial';
    }
    return $f['fin'] === null ? 'desde ' . $f['inicio'] : ($f['inicio'] === $f['fin'] ? (string) $f['inicio'] : $f['inicio'] . '–' . $f['fin']);
};
$tecnica = static function (array $f): string {
    $p = [];
    $p[] = $f['cc'] !== null ? num((float) $f['cc']) . ' cm³' : 'sin cilindrada';
    $p[] = num((float) $f['kw'], $f['kw'] == floor($f['kw']) ? 0 : 1) . ' kW (' . num((float) $f['cv']) . ' CV)';
    return implode(' · ', $p);
};
$confianza = ['alta' => 'Coincidencia alta', 'media' => 'Coincidencia media', 'baja' => 'Coincidencia baja'];
$origenes = ['auto' => 'Elegida por nosotros', 'usuario' => 'Elegida por ti', 'manual' => 'Precio escrito por ti'];
$enUso = array_values(array_filter($filas, static fn(array $f): bool => $f['en_uso']));
?>
<details class="panel panel-boe no-imprimir" id="fila-boe"<?= ($actual === null || ($actual['origen'] ?? '') === 'auto') ? ' open' : '' ?>>
  <summary>Fila del BOE de la que sale el valor venal</summary>
  <?php if (!$panel['disponible']): ?>
    <p class="pista">Esta instalación no incluye la base de precios del BOE de <?= e($panel['anio']) ?>. Escribe el precio medio a mano al editar los datos.</p>
  <?php else: ?>
    <?php if ($actual !== null): ?>
      <div class="boe-actual">
        <p class="boe-actual__nombre"><strong><?= e($actual['modelo_tipo'] ?? 'Precio medio del BOE escrito por ti') ?></strong>
          <?php if (!empty($actual['confianza'])): ?><span class="insignia insignia--<?= $actual['confianza'] === 'alta' ? 'estimacion' : 'verificar' ?>"><?= e($confianza[$actual['confianza']] ?? '') ?> · <?= e((int) round((float) $actual['puntuacion'] * 100)) ?> %</span><?php endif; ?>
        </p>
        <?php if (isset($actual['marca'])): ?>
          <p class="boe-actual__datos"><?= e($actual['marca']) ?> · <?= e($periodo($actual)) ?> · <?= e($tecnica($actual)) ?></p>
        <?php endif; ?>
        <p class="boe-actual__precio">Precio medio de la Orden: <strong class="num"><?= eur((float) $actual['valor']) ?></strong>
          <span class="pista">· <?= e($origenes[$actual['origen'] ?? 'auto'] ?? '') ?></span></p>
      </div>
    <?php else: ?>
      <p>No hemos encontrado tu coche en la Orden del BOE de <?= e($panel['anio']) ?>, así que no se pueden calcular el valor venal, el impuesto de matriculación ni el ITP.
        <a href="/c/<?= e($id) ?>/editar">Edita los datos</a> (marca, modelo y versión tal como los escribe el BOE) o escribe a mano el precio medio de tu modelo.</p>
    <?php endif; ?>

    <?php if ($filas): ?>
      <form class="formulario boe-form" method="post" action="/c/<?= e($id) ?>/boe">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <fieldset>
          <legend><?= $actual !== null ? 'Otras filas parecidas del BOE' : 'Filas del BOE más parecidas a tu coche' ?></legend>
          <p class="pista">Los precios del BOE cambian con la versión exacta del modelo. Elige la fila que coincida con tu coche y recalculamos todo con ella.</p>
          <ul class="boe-filas">
            <?php foreach ($filas as $i => $f): $fila = $f['fila']; ?>
              <li class="boe-fila">
                <input type="radio" name="fila" id="boe-<?= e($fila['id']) ?>" value="<?= e($fila['id']) ?>"<?= $f['en_uso'] ? ' checked' : '' ?>>
                <label for="boe-<?= e($fila['id']) ?>">
                  <span class="boe-fila__nombre"><?= e($fila['modelo_tipo']) ?><?= $f['en_uso'] ? ' <span class="insignia insignia--estimacion">En uso</span>' : '' ?></span>
                  <span class="boe-fila__datos"><?= e($periodo($fila)) ?> · <?= e($tecnica($fila)) ?> · <?= e(['gasolina' => 'gasolina', 'diesel' => 'diésel', 'electrico' => 'eléctrico', 'hibrido' => 'híbrido', 'phev' => 'híbrido enchufable', 'glp' => 'GLP', 'etanol' => 'etanol', 'hidrogeno' => 'hidrógeno'][$fila['combustible']] ?? $fila['combustible']) ?></span>
                  <span class="boe-fila__precio">Precio medio <strong class="num"><?= eur((float) $fila['valor']) ?></strong>
                    <?php if ($f['total'] !== null): ?> · Total con esta fila <strong class="num"><?= eur($f['total']) ?></strong><?php
                        $dif = $f['total'] - $totalActual;
                        if (!$f['en_uso'] && abs($dif) >= 0.5): ?> <span class="pista">(<?= $dif > 0 ? '+' : '−' ?><?= e(num(abs($dif), 0)) ?> €)</span><?php endif; ?>
                    <?php endif; ?>
                  </span>
                  <span class="boe-fila__motivo pista"><?= e($confianza[$f['confianza']]) ?> (<?= e((int) round($f['puntuacion'] * 100)) ?> %): <?= e(implode(', ', $f['motivos'])) ?>.</span>
                </label>
              </li>
            <?php endforeach; ?>
          </ul>
        </fieldset>
        <div class="botones"><button class="boton boton--secundario" type="submit">Usar la fila elegida</button></div>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</details>
