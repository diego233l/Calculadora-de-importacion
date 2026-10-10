<?php
declare(strict_types=1);

use function CalculadoraImportacion\Web\e;
use function CalculadoraImportacion\Web\eur;
use function CalculadoraImportacion\Web\fecha;
use function CalculadoraImportacion\Web\icono;
use function CalculadoraImportacion\Web\num;
use function CalculadoraImportacion\Web\pct;
use function CalculadoraImportacion\Web\resumenErrores;
use CalculadoraImportacion\Web\Vista;

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
/** @var array $coche */
/** @var array<string, string> $ajustesActuales */

$der = $res['derivados'];
$total = (float) $res['total'];
$faltan = array_values(array_filter($res['partidas'], static fn(array $p): bool => $p['estado'] !== 'ok'));
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

// Avisos: los que piden atención se ven siempre; las notas informativas, plegadas
$alertasAtencion = array_values(array_filter($res['avisos'], static fn(array $a): bool => $a['nivel'] === 'atencion' && $a['codigo'] !== 'estimacion_orientativa'));
$notas = array_values(array_filter($res['avisos'], static fn(array $a): bool => $a['nivel'] !== 'atencion' && $a['codigo'] !== 'estimacion_orientativa'));

// Las partidas, agrupadas en tarjetas: precio, matriculación, ITP/IVA, circulación, trayecto y tasas
$categorias = [
    'precio' => ['icono' => 'etiqueta', 'titulo' => 'Precio del vehículo', 'partidas' => []],
    'iedmt' => ['icono' => 'documento', 'titulo' => 'Impuesto de matriculación', 'partidas' => []],
    'itp' => ['icono' => 'documento', 'titulo' => 'ITP o IVA', 'partidas' => []],
    'ivtm' => ['icono' => 'edificio', 'titulo' => 'IVTM (impuesto de circulación, anual)', 'partidas' => []],
    'trayecto' => ['icono' => 'ruta', 'titulo' => 'Coste de trayecto', 'partidas' => []],
    'fijos' => ['icono' => 'ajustes', 'titulo' => 'Tasas y gestión', 'partidas' => []],
];
$destinoCategoria = ['precio' => 'precio', 'iedmt' => 'iedmt', 'itp_iva' => 'itp', 'ivtm' => 'ivtm', 'transporte' => 'trayecto', 'otros_gastos_viaje' => 'trayecto'];
foreach ($res['partidas'] as $p) {
    $categorias[$destinoCategoria[$p['clave']] ?? 'fijos']['partidas'][] = $p;
}
foreach ($categorias as $clave => $c) {
    if ($c['partidas'] === []) {
        unset($categorias[$clave]);
    }
}

/** Cuerpo de una partida: la explicación en claro, las cifras clave y la fuente con su enlace. */
$cuerpoPartida = static function (array $p, bool $conInsignias = true) use ($formatoTipo): string {
    $ok = $p['estado'] === 'ok';
    $h = '';
    if ($conInsignias && ($p['verificar'] || $p['estimacion'])) {
        $h .= '<p>';
        if ($p['verificar']) {
            $h .= '<span class="insignia insignia--verificar" title="Esta cifra se apoya en un dato que aún no hemos contrastado con la norma">Por verificar</span> ';
        }
        if ($p['estimacion']) {
            $h .= '<span class="insignia insignia--estimacion" title="Valor por defecto que puedes cambiar">Estimación</span>';
        }
        $h .= '</p>';
    }
    $h .= '<p class="explicacion">' . e($p['explicacion']) . '</p>';
    if ($ok && ($p['base'] !== null || $p['tipo'] !== null)) {
        $h .= '<dl class="filas">';
        if ($p['base'] !== null) {
            $h .= '<div><dt>Base</dt><dd class="num">' . eur((float) $p['base']) . '</dd></div>';
        }
        if ($p['tipo'] !== null) {
            $h .= '<div><dt>Tipo aplicado</dt><dd class="num">' . e($formatoTipo($p)) . '</dd></div>';
        }
        $h .= '</dl>';
    }
    $h .= '<p class="fuente">Fuente: ' . e($p['fuente']);
    if (!empty($p['url'])) {
        $h .= ' · <a href="' . e($p['url']) . '" rel="noopener noreferrer" target="_blank">Ver la norma<span class="solo-lectores"> (se abre en otra pestaña)</span></a>';
    }
    return $h . '</p>';
};

$actual = $panelBoe['actual'] ?? null;
$origenesBoe = ['auto' => 'AUTO', 'usuario' => 'ELEGIDO', 'manual' => 'MANUAL'];
// Una fila elegida por nosotros sin coincidencia alta no es «el modelo identificado»: es lo más parecido que hay
$aproximado = $actual !== null && ($actual['origen'] ?? '') === 'auto' && ($actual['confianza'] ?? 'alta') !== 'alta';
$motivoAproximado = '';
if ($aproximado) {
    foreach ($panelBoe['filas'] as $f) {
        foreach ($f['en_uso'] ? $f['motivos'] : [] as $motivo) {
            if (str_starts_with($motivo, 'la versión')) {
                $motivoAproximado = ucfirst($motivo) . '.';
            }
        }
    }
}
$gestoriaExcluida = isset($entrada['costes_fijos']['gestoria']) && (float) $entrada['costes_fijos']['gestoria'] === 0.0;
$gestoriaDefecto = 0.0;
foreach ($costesDefecto as $c) {
    if ($c['clave'] === 'gestoria') {
        $gestoriaDefecto = (float) $c['importe'];
    }
}
?>
<div class="contenedor estrecho">
  <h1 class="solo-lectores">Coste de importar <?= e($coche['titulo']) ?> a <?= e($municipio) ?></h1>
  <p class="nota-calculo">Calculado el <?= e(fecha((string) ($res['meta']['calculado'] ?? ''))) ?> con las tablas fiscales de <?= e($res['meta']['anio_tablas'] ?? '') ?></p>

  <?php if ($errores): ?>
    <?= resumenErrores($errores, $etiquetasAjuste + ['general' => 'Ajustes']) ?>
  <?php endif; ?>

  <div class="pila">
    <?= Vista::renderizar('tarjeta_coche', ['coche' => $coche]) ?>

    <div class="destino">
      <div class="destino__fila">
        <?= icono('check') ?>
        <span class="destino__nombre"><?= e($municipio) ?></span>
        <a class="destino__cambiar" href="/c/<?= e($id) ?>/editar?destino=1#destino">Cambiar<span class="solo-lectores"> el municipio de destino</span></a>
      </div>
    </div>

    <?php if ($actual !== null): ?>
      <section class="modelo<?= $aproximado ? ' modelo--aprox' : '' ?>" aria-label="<?= $aproximado ? 'Modelo más parecido en el BOE, no exacto' : 'Modelo identificado en el BOE' ?>">
        <span class="modelo__icono"><?= icono($aproximado ? 'aviso' : 'escudo') ?></span>
        <div>
          <p class="modelo__etiqueta"><?= ($actual['origen'] ?? '') === 'manual' ? 'Precio medio del BOE (indicado por ti)' : ($aproximado ? 'Modelo más parecido en el BOE (no exacto)' : 'Modelo identificado (base BOE)') ?></p>
          <p class="modelo__nombre"><?= e($actual['modelo_tipo'] ?? 'Precio medio escrito a mano') ?>
            <span class="insignia insignia--<?= $aproximado ? 'aprox' : (($actual['origen'] ?? 'auto') === 'auto' ? 'auto' : 'manual') ?>"><?= $aproximado ? 'APROX.' : e($origenesBoe[$actual['origen'] ?? 'auto'] ?? 'AUTO') ?></span></p>
          <p class="modelo__cifras">
            <span>Valor de referencia: <strong class="num"><?= eur((float) $actual['valor']) ?></strong></span>
            <?php if ($der['cvf'] !== null): ?><span>Caballos fiscales: <strong class="num"><?= e(num((float) $der['cvf'], 2)) ?></strong></span><?php endif; ?>
            <?php if (!empty($actual['puntuacion'])): ?><span>Coincidencia: <strong class="num"><?= e((int) round((float) $actual['puntuacion'] * 100)) ?> %</strong></span><?php endif; ?>
          </p>
          <?php if ($aproximado): ?>
            <p class="modelo__aviso"><?= $motivoAproximado !== '' ? e($motivoAproximado) . ' ' : '' ?>Comprueba que sea tu versión; si no lo es, elige la correcta en «Otros modelos posibles».</p>
          <?php endif; ?>
        </div>
      </section>
    <?php elseif ($panelBoe['disponible']): ?>
      <section class="modelo modelo--vacio" aria-label="Modelo del BOE no encontrado">
        <span class="modelo__icono"><?= icono('aviso') ?></span>
        <div>
          <p class="modelo__nombre">No hemos encontrado tu coche en la Orden del BOE de <?= e($panelBoe['anio']) ?></p>
          <p class="modelo__cifras"><span>Sin él no se pueden calcular el valor venal, el impuesto de matriculación ni el ITP.
            <a href="/c/<?= e($id) ?>/editar">Edita los datos</a> (marca, modelo y versión tal como los escribe el BOE) o escribe a mano el precio medio de tu modelo.</span></p>
        </div>
      </section>
    <?php endif; ?>

    <section class="total-banner" aria-labelledby="total-titulo">
      <div class="total-banner__fila">
        <p class="total-banner__etiqueta" id="total-titulo"><?= icono('ayuda') ?><span><?= $res['completo'] ? 'Coste total estimado' : 'Coste parcial (incompleto)' ?></span></p>
        <p class="total-banner__importe<?= $res['completo'] ? '' : ' total-banner__importe--parcial' ?>"><?= eur($total) ?></p>
        <div class="total-banner__botones no-imprimir">
          <button class="boton-icono" type="button" data-copiar="#enlace-compartir" hidden><?= icono('compartir') ?><span class="solo-lectores">Copiar el enlace de este cálculo</span></button>
          <button class="boton-icono" type="button" data-imprimir hidden><?= icono('descargar') ?><span class="solo-lectores">Imprimir o guardar en PDF</span></button>
        </div>
      </div>
      <p class="total-banner__frase">Precio del anuncio <strong class="num"><?= eur((float) $res['precio_anuncio']) ?></strong> +
        <strong class="num"><?= eur((float) $resumen['extra']) ?></strong> de impuestos, tasas y transporte (<strong>+<?= num((float) $resumen['porcentaje'], 1) ?> %</strong> sobre el precio).</p>
    </section>
    <p class="estado-copia no-imprimir" role="status" aria-live="polite" data-estado-copia></p>

    <?php if (!$res['completo']): ?>
      <div class="alerta alerta--atencion" role="status">
        <span class="alerta__icono" aria-hidden="true">⚠</span>
        <p><strong>Total parcial.</strong> No hemos podido calcular <?= count($faltan) ?> <?= count($faltan) === 1 ? 'partida' : 'partidas' ?>
          (<?= e(implode(', ', array_map(static fn(array $p): string => mb_strtolower($p['concepto']), $faltan))) ?>)
          y no están sumadas. Abre cada una para ver qué falta. <a href="/c/<?= e($id) ?>/editar">Completa los datos</a> y se recalcula.</p>
      </div>
    <?php endif; ?>

    <?php if ($alertasAtencion): ?>
      <h2 class="solo-lectores">Avisos importantes</h2>
      <ul class="alertas no-imprimir">
        <?php foreach ($alertasAtencion as $a): ?>
          <li class="alerta alerta--atencion"><span class="alerta__icono" aria-hidden="true">⚠</span><p><?= e($a['texto']) ?></p></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <h2 class="solo-lectores">Desglose del coste</h2>
    <div class="categorias">
      <?php foreach ($categorias as $clave => $cat):
          $ps = $cat['partidas'];
          $oks = array_filter($ps, static fn(array $p): bool => $p['estado'] === 'ok');
          $suma = array_sum(array_map(static fn(array $p): float => (float) $p['importe'], $oks));
          $unica = count($ps) === 1;
          $titulo = $unica ? ($clave === 'iedmt' ? 'Impuesto de matriculación' : ($clave === 'itp' ? (str_contains($ps[0]['concepto'], 'IVA') && !str_contains($ps[0]['concepto'], 'ITP') ? 'IVA español' : 'ITP (compraventa entre particulares)') : ($clave === 'precio' ? $cat['titulo'] : $cat['titulo']))) : $cat['titulo'];
          $todoOk = count($oks) === count($ps);
      ?>
        <?php if ($clave === 'precio'): ?>
          <div class="categoria categoria--precio">
            <div class="categoria__plana">
              <span class="categoria__icono"><?= icono($cat['icono']) ?></span>
              <span class="categoria__titulo"><?= e($cat['titulo']) ?></span>
              <span class="categoria__importe num"><?= eur($suma) ?></span>
            </div>
          </div>
          <?php continue; ?>
        <?php endif; ?>
        <details class="categoria categoria--<?= e($clave) ?>"<?= !$todoOk ? ' open' : '' ?>>
          <summary>
            <span class="categoria__icono"><?= icono($cat['icono']) ?></span>
            <span class="categoria__titulo"><?= e($titulo) ?></span>
            <?php if ($unica && !$todoOk): ?>
              <span class="categoria__importe categoria__importe--falta">No calculable</span>
            <?php else: ?>
              <span class="categoria__importe num"><?= eur($suma) ?><?= !$todoOk ? ' (parcial)' : '' ?></span>
            <?php endif; ?>
          </summary>
          <div class="categoria__cuerpo">
            <?php if ($unica): ?>
              <?= $cuerpoPartida($ps[0]) ?>
            <?php else: ?>
              <?php foreach ($ps as $p): $ok = $p['estado'] === 'ok'; $esGestoria = $p['clave'] === 'gestoria'; ?>
                <details class="partida-interna"<?= $ok ? '' : ' open' ?>>
                  <summary>
                    <span class="partida-interna__nombre"><?= e($p['concepto']) ?>
                      <?php if ($p['verificar'] || $p['estimacion']): ?><span class="insignias">
                        <?php if ($p['verificar']): ?><span class="insignia insignia--verificar">Por verificar</span><?php endif; ?>
                        <?php if ($p['estimacion']): ?><span class="insignia insignia--estimacion">Estimación</span><?php endif; ?>
                      </span><?php endif; ?></span>
                    <span class="partida-interna__importe num"><?= $ok ? eur((float) $p['importe']) : 'No calculable' ?></span>
                  </summary>
                  <div class="partida-interna__cuerpo">
                    <?= $cuerpoPartida($p, false) ?>
                  </div>
                </details>
                <?php if ($esGestoria): ?>
                  <form class="no-imprimir" method="post" action="/c/<?= e($id) ?>/costes">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <?php foreach ($ajustesActuales as $campo => $valor): ?><input type="hidden" name="<?= e($campo) ?>" value="<?= e($valor) ?>"><?php endforeach; ?>
                    <input type="hidden" name="cf_gestoria" value="0">
                    <label class="interruptor"><input type="checkbox" name="cf_gestoria" value=""<?= $gestoriaExcluida ? '' : ' checked' ?> data-autoenviar>
                      Incluir la gestoría (<?= eur($gestoriaDefecto) ?> por defecto): desmárcalo si haces tú los trámites</label>
                    <button class="boton boton--secundario boton--pequeno" type="submit" data-autoenviar-boton>Recalcular</button>
                  </form>
                <?php endif; ?>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </details>
      <?php endforeach; ?>
    </div>

    <?php if ($notas): ?>
      <details class="panel no-imprimir">
        <summary><?= icono('info') ?>Notas sobre el cálculo (<?= count($notas) ?>)</summary>
        <div class="panel__cuerpo">
          <ul class="alertas">
            <?php foreach ($notas as $a): ?>
              <li class="alerta alerta--info"><span class="alerta__icono" aria-hidden="true">ℹ</span><p><?= e($a['texto']) ?></p></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </details>
    <?php endif; ?>
  </div>

  <?php if ($panelBoe['disponible'] && $panelBoe['filas']): ?>
    <section class="no-imprimir" aria-labelledby="otros-modelos">
      <h2 class="titulo-seccion" id="otros-modelos"><?= $actual !== null ? 'Otros modelos posibles' : 'Modelos del BOE más parecidos' ?></h2>
      <p class="pista">El valor venal depende de la versión exacta. Si tu coche es otro de estos, tócalo y recalculamos todo con él.</p>
      <form method="post" action="/c/<?= e($id) ?>/boe">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <ul class="alternativas">
          <?php foreach ($panelBoe['filas'] as $f): $fila = $f['fila']; $dif = $f['total'] !== null ? $f['total'] - $total : null; ?>
            <li>
              <button class="alternativa<?= $f['en_uso'] ? ' alternativa--uso' : '' ?>" type="submit" name="fila" value="<?= e($fila['id']) ?>"<?= $f['en_uso'] ? ' aria-current="true"' : '' ?>>
                <span class="alternativa__nombre"><?= e($fila['modelo_tipo']) ?>
                  <small><?= e($fila['inicio'] === null ? 'sin periodo comercial' : ($fila['fin'] === null ? 'desde ' . $fila['inicio'] : ($fila['inicio'] === $fila['fin'] ? (string) $fila['inicio'] : $fila['inicio'] . '–' . $fila['fin']))) ?> ·
                    <?= $fila['cc'] !== null ? e(num((float) $fila['cc'])) . ' cm³ · ' : '' ?><?= e(num((float) $fila['kw'], $fila['kw'] == floor($fila['kw']) ? 0 : 1)) ?> kW · valor <?= e(eur((float) $fila['valor'])) ?></small></span>
                <?php if ($f['en_uso']): ?><span class="insignia insignia--auto">En uso</span>
                <?php elseif ($dif !== null && abs($dif) >= 0.5): ?><span class="alternativa__dif"><?= $dif > 0 ? '+' : '−' ?><?= e(num(abs($dif), 0)) ?> €</span><?php endif; ?>
                <span class="insignia insignia--pct<?= $f['confianza'] === 'baja' ? ' insignia--pct-baja' : '' ?>"><?= e((int) round($f['puntuacion'] * 100)) ?>%<span class="solo-lectores"> de coincidencia</span></span>
              </button>
            </li>
          <?php endforeach; ?>
        </ul>
      </form>
    </section>
  <?php endif; ?>

  <div class="acciones botones no-imprimir">
    <a class="boton" href="/c/<?= e($id) ?>/editar"><?= icono('editar') ?>Editar los datos</a>
    <button class="boton boton--secundario" type="button" data-guardar-historial="<?= e($resumenJson) ?>" hidden><?= icono('historial') ?>Guardar en mi historial</button>
    <a class="boton boton--secundario" href="/comparar?c=<?= e($id) ?>">Comparar con otros</a>
  </div>
  <p class="estado-copia no-imprimir" role="status" aria-live="polite" data-estado-historial></p>

  <details class="panel no-imprimir"<?= $errores ? ' open' : '' ?>>
    <summary><?= icono('ajustes') ?>Ajustar costes y transporte</summary>
    <div class="panel__cuerpo">
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
    </div>
  </details>

  <details class="panel no-imprimir">
    <summary><?= icono('compartir') ?>Compartir este cálculo</summary>
    <div class="panel__cuerpo">
      <div class="copiable">
        <code id="enlace-compartir"><?= e($url) ?></code>
      </div>
      <p class="pista">Cualquiera con este enlace verá el cálculo tal como está ahora, sin tus datos personales. Se conserva un año.</p>
    </div>
  </details>

  <details class="panel no-imprimir">
    <summary><?= icono('info') ?>Ver detalles técnicos</summary>
    <div class="panel__cuerpo">
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
        <dt>Cómo se eligió la fila del BOE</dt>
        <dd><?= $actual === null ? 'No hay fila del BOE.' : e(['auto' => 'Automáticamente, por parecido con el anuncio.', 'usuario' => 'La elegiste tú.', 'manual' => 'Escribiste tú el precio medio.'][$actual['origen'] ?? 'auto'] ?? '') ?></dd>
      </dl>
    </div>
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
