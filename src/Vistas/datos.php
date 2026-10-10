<?php
declare(strict_types=1);

use function CalculadoraImportacion\Web\campoMunicipio;
use function CalculadoraImportacion\Web\campoTexto;
use function CalculadoraImportacion\Web\e;
use function CalculadoraImportacion\Web\resumenErrores;

/** @var string $csrf */
/** @var array $v valores */
/** @var array $errores */
/** @var array $candidatos */
/** @var array $combustibles */
/** @var ?string $editando */
$etiquetas = [
    'enlace' => 'Enlace del anuncio', 'municipio' => 'Municipio', 'matriculacion' => 'Primera matriculación', 'km' => 'Kilómetros',
    'combustible' => 'Combustible', 'cc' => 'Cilindrada', 'cilindros' => 'Cilindros', 'kw' => 'Potencia (kW)', 'cv' => 'Potencia (CV)',
    'co2' => 'Emisiones de CO₂', 'precio' => 'Precio', 'vendedor' => 'Vendedor', 'cp_vendedor' => 'Código postal del vendedor',
    'boe_precio' => 'Precio medio del BOE', 'general' => 'Datos',
];
$combustibleActual = $v['combustible'] ?? '';
?>
<div class="contenedor estrecho">
  <nav class="migas" aria-label="Migas de pan"><a href="/">Inicio</a> › <?= $editando ? 'Editar datos' : 'Datos del coche' ?></nav>
  <h1><?= $editando ? 'Edita los datos del coche' : 'Los datos del coche' ?></h1>
  <p class="suave">Cópialos del anuncio de mobile.de. Si un dato no lo tienes, déjalo en blanco y te diremos qué partida no hemos podido calcular: nunca lo inventamos.</p>

  <?= resumenErrores($errores, $etiquetas) ?>

  <form class="formulario" method="post" action="/calcular" novalidate>
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

    <fieldset>
      <legend>Anuncio y destino</legend>
      <div class="formulario">
        <?= campoTexto('enlace', 'Enlace del anuncio de mobile.de', $v, $errores, ['modo' => 'url', 'pista' => 'Solo sirve para recordar de qué anuncio es el cálculo.']) ?>
        <?= campoMunicipio($v, $errores, $candidatos) ?>
      </div>
    </fieldset>

    <fieldset>
      <legend>El coche</legend>
      <div class="formulario">
        <div class="rejilla rejilla--3">
          <?= campoTexto('marca', 'Marca', $v, $errores, ['auto' => 'off']) ?>
          <?= campoTexto('modelo', 'Modelo', $v, $errores) ?>
          <?= campoTexto('version', 'Versión', $v, $errores) ?>
        </div>
        <div class="rejilla rejilla--2">
          <?= campoTexto('matriculacion', 'Primera matriculación', $v, $errores, ['obligatorio' => true, 'modo' => 'numeric', 'pista' => 'Mes y año, como «03/2019». En el anuncio: «Erstzulassung».']) ?>
          <?= campoTexto('km', 'Kilómetros', $v, $errores, ['obligatorio' => true, 'modo' => 'numeric', 'pista' => 'En el anuncio: «Kilometerstand».']) ?>
        </div>
        <div class="campo">
          <label for="f-combustible">Combustible <span class="solo-lectores">(obligatorio)</span></label>
          <select id="f-combustible" name="combustible" aria-required="true"<?= isset($errores['combustible']) ? ' aria-invalid="true" aria-describedby="e-combustible"' : '' ?>>
            <option value="">Elige…</option>
            <?php foreach ($combustibles as $clave => $texto): ?>
              <option value="<?= e($clave) ?>"<?= $combustibleActual === $clave ? ' selected' : '' ?>><?= e($texto) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errores['combustible'])): ?><p class="error-campo" id="e-combustible"><?= e($errores['combustible']) ?></p><?php endif; ?>
        </div>
        <div class="rejilla rejilla--2" data-solo-combustion>
          <?= campoTexto('cc', 'Cilindrada (cm³)', $v, $errores, ['obligatorio' => true, 'modo' => 'numeric', 'pista' => 'Por ejemplo 1.998. En el anuncio: «Hubraum».']) ?>
          <?= campoTexto('cilindros', 'Número de cilindros', $v, $errores, ['obligatorio' => true, 'modo' => 'numeric', 'pista' => 'Lo normal son 4. Con él calculamos los caballos fiscales.']) ?>
        </div>
        <div class="rejilla rejilla--3">
          <?= campoTexto('kw', 'Potencia (kW)', $v, $errores, ['modo' => 'decimal', 'pista' => 'Obligatoria en eléctricos. En el anuncio: «Leistung».']) ?>
          <?= campoTexto('cv', 'Potencia (CV)', $v, $errores, ['modo' => 'decimal', 'pista' => 'Si solo tienes una de las dos, vale.']) ?>
          <div data-solo-combustion>
            <?= campoTexto('co2', 'CO₂ (g/km, WLTP)', $v, $errores, ['modo' => 'decimal', 'pista' => 'Emisiones combinadas. Fija el tipo del impuesto de matriculación.']) ?>
          </div>
        </div>
      </div>
    </fieldset>

    <fieldset>
      <legend>Precio y vendedor</legend>
      <div class="formulario">
        <div class="rejilla rejilla--2">
          <?= campoTexto('precio', 'Precio del anuncio (€)', $v, $errores, ['obligatorio' => true, 'modo' => 'decimal', 'pista' => 'El precio total que pide el vendedor.']) ?>
          <?= campoTexto('cp_vendedor', 'Código postal del vendedor', $v, $errores, ['obligatorio' => true, 'modo' => 'numeric', 'pista' => '5 cifras, junto a la ubicación. Sirve para estimar el transporte.']) ?>
        </div>
        <div class="campo" role="radiogroup" aria-labelledby="et-vendedor"<?= isset($errores['vendedor']) ? ' aria-describedby="e-vendedor"' : '' ?>>
          <span class="etiqueta" id="et-vendedor">Tipo de vendedor</span>
          <div class="opciones">
            <label class="opcion"><input type="radio" name="vendedor" value="particular"<?= ($v['vendedor'] ?? '') === 'particular' ? ' checked' : '' ?>><span>Particular (Privatanbieter)</span></label>
            <label class="opcion"><input type="radio" name="vendedor" value="profesional"<?= ($v['vendedor'] ?? '') === 'profesional' ? ' checked' : '' ?>><span>Profesional (Händler)</span></label>
          </div>
          <?php if (isset($errores['vendedor'])): ?><p class="error-campo" id="e-vendedor"><?= e($errores['vendedor']) ?></p><?php endif; ?>
        </div>
        <div class="casilla" data-solo-profesional>
          <input type="checkbox" id="f-iva_deducible" name="iva_deducible" value="1"<?= ($v['iva_deducible'] ?? '') === '1' ? ' checked' : '' ?>>
          <label for="f-iva_deducible">El anuncio indica «MwSt. ausweisbar» (IVA desglosable)<br><span class="pista">Importa si el coche es nuevo: entonces se factura sin IVA alemán y se paga el IVA español.</span></label>
        </div>
      </div>
    </fieldset>

    <fieldset>
      <legend>Valoración del BOE</legend>
      <div class="formulario">
        <?= campoTexto('boe_precio', 'Precio medio del modelo en el BOE (€)', $v, $errores, [
            'modo' => 'decimal',
            'pista' => 'Es el precio medio que fija la Orden HAC/1501/2025 (anexo I) para tu modelo. Hacienda lo usa de base para el impuesto de matriculación y el ITP. Sin él no podemos calcular esos dos impuestos.',
        ]) ?>
      </div>
    </fieldset>

    <div class="botones">
      <button class="boton" type="submit">Calcular el coste</button>
      <a class="boton boton--secundario" href="<?= $editando ? '/c/' . e($editando) : '/' ?>">Cancelar</a>
    </div>
  </form>
</div>
