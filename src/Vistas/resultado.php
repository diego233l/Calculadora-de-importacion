<?php
declare(strict_types=1);

use function CalculadoraImportacion\Web\e;
use function CalculadoraImportacion\Web\eur;
use function CalculadoraImportacion\Web\fecha;
use function CalculadoraImportacion\Web\num;
use function CalculadoraImportacion\Web\pct;
use function CalculadoraImportacion\Web\resumenErrores;

/** @var string $id */
/** @var array $entrada */
/** @var array $res */
/** @var string $titulo */
/** @var string $url */
/** @var string $csrf */
/** @var array $resumen */
/** @var string $municipio */
/** @var array $errores */
/** @var array $ajuste */
/** @var array $costesDefecto */
/** @var array $transporteDefecto */
/** @var string $resumenJson */
/** @var array $panelBoe */

$der = $res['derivados'];
$g = $resumen['grupos'];
$total = (float) $res['total'];
$faltan = array_values(array_filter($res['partidas'], static fn(array $p): bool => $p['estado'] !== 'ok'));
$combustibles = ['gasolina' => 'Gasolina', 'diesel' => 'Diésel', 'hibrido' => 'Híbrido', 'phev' => 'Híbrido enchufable', 'electrico' => 'Eléctrico', 'glp' => 'GLP', 'gnc' => 'GNC'];
$mat = preg_match('/^(\d{4})-(\d{2})/', (string) $entrada['fecha_matriculacion'], $mm) ? $mm[2] . '/' . $mm[1] : '';

// Formato del tipo aplicado en el desglose
$formatoTipo = static function (array $p): string {
    if ($p['tipo'] === null) {
        return '';
    }
    return match ($p['tipo_unidad']) {
        '%' => pct((float) $p['tipo']),
        'coef' => '× ' . rtrim(rtrim(number_format((float) $p['tipo'], 4, ',', '.'), '0'), ','),
        default => num((float) $p['tipo'], 2) . ' ' . $p['tipo_unidad'],
    };
};
$valorAjuste = static function (string $campo, mixed $actual) use ($ajuste): string {
    if (array_key_exists($campo, $ajuste)) {
        return (string) $ajuste[$campo];
    }
    return $actual === null ? '' : rtrim(rtrim(number_format((float) $actual, 2, ',', ''), '0'), ',');
};
$etiquetasAjuste = ['t_modo' => 'Modo de transporte'];
foreach ($costesDefecto as $c) {
    $etiquetasAjuste['cf_' . $c['clave']] = $c['concepto'];
}
$alertas = $res['avisos'];
usort($alertas, static fn(array $a, array $b): int => [$a['nivel'] === 'atencion' ? 0 : 1] <=> [$b['nivel'] === 'atencion' ? 0 : 1]);
?>
<div class="contenedor estrecho">
  <nav class="migas" aria-label="Migas de pan"><a href="/">Inicio</a> › Resultado</nav>
  <h1 class="titulo-coche"><?= e($titulo) ?></h1>
  <p class="meta-coche">
    Matriculado en <?= e($mat) ?> · <span class="num"><?= num((float) $entrada['km']) ?> km</span> · <?= e($combustibles[$entrada['combustible']] ?? $entrada['combustible']) ?>
    · vendedor <?= e($entrada['vendedor']) ?> · destino <?= e($municipio) ?>
  </p>

  <?php if ($errores): ?>
    <?= resumenErrores($errores, $etiquetasAjuste + ['general' => 'Ajustes']) ?>
  <?php endif; ?>

  <?php if (!$res['completo']): ?>
    <div class="alerta alerta--atencion" role="status">
      <span class="alerta__icono" aria-hidden="true">⚠</span>
      <p><strong>Total parcial.</strong> No hemos podido calcular <?= count($faltan) ?> <?= count($faltan) === 1 ? 'partida' : 'partidas' ?>
        (<?= e(implode(', ', array_map(static fn(array $p): string => mb_strtolower($p['concepto']), $faltan))) ?>)
        y no están sumadas. Abre cada una para ver qué falta. <a href="/c/<?= e($id) ?>/editar">Completa los datos</a> y se recalcula.</p>
    </div>
  <?php endif; ?>

  <section class="ticket" aria-labelledby="total-titulo">
    <div class="ticket__cabecera">
      <p class="ticket__etiqueta" id="total-titulo"><?= $res['completo'] ? 'Coste total de importarlo' : 'Coste parcial (incompleto)' ?></p>
      <p class="total<?= $res['completo'] ? '' : ' total--parcial' ?>"><?= eur($total) ?></p>
      <p class="ticket__frase">
        Precio del anuncio <strong class="num"><?= eur((float) $res['precio_anuncio']) ?></strong> +
        <strong class="num"><?= eur((float) $resumen['extra']) ?></strong> de impuestos, tasas y transporte
        (<strong>+<?= num((float) $resumen['porcentaje'], 1) ?> %</strong> sobre el precio).
      </p>
      <?php if ($total > 0): ?>
        <div class="barra" aria-hidden="true">
          <svg viewBox="0 0 100 10" preserveAspectRatio="none" focusable="false">
            <?php $x = 0.0; foreach (['precio', 'impuestos', 'gestion', 'transporte'] as $clave): $w = $g[$clave] / $total * 100; if ($w <= 0) { continue; } ?>
              <rect class="seg--<?= e($clave) ?>" x="<?= number_format($x, 3, '.', '') ?>" y="0" width="<?= number_format($w, 3, '.', '') ?>" height="10"/>
            <?php $x += $w; endforeach; ?>
          </svg>
        </div>
      <?php endif; ?>
      <ul class="leyenda">
        <li><i class="i-precio"></i>Precio del coche <span class="importe num"><?= eur($g['precio']) ?></span></li>
        <li><i class="i-impuestos"></i>Impuestos <span class="importe num"><?= eur($g['impuestos']) ?></span></li>
        <li><i class="i-gestion"></i>Tasas y gestión <span class="importe num"><?= eur($g['gestion']) ?></span></li>
        <li><i class="i-transporte"></i>Transporte <span class="importe num"><?= eur($g['transporte']) ?></span></li>
      </ul>
    </div>

    <ol class="desglose" aria-label="Desglose del coste">
      <?php foreach ($res['partidas'] as $p): $ok = $p['estado'] === 'ok'; ?>
        <li>
          <details class="partida"<?= $ok ? '' : ' open' ?>>
            <summary>
              <span class="partida__nombre"><?= e($p['concepto']) ?>
                <?php if ($p['verificar'] || $p['estimacion']): ?>
                  <span class="insignias">
                    <?php if ($p['verificar']): ?><span class="insignia insignia--verificar" title="Esta cifra se apoya en un dato que aún no hemos contrastado con la norma">Por verificar</span><?php endif; ?>
                    <?php if ($p['estimacion']): ?><span class="insignia insignia--estimacion" title="Valor por defecto que puedes cambiar">Estimación</span><?php endif; ?>
                  </span>
                <?php endif; ?>
              </span>
              <span class="partida__relleno" aria-hidden="true"></span>
              <?php if ($ok): ?>
                <span class="partida__importe"><?= eur((float) $p['importe']) ?></span>
              <?php else: ?>
                <span class="partida__importe partida__importe--falta">No calculable</span>
              <?php endif; ?>
            </summary>
            <div class="partida__cuerpo">
              <p class="partida__explicacion"><?= e($p['explicacion']) ?></p>
              <dl>
                <?php if ($ok && $p['base'] !== null): ?><dt>Base</dt><dd class="num"><?= eur((float) $p['base']) ?></dd><?php endif; ?>
                <?php if ($ok && $p['tipo'] !== null): ?><dt>Tipo aplicado</dt><dd class="num"><?= e($formatoTipo($p)) ?></dd><?php endif; ?>
                <dt>Fuente</dt>
                <dd><?= e($p['fuente']) ?><?php if (!empty($p['url'])): ?> · <a href="<?= e($p['url']) ?>" rel="noopener noreferrer" target="_blank">Ver la norma<span class="solo-lectores"> (se abre en otra pestaña)</span></a><?php endif; ?></dd>
              </dl>
            </div>
          </details>
        </li>
      <?php endforeach; ?>
    </ol>

    <div class="ticket__pie">
      <div class="fila-total"><span><?= $res['completo'] ? 'Total' : 'Total parcial' ?></span><span class="num"><?= eur($total) ?></span></div>
      <p class="pista">Calculado el <?= e(fecha((string) ($res['meta']['calculado'] ?? ''))) ?> con las tablas fiscales de <?= e($res['meta']['anio_tablas'] ?? '') ?>. <strong>Estimación orientativa; confirma con tu gestoría o Hacienda.</strong></p>
    </div>
  </section>

  <?php if ($alertas): ?>
    <h2 class="solo-lectores">Avisos</h2>
    <ul class="alertas">
      <?php foreach ($alertas as $a): if ($a['codigo'] === 'estimacion_orientativa') { continue; } ?>
        <li class="alerta alerta--<?= $a['nivel'] === 'atencion' ? 'atencion' : 'info' ?>">
          <span class="alerta__icono" aria-hidden="true"><?= $a['nivel'] === 'atencion' ? '⚠' : 'ℹ' ?></span>
          <p><?= e($a['texto']) ?></p>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?= \CalculadoraImportacion\Web\Vista::renderizar('boe_panel', ['id' => $id, 'csrf' => $csrf, 'panel' => $panelBoe, 'totalActual' => $total]) ?>

  <div class="acciones botones">
    <a class="boton" href="/c/<?= e($id) ?>/editar">Editar los datos</a>
    <button class="boton boton--secundario" type="button" data-imprimir hidden>Imprimir o guardar en PDF</button>
    <button class="boton boton--secundario" type="button" data-guardar-historial="<?= e($resumenJson) ?>" hidden>Guardar en mi historial</button>
    <a class="boton boton--secundario" href="/comparar?c=<?= e($id) ?>">Comparar con otros</a>
  </div>
  <p class="estado-copia no-imprimir" role="status" aria-live="polite" data-estado-historial></p>

  <details class="panel no-imprimir" open>
    <summary>Compartir este cálculo</summary>
    <div class="copiable">
      <code id="enlace-compartir"><?= e($url) ?></code>
      <button class="boton boton--secundario boton--pequeno" type="button" data-copiar="#enlace-compartir" hidden>Copiar enlace</button>
    </div>
    <p class="estado-copia" role="status" aria-live="polite" data-estado-copia></p>
    <p class="pista">Cualquiera con este enlace verá el cálculo tal como está ahora, sin tus datos personales. Se conserva un año.</p>
  </details>

  <details class="panel no-imprimir">
    <summary>Datos que hemos deducido</summary>
    <dl class="datos-lista">
      <dt>¿Nuevo o usado?</dt>
      <dd><?= $der['nuevo'] ? 'Nuevo a efectos de IVA (' . ($der['nuevo_por_meses'] ? 'menos de 6 meses desde la matriculación' : '') . ($der['nuevo_por_meses'] && $der['nuevo_por_km'] ? ' y ' : '') . ($der['nuevo_por_km'] ? 'no más de 6.000 km' : '') . ')' : 'Usado: más de 6 meses y más de 6.000 km' ?></dd>
      <dt>Antigüedad</dt><dd><?= e($der['antiguedad_anios']) ?> <?= (int) $der['antiguedad_anios'] === 1 ? 'año' : 'años' ?> completos</dd>
      <dt>Depreciación del BOE</dt><dd class="num"><?= pct((float) $der['coeficiente_depreciacion'] * 100) ?></dd>
      <dt>Valor venal</dt><dd class="num"><?= $der['valor_venal'] !== null ? eur((float) $der['valor_venal']) : 'No calculable (falta el precio medio del BOE)' ?></dd>
      <dt>Caballos fiscales</dt><dd><?= $der['cvf'] !== null ? e(num((float) $der['cvf'], 2)) . ' CVF. ' . e($der['cvf_explicacion']) : e($der['cvf_explicacion']) ?></dd>
      <?php if ($der['tipo_iedmt'] !== null): ?><dt>Tipo de IEDMT</dt><dd class="num"><?= pct((float) $der['tipo_iedmt']) ?></dd><?php endif; ?>
      <?php if ($der['distancia'] !== null): ?>
        <dt>Distancia estimada</dt>
        <dd class="num"><?= num((float) $der['distancia']['carretera_km'], 0) ?> km por carretera (<?= num((float) $der['distancia']['recta_km'], 0) ?> km en línea recta) · unas <?= num((float) $der['distancia']['horas'], 1) ?> h</dd>
      <?php endif; ?>
    </dl>
  </details>

  <details class="panel no-imprimir"<?= $errores ? ' open' : '' ?>>
    <summary>Ajustar costes y transporte</summary>
    <p class="pista">Cambia cualquier importe por el que te hayan presupuestado. Los campos vacíos usan nuestra estimación.</p>
    <form class="formulario" method="post" action="/c/<?= e($id) ?>/costes" novalidate>
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <fieldset>
        <legend>Tasas y gestión (€)</legend>
        <div class="rejilla rejilla--2">
          <?php foreach ($costesDefecto as $c): $campo = 'cf_' . $c['clave']; ?>
            <div class="campo">
              <label for="a-<?= e($c['clave']) ?>"><?= e($c['concepto']) ?></label>
              <input type="text" id="a-<?= e($c['clave']) ?>" name="<?= e($campo) ?>" inputmode="decimal" autocomplete="off"
                     value="<?= e($valorAjuste($campo, $entrada['costes_fijos'][$c['clave']] ?? null)) ?>"
                     placeholder="<?= e(number_format((float) $c['importe'], 2, ',', '')) ?>"<?= isset($errores[$campo]) ? ' aria-invalid="true" aria-describedby="ea-' . e($c['clave']) . '"' : '' ?>>
              <?php if (isset($errores[$campo])): ?><p class="error-campo" id="ea-<?= e($c['clave']) ?>"><?= e($errores[$campo]) ?></p><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <fieldset>
        <legend>Transporte</legend>
        <div class="formulario">
          <div class="campo">
            <label for="a-modo">Cómo traerás el coche</label>
            <select id="a-modo" name="t_modo">
              <?php $modo = $valorAjuste('t_modo', $entrada['transporte']['modo'] ?? ''); ?>
              <option value=""<?= $modo === '' ? ' selected' : '' ?>>Yendo a recogerlo (por defecto)</option>
              <option value="conduciendo"<?= $modo === 'conduciendo' ? ' selected' : '' ?>>Yendo a recogerlo y conduciéndolo</option>
              <option value="camion"<?= $modo === 'camion' ? ' selected' : '' ?>>En camión portavehículos</option>
            </select>
          </div>
          <div class="rejilla rejilla--3">
            <?php foreach ([['t_peajes', 'peajes', 'Peajes (€)'], ['t_billete_vuelta', 'billete_vuelta', 'Billete de vuelta (€)'], ['t_alojamiento', 'alojamiento', 'Alojamiento (€)'], ['t_precio_km', 'precio_por_km', 'Camión: precio por km (€)']] as [$campo, $clave, $etiq]): ?>
              <div class="campo">
                <label for="a-<?= e($campo) ?>"><?= e($etiq) ?></label>
                <input type="text" id="a-<?= e($campo) ?>" name="<?= e($campo) ?>" inputmode="decimal" autocomplete="off"
                       value="<?= e($valorAjuste($campo, $entrada['transporte'][$clave] ?? null)) ?>"
                       placeholder="<?= e($clave === 'precio_por_km' ? number_format((float) $transporteDefecto['camion']['precio_por_km'], 2, ',', '') : '0') ?>"<?= isset($errores[$campo]) ? ' aria-invalid="true" aria-describedby="ea-' . e($campo) . '"' : '' ?>>
                <?php if (isset($errores[$campo])): ?><p class="error-campo" id="ea-<?= e($campo) ?>"><?= e($errores[$campo]) ?></p><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
          <details>
            <summary>Parámetros avanzados de la estimación</summary>
            <div class="rejilla rejilla--2">
              <?php
              $comb = (string) $entrada['combustible'];
              $defConsumo = $transporteDefecto['conduciendo']['consumo_por_100km'][$comb] ?? '';
              $defPrecio = $transporteDefecto['conduciendo']['precio_por_unidad'][$comb] ?? '';
              $unidad = $transporteDefecto['conduciendo']['unidad_consumo'][$comb] ?? '';
              foreach ([
                  ['t_consumo', 'consumo_por_100km', 'Consumo (' . $unidad . ' por 100 km)', $defConsumo],
                  ['t_precio_unidad', 'precio_por_unidad', 'Precio del combustible (€ por ' . $unidad . ')', $defPrecio],
                  ['t_factor', 'factor_carretera', 'Factor de carretera (sobre la línea recta)', $transporteDefecto['factor_carretera']],
                  ['t_velocidad', 'velocidad_kmh', 'Velocidad media (km/h)', $transporteDefecto['velocidad_media_kmh']],
              ] as [$campo, $clave, $etiq, $def]): ?>
                <div class="campo">
                  <label for="a-<?= e($campo) ?>"><?= e($etiq) ?></label>
                  <input type="text" id="a-<?= e($campo) ?>" name="<?= e($campo) ?>" inputmode="decimal" autocomplete="off"
                         value="<?= e($valorAjuste($campo, $entrada['transporte'][$clave] ?? null)) ?>"
                         placeholder="<?= e(number_format((float) $def, 2, ',', '')) ?>"<?= isset($errores[$campo]) ? ' aria-invalid="true" aria-describedby="ea-' . e($campo) . '"' : '' ?>>
                  <?php if (isset($errores[$campo])): ?><p class="error-campo" id="ea-<?= e($campo) ?>"><?= e($errores[$campo]) ?></p><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </details>
        </div>
      </fieldset>
      <div class="botones"><button class="boton" type="submit">Recalcular</button></div>
    </form>
  </details>

  <section class="solo-impresion" aria-labelledby="impresion-titulo">
    <h2 id="impresion-titulo">Desglose del cálculo</h2>
    <table>
      <thead><tr><th>Concepto</th><th>Importe</th><th>Cómo se ha calculado</th></tr></thead>
      <tbody>
        <?php foreach ($res['partidas'] as $p): ?>
          <tr>
            <td><?= e($p['concepto']) ?><?= $p['estimacion'] ? ' (estimación)' : '' ?><?= $p['verificar'] ? ' (por verificar)' : '' ?></td>
            <td class="num"><?= $p['estado'] === 'ok' ? eur((float) $p['importe']) : 'No calculable' ?></td>
            <td><?= e($p['explicacion']) ?><div class="sub">Fuente: <?= e($p['fuente']) ?></div></td>
          </tr>
        <?php endforeach; ?>
        <tr><th>Total</th><th class="num"><?= eur($total) ?></th><th>Estimación orientativa; confirma con tu gestoría o Hacienda.</th></tr>
      </tbody>
    </table>
    <p class="sub">Enlace del cálculo: <?= e($url) ?> · Calculado el <?= e(fecha((string) ($res['meta']['calculado'] ?? ''))) ?></p>
  </section>
</div>
