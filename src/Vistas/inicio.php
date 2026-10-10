<?php
declare(strict_types=1);

use function CalculadoraImportacion\Web\e;
use function CalculadoraImportacion\Web\icono;
use function CalculadoraImportacion\Web\resumenErrores;

/** @var string $csrf */
/** @var array $valores */
/** @var array $errores */
$errorEnlace = $errores['enlace'] ?? null;
?>
<div class="contenedor portada">
  <span class="portada__icono"><?= icono('coche') ?></span>
  <h1>Calcula el coste real de <em>importar tu coche</em></h1>
  <p class="portada__intro">Pega el enlace de un anuncio de <strong>mobile.de</strong> y obtén al instante el desglose completo de impuestos, tasas, transporte y costes fijos, con la fuente de cada cifra.</p>

  <div class="portada__formulario">
    <?= resumenErrores($errores, ['enlace' => 'Enlace del anuncio']) ?>
    <form method="post" action="/datos" novalidate>
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <div class="buscador">
        <?= icono('enlace') ?>
        <label class="solo-lectores" for="f-enlace">Enlace del anuncio de mobile.de</label>
        <input type="text" id="f-enlace" name="enlace" value="<?= e($valores['enlace'] ?? '') ?>" inputmode="url" autocomplete="off" spellcheck="false"
               placeholder="Pega aquí el enlace de mobile.de…"<?= $errorEnlace !== null ? ' aria-invalid="true" aria-describedby="e-enlace"' : '' ?>>
        <button class="boton" type="submit"><?= icono('calculadora') ?><span>Calcular</span></button>
      </div>
      <?php if ($errorEnlace !== null): ?><p class="error-campo" id="e-enlace"><?= e($errorEnlace) ?></p><?php endif; ?>

      <details class="pegar-texto"<?= !empty($valores['texto_anuncio']) ? ' open' : '' ?>>
        <summary><?= icono('documento') ?>¿El enlace no funciona? Pega el texto del anuncio</summary>
        <div class="campo">
          <label for="f-texto_anuncio">Texto del anuncio <span class="opcional">(opcional)</span></label>
          <p class="pista" id="p-texto_anuncio">Abre el anuncio, pulsa Ctrl+A y Ctrl+C (en el móvil, «Seleccionar todo» y «Copiar») y pega aquí. Si pegas el texto, se usa en lugar del enlace.</p>
          <textarea id="f-texto_anuncio" name="texto_anuncio" rows="5" maxlength="60000" aria-describedby="p-texto_anuncio" spellcheck="false" autocomplete="off"><?= e($valores['texto_anuncio'] ?? '') ?></textarea>
        </div>
      </details>
    </form>
    <p class="portada__alternativas">¿Prefieres escribir los datos tú? <a href="/datos">Rellenar a mano</a></p>
  </div>

  <ul class="rasgos">
    <li><span class="rasgos__icono"><?= icono('escudo') ?></span><div><strong>Datos oficiales</strong><span>Base del BOE con los precios medios y la depreciación.</span></div></li>
    <li><span class="rasgos__icono"><?= icono('ayuda') ?></span><div><strong>Transparente</strong><span>La fuente y el cálculo de cada cifra, a la vista.</span></div></li>
    <li><span class="rasgos__icono"><?= icono('reloj') ?></span><div><strong>Historial</strong><span>Guarda y compara resultados en tu navegador.</span></div></li>
  </ul>
</div>

<section class="seccion" aria-labelledby="como-funciona">
  <div class="contenedor">
    <h2 id="como-funciona">Cómo funciona</h2>
    <ol class="pasos">
      <li>
        <h3>Pega el anuncio</h3>
        <p>Solo enlaces de mobile.de (o el texto copiado del anuncio). Leemos marca, kilómetros, potencia, emisiones y vendedor.</p>
      </li>
      <li>
        <h3>Elige dónde lo matriculas</h3>
        <p>El municipio de destino fija el ITP, el IVTM y la comunidad autónoma. Revisas lo que hemos leído antes de calcular.</p>
      </li>
      <li>
        <h3>Recibe el desglose</h3>
        <p>El total, concepto a concepto, con la norma que lo respalda. Lo guardas, lo compartes o lo imprimes en PDF.</p>
      </li>
    </ol>
  </div>
</section>

<section class="seccion" aria-labelledby="que-incluye">
  <div class="contenedor">
    <h2 id="que-incluye">Qué incluye el cálculo</h2>
    <ul class="chips">
      <li>Impuesto de matriculación (IEDMT)</li>
      <li>ITP o IVA según el caso</li>
      <li>IVTM del municipio</li>
      <li>Tasa de la DGT</li>
      <li>ITV de importación</li>
      <li>Gestoría y placas</li>
      <li>Transporte desde el vendedor</li>
    </ul>
  </div>
</section>

<section class="seccion" aria-labelledby="garantias">
  <div class="contenedor">
    <h2 id="garantias">Sin letra pequeña</h2>
    <div class="garantias">
      <div><h3>Sin registro</h3><p>No hay cuentas ni pagos: pegas, calculas y listo. Tu historial se queda en tu navegador.</p></div>
      <div><h3>Orientativo, no inventado</h3><p>Si un dato falta, te decimos qué partida no se puede calcular en lugar de adivinarla.</p></div>
      <div><h3>Todo editable</h3><p>Cambia cualquier coste por el que te hayan presupuestado y recalculamos al momento.</p></div>
    </div>
  </div>
</section>
