<?php
declare(strict_types=1);

use CalculadoraImportacion\Web\TarjetaCoche;
use CalculadoraImportacion\Web\Vista;
use function CalculadoraImportacion\Web\avisoRevisar;
use function CalculadoraImportacion\Web\campoMunicipio;
use function CalculadoraImportacion\Web\campoTexto;
use function CalculadoraImportacion\Web\e;
use function CalculadoraImportacion\Web\icono;
use function CalculadoraImportacion\Web\resumenErrores;

/** @var string $csrf */
/** @var array $v valores */
/** @var array $errores */
/** @var array $candidatos */
/** @var array $combustibles */
/** @var list<string> $marcas nombres de marca para la lista de sugerencias */
/** @var ?string $editando */
/** @var ?array $anuncio resultado de leer el anuncio: estado, mensaje, fuente, avisos, revisar (campo => texto) */
$etiquetas = [
    'enlace' => 'Enlace del anuncio', 'municipio' => 'Municipio', 'matriculacion' => 'Primera matriculación', 'km' => 'Kilómetros',
    'combustible' => 'Combustible', 'cc' => 'Cilindrada', 'cilindros' => 'Cilindros', 'kw' => 'Potencia (kW)', 'cv' => 'Potencia (CV)',
    'co2' => 'Emisiones de CO₂', 'precio' => 'Precio', 'vendedor' => 'Vendedor', 'cp_vendedor' => 'Código postal del vendedor',
    'boe_precio' => 'Precio medio del BOE', 'general' => 'Datos',
];
$combustibleActual = $v['combustible'] ?? '';
$anuncio ??= ['estado' => 'ninguno'];
$revisar = $anuncio['revisar'] ?? [];
$leido = ($anuncio['estado'] ?? 'ninguno') === 'ok';
$mostrarTarjeta = $leido || $editando;
$puedePegar = !$editando && !$leido;
$sinMunicipio = ($v['municipio'] ?? '') === '';
$destinoAbierto = $sinMunicipio || isset($errores['municipio']) || !empty($anuncio['abrir_destino']);
$datosAbiertos = !$mostrarTarjeta || $errores || array_filter($revisar, static fn(string $t): bool => str_contains($t, 'No lo hemos encontrado'));
$coche = $mostrarTarjeta ? TarjetaCoche::desdeValores($v, $revisar) : null;
?>
<div class="contenedor estrecho">
  <nav class="migas" aria-label="Migas de pan"><a href="/">Inicio</a> › <?= $editando ? 'Editar datos' : 'Datos del coche' ?></nav>
  <h1><?= $editando ? 'Edita los datos del coche' : ($leido ? 'Esto es lo que hemos leído' : 'Los datos del coche') ?></h1>

  <?php if ($leido): ?>
    <div class="estado-lectura" role="status">
      <?= icono('info') ?>
      <div>
        <p><strong>Hemos leído estos datos <?= e(($anuncio['fuente'] ?? '') === 'texto' ? 'del texto que has pegado' : 'del anuncio') ?>.</strong>
          Las casillas en amarillo tienen poca confianza o no estaban en el anuncio: revísalas con «Corregir o completar los datos». Tú tienes la última palabra sobre cada dato.</p>
        <?php foreach ($anuncio['avisos'] ?? [] as $aviso): ?><p><?= e($aviso) ?></p><?php endforeach; ?>
      </div>
    </div>
  <?php elseif (!$editando): ?>
    <p class="suave">Cópialos del anuncio de mobile.de. Si un dato no lo tienes, déjalo en blanco y te diremos qué partida no hemos podido calcular: nunca lo inventamos.</p>
    <?php if (!empty($anuncio['mensaje'])): ?>
      <div class="alerta alerta--atencion" role="status">
        <span class="alerta__icono" aria-hidden="true">⚠</span>
        <p><?= e($anuncio['mensaje']) ?></p>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <?= resumenErrores($errores, $etiquetas) ?>

  <?php if ($puedePegar): ?>
    <details class="pegar-anuncio"<?= !empty($anuncio['mensaje']) ? ' open' : '' ?>>
      <summary><?= icono('documento') ?>Pegar el texto del anuncio para rellenar los datos</summary>
      <form class="formulario" method="post" action="/datos" novalidate>
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="enlace" value="<?= e($v['enlace'] ?? '') ?>">
        <input type="hidden" name="municipio" value="<?= e($v['municipio'] ?? '') ?>">
        <input type="hidden" name="municipio_texto" value="<?= e($v['municipio_texto'] ?? '') ?>">
        <div class="campo">
          <label for="f-texto_anuncio">Texto del anuncio</label>
          <p class="pista" id="p-texto_anuncio">En el anuncio de mobile.de pulsa Ctrl+A y Ctrl+C (en el móvil, «Seleccionar todo» y «Copiar») y pega aquí. No guardamos el texto, solo leemos los datos del coche.</p>
          <textarea id="f-texto_anuncio" name="texto_anuncio" rows="6" maxlength="60000" aria-describedby="p-texto_anuncio" spellcheck="false" autocomplete="off"></textarea>
        </div>
        <div class="botones"><button class="boton boton--secundario" type="submit">Leer el texto</button></div>
      </form>
    </details>
  <?php endif; ?>

  <form class="pila" method="post" action="/calcular" novalidate>
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="cambio" value="<?= e($v['cambio'] ?? '') ?>">
    <input type="hidden" name="ciudad_vendedor" value="<?= e($v['ciudad_vendedor'] ?? '') ?>">

    <?php if ($coche !== null): ?><?= Vista::renderizar('tarjeta_coche', ['coche' => $coche]) ?><?php endif; ?>

    <details class="destino<?= $sinMunicipio ? ' destino--falta' : '' ?>" id="destino" data-destino<?= $destinoAbierto ? ' open' : '' ?>>
      <summary>
        <?= icono($sinMunicipio ? 'lugar' : 'check') ?>
        <span class="destino__nombre" data-destino-nombre><?= $sinMunicipio ? '¿Dónde vas a matricularlo?' : e($v['municipio_texto'] ?? '') ?></span>
        <span class="destino__cambiar"><?= $sinMunicipio ? 'Elegir' : 'Cambiar' ?></span>
      </summary>
      <div class="destino__cuerpo"><?= campoMunicipio($v, $errores, $candidatos) ?></div>
    </details>

    <button class="boton boton--grande boton--bloque" type="submit"><?= icono('calculadora') ?><span>Calcular costes de importación</span></button>

    <details class="editar-datos"<?= $datosAbiertos ? ' open' : '' ?>>
      <summary><?= icono('editar') ?>Corregir o completar los datos</summary>
      <div class="editar-datos__cuerpo">
        <?= campoTexto('enlace', 'Enlace del anuncio de mobile.de', $v, $errores, ['modo' => 'url', 'pista' => 'Solo sirve para recordar de qué anuncio es el cálculo.']) ?>
      <fieldset>
      <legend>El coche</legend>
      <div class="formulario">
        <div class="rejilla rejilla--3">
          <?= campoTexto('marca', 'Marca', $v, $errores, ['auto' => 'off', 'lista' => 'lista-marcas', 'revisar' => $revisar['marca'] ?? null]) ?>
          <?= campoTexto('modelo', 'Modelo', $v, $errores, ['revisar' => $revisar['modelo'] ?? null]) ?>
          <?= campoTexto('version', 'Versión', $v, $errores, ['revisar' => $revisar['version'] ?? null]) ?>
        </div>
        <?php if (!empty($marcas)): ?>
          <datalist id="lista-marcas"><?php foreach ($marcas as $m): ?><option value="<?= e($m) ?>"><?php endforeach; ?></datalist>
        <?php endif; ?>
        <p class="pista">Escribe marca, modelo y versión como en el anuncio («BMW», «320d», «Touring Sport Line»): con ellos buscamos tu coche en la Orden del BOE para saber su valor venal.</p>
        <div class="rejilla rejilla--2">
          <?= campoTexto('matriculacion', 'Primera matriculación', $v, $errores, ['revisar' => $revisar['matriculacion'] ?? null, 'obligatorio' => true, 'modo' => 'numeric', 'pista' => 'Mes y año, como «03/2019». En el anuncio: «Erstzulassung».']) ?>
          <?= campoTexto('km', 'Kilómetros', $v, $errores, ['revisar' => $revisar['km'] ?? null, 'obligatorio' => true, 'modo' => 'numeric', 'pista' => 'En el anuncio: «Kilometerstand».']) ?>
        </div>
        <div class="campo<?= isset($revisar['combustible']) ? ' campo--revisar' : '' ?>">
          <label for="f-combustible">Combustible <span class="solo-lectores">(obligatorio)</span></label>
          <?php if (isset($revisar['combustible'])): ?><?= avisoRevisar('combustible', $revisar['combustible']) ?><?php endif; ?>
          <select id="f-combustible" name="combustible" aria-required="true"<?= isset($errores['combustible']) ? ' aria-invalid="true" aria-describedby="e-combustible' . (isset($revisar['combustible']) ? ' r-combustible' : '') . '"' : (isset($revisar['combustible']) ? ' aria-describedby="r-combustible"' : '') ?>>
            <option value="">Elige…</option>
            <?php foreach ($combustibles as $clave => $texto): ?>
              <option value="<?= e($clave) ?>"<?= $combustibleActual === $clave ? ' selected' : '' ?>><?= e($texto) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errores['combustible'])): ?><p class="error-campo" id="e-combustible"><?= e($errores['combustible']) ?></p><?php endif; ?>
        </div>
        <div class="rejilla rejilla--2" data-solo-combustion>
          <?= campoTexto('cc', 'Cilindrada (cm³)', $v, $errores, ['revisar' => $revisar['cc'] ?? null, 'obligatorio' => true, 'modo' => 'numeric', 'pista' => 'Por ejemplo 1.998. En el anuncio: «Hubraum».']) ?>
          <?= campoTexto('cilindros', 'Número de cilindros', $v, $errores, ['revisar' => $revisar['cilindros'] ?? null, 'modo' => 'numeric', 'pista' => 'Si no lo sabes, déjalo en blanco: usaremos el de la fila del BOE.']) ?>
        </div>
        <div class="rejilla rejilla--3">
          <?= campoTexto('kw', 'Potencia (kW)', $v, $errores, ['revisar' => $revisar['kw'] ?? null, 'modo' => 'decimal', 'pista' => 'Obligatoria en eléctricos. En el anuncio: «Leistung».']) ?>
          <?= campoTexto('cv', 'Potencia (CV)', $v, $errores, ['revisar' => $revisar['cv'] ?? null, 'modo' => 'decimal', 'pista' => 'Si solo tienes una de las dos, vale.']) ?>
          <div data-solo-combustion>
            <?= campoTexto('co2', 'CO₂ (g/km, WLTP)', $v, $errores, ['revisar' => $revisar['co2'] ?? null, 'modo' => 'decimal', 'pista' => 'Emisiones combinadas. Fija el tipo del impuesto de matriculación.']) ?>
          </div>
        </div>
      </div>
    </fieldset>

      <fieldset>
      <legend>Precio y vendedor</legend>
      <div class="formulario">
        <div class="rejilla rejilla--2">
          <?= campoTexto('precio', 'Precio del anuncio (€)', $v, $errores, ['revisar' => $revisar['precio'] ?? null, 'obligatorio' => true, 'modo' => 'decimal', 'pista' => 'El precio total que pide el vendedor.']) ?>
          <?= campoTexto('cp_vendedor', 'Código postal del vendedor', $v, $errores, ['revisar' => $revisar['cp_vendedor'] ?? null, 'obligatorio' => true, 'modo' => 'numeric', 'pista' => '5 cifras, junto a la ubicación. Sirve para estimar el transporte.']) ?>
        </div>
        <div class="campo<?= isset($revisar['vendedor']) ? ' campo--revisar' : '' ?>" role="radiogroup" aria-labelledby="et-vendedor"<?= isset($errores['vendedor']) ? ' aria-describedby="e-vendedor"' : (isset($revisar['vendedor']) ? ' aria-describedby="r-vendedor"' : '') ?>>
          <span class="etiqueta" id="et-vendedor">Tipo de vendedor</span>
          <?php if (isset($revisar['vendedor'])): ?><?= avisoRevisar('vendedor', $revisar['vendedor']) ?><?php endif; ?>
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
      <legend>Valoración del BOE <span class="opcional">(opcional)</span></legend>
      <div class="formulario">
        <?= campoTexto('boe_precio', 'Precio medio del modelo en el BOE (€)', $v, $errores, [
            'modo' => 'decimal',
            'pista' => 'Déjalo vacío y buscaremos tu coche en la Orden HAC/1501/2025 (anexo I) por marca, modelo, año, cilindrada, potencia y combustible; en el resultado podrás cambiar de fila. Escríbelo solo si ya conoces el precio medio exacto de tu versión: entonces usaremos ese.',
        ]) ?>
      </div>
    </fieldset>
        <div class="botones">
          <button class="boton" type="submit">Calcular el coste</button>
          <a class="boton boton--secundario" href="<?= $editando ? '/c/' . e($editando) : '/' ?>">Cancelar</a>
        </div>
      </div>
    </details>
  </form>
</div>
