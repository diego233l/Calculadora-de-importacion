<?php
declare(strict_types=1);

use function CalculadoraImportacion\Web\campoMunicipio;
use function CalculadoraImportacion\Web\campoTexto;
use function CalculadoraImportacion\Web\e;
use function CalculadoraImportacion\Web\resumenErrores;

/** @var string $csrf */
/** @var array $valores */
/** @var array $errores */
/** @var array $candidatos */
?>
<div class="contenedor heroe">
  <div>
    <p class="heroe__ruta">
      <svg width="46" height="14" viewBox="0 0 46 14" aria-hidden="true" focusable="false"><path d="M2 7h42" stroke="currentColor" stroke-width="2" stroke-dasharray="2 5" stroke-linecap="round"/><circle cx="3" cy="7" r="3" fill="currentColor"/><circle cx="43" cy="7" r="3" fill="currentColor"/></svg>
      De mobile.de a tu matrícula
    </p>
    <h1>¿Cuánto cuesta <em>de verdad</em> importar ese coche a España?</h1>
    <p class="heroe__intro">Pega el anuncio, dinos dónde lo vas a matricular y te desglosamos el coste total: impuesto de matriculación, ITP o IVA, circulación, tasas, gestoría y transporte. Cada cifra con su fuente y su cálculo.</p>
  </div>

  <div class="tarjeta tarjeta--ticket">
    <h2 id="titulo-formulario">Empieza aquí</h2>
    <?= resumenErrores($errores, ['enlace' => 'Enlace del anuncio', 'municipio' => 'Municipio']) ?>
    <form class="formulario" method="post" action="/datos" novalidate aria-labelledby="titulo-formulario">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <?= campoTexto('enlace', 'Enlace del anuncio de mobile.de', $valores, $errores, [
          'pista' => 'La dirección completa del anuncio, copiada de la barra del navegador.',
          'modo' => 'url', 'auto' => 'off',
      ]) ?>
      <?= campoMunicipio($valores, $errores, $candidatos) ?>
      <details class="pegar-texto"<?= !empty($valores['texto_anuncio']) || isset($errores['texto_anuncio']) ? ' open' : '' ?>>
        <summary>¿El enlace no funciona? Pega el texto del anuncio</summary>
        <div class="campo">
          <label for="f-texto_anuncio">Texto del anuncio <span class="opcional">(opcional)</span></label>
          <p class="pista" id="p-texto_anuncio">Abre el anuncio, pulsa Ctrl+A y Ctrl+C (en el móvil, «Seleccionar todo» y «Copiar») y pega aquí. Si pegas el texto, se usa en lugar del enlace.</p>
          <textarea id="f-texto_anuncio" name="texto_anuncio" rows="5" maxlength="60000" aria-describedby="p-texto_anuncio" spellcheck="false" autocomplete="off"><?= e($valores['texto_anuncio'] ?? '') ?></textarea>
        </div>
      </details>
      <button class="boton boton--bloque" type="submit">Continuar</button>
    </form>
    <p class="aviso-nota">Leemos los datos del anuncio por ti y tú los revisas antes de calcular. No pedimos registro ni datos personales.</p>
  </div>
</div>

<section class="seccion" aria-labelledby="como-funciona">
  <div class="contenedor">
    <h2 id="como-funciona">Cómo funciona</h2>
    <ol class="pasos">
      <li>
        <h3>Pega el anuncio y elige municipio</h3>
        <p>Solo se admiten enlaces de mobile.de (o el texto copiado del anuncio). El municipio de destino fija el ITP, el IVTM y la comunidad autónoma.</p>
      </li>
      <li>
        <h3>Revisa los datos del coche</h3>
        <p>Leemos marca, matriculación, kilómetros, potencia, emisiones y vendedor, y marcamos lo que no está claro. Tú tienes la última palabra sobre cada dato.</p>
      </li>
      <li>
        <h3>Recibe el desglose completo</h3>
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
    <h2 id="garantias">Sin cajas negras</h2>
    <div class="garantias">
      <div>
        <h3>Cada cifra con su fuente</h3>
        <p>Cada partida enlaza con la norma y explica el cálculo paso a paso, con el valor venal del BOE y los tipos aplicados.</p>
      </div>
      <div>
        <h3>Claros con lo que no sabemos</h3>
        <p>Si un dato falta o aún no está verificado, te lo decimos en vez de inventarlo. Nada de totales que parecen exactos y no lo son.</p>
      </div>
      <div>
        <h3>Sin cuentas ni pagos</h3>
        <p>No te pedimos registro. Tu historial se queda en tu navegador y solo se comparte el cálculo que tú decidas enlazar.</p>
      </div>
    </div>
  </div>
</section>
