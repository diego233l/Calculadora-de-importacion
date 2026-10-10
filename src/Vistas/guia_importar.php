<?php
declare(strict_types=1);

use function CalculadoraImportacion\Web\e;

/** @var string $titulo */
?>
<div class="contenedor seccion">
  <nav class="migas" aria-label="Migas de pan"><a href="/guias">Guías</a> › Importar desde Alemania</nav>
  <article class="prosa">
    <h1><?= e($titulo) ?></h1>
    <p>Comprar un coche en Alemania y matricularlo en España es perfectamente legal y, a menudo, compensa. Al ser un país de la UE no hay aduana ni arancel, pero sí impuestos en España, y conviene conocerlos <em>antes</em> de pagar la señal. Esta guía resume el camino; la <a href="/">calculadora</a> te da las cifras de tu caso.</p>

    <h2>1. Antes de comprar</h2>
    <ul>
      <li><strong>¿Particular o profesional?</strong> Cambia los impuestos. A un <em>profesional</em> (Händler) el coche usado suele llegar ya con el IVA alemán pagado, así que en España no hay ITP ni IVA. A un <em>particular</em> se le paga el ITP de tu comunidad autónoma.</li>
      <li><strong>¿Es «nuevo» a efectos de IVA?</strong> Lo es si tiene menos de 6 meses desde su primera matriculación o no ha recorrido más de 6.000 km. Entonces se compra sin IVA alemán y se paga el IVA español (21 %) con el modelo 309.</li>
      <li><strong>Emisiones de CO₂ (WLTP).</strong> Fijan el tipo del impuesto de matriculación: de 0 % a 14,75 %. Pídelas por escrito o míralas en la documentación del coche.</li>
      <li><strong>Documentación.</strong> Pide el certificado de conformidad europeo (COC) si lo tiene, las dos partes de la documentación del vehículo y la factura o el contrato de compraventa. Sin COC, el fabricante puede emitirlo (a veces con coste).</li>
    </ul>

    <h2>2. La compra y la salida de Alemania</h2>
    <p>Para sacar el coche de Alemania hay que darlo de baja allí y circular con placas de exportación y un seguro temporal, o traerlo en un camión. La calculadora estima ambas opciones: te recoges el coche tú, o lo transporta una empresa. Pide siempre recibo del pago y los papeles originales.</p>

    <h2>3. En España: impuestos y trámites</h2>
    <ol>
      <li><strong>Impuesto de matriculación (IEDMT)</strong>, modelo 576, ante la Agencia Tributaria. Se presenta para todos los coches importados, nuevos o usados; los eléctricos puros tributan al 0 %. <a href="/guias/impuesto-de-matriculacion">Cómo se calcula</a>.</li>
      <li><strong>ITP</strong> (si compraste a un particular) o <strong>IVA</strong> (si es un coche nuevo), en la comunidad autónoma donde residas. <a href="/guias/itp">Cómo se calcula el ITP</a>.</li>
      <li><strong>ITV de importación</strong>: la estación comprueba el coche y se obtiene la ficha técnica española.</li>
      <li><strong>Matriculación en la DGT</strong>: tasa de 99,77 € en 2026 para matricular un vehículo no matriculado en España, más las placas.</li>
      <li><strong>IVTM</strong> (impuesto de circulación) en tu ayuntamiento, que se paga prorrateado por trimestres el año de la matriculación.</li>
    </ol>
    <p class="nota"><strong>Ojo con el plazo.</strong> La ley del impuesto de matriculación considera que el coche tributa si circula en España sin haber pedido la matriculación definitiva en los 30 días siguientes a empezar a usarlo (60 días si lo traes por traslado de tu residencia habitual a España y te corresponde esa exención).</p>

    <h2>4. Quién lo gestiona</h2>
    <p>Puedes hacer todos los trámites tú, pero casi todo el mundo usa una gestoría, que suele cobrar entre 150 y 300 €. La calculadora incluye una estimación editable para gestoría, ITV, certificado de conformidad, placas y matrícula de exportación.</p>

    <h2>Lo que esta guía no cubre</h2>
    <ul>
      <li>Canarias, Navarra, País Vasco, Ceuta y Melilla: tienen un régimen fiscal distinto.</li>
      <li>Exenciones del impuesto de matriculación (por ejemplo, personas con discapacidad en ciertos supuestos, traslado de residencia, taxis o coches de alquiler).</li>
      <li>Coches de empresa o con destino a una actividad económica.</li>
    </ul>
    <p class="fuente">Fuentes: <a href="https://www.boe.es/buscar/act.php?id=BOE-A-1992-28741" rel="noopener noreferrer">Ley 38/1992, de Impuestos Especiales</a> (arts. 65 y 69-70) · <a href="https://www.boe.es/buscar/act.php?id=BOE-A-1992-28740" rel="noopener noreferrer">Ley 37/1992 del IVA</a> (art. 13) · <a href="https://sede.dgt.gob.es/es/asistentes/asistente-tasas/" rel="noopener noreferrer">Sede electrónica de la DGT</a>. Información orientativa: confirma con tu gestoría o Hacienda.</p>
    <p class="botones"><a class="boton" href="/">Calcular el coste de mi coche</a></p>
  </article>
</div>
