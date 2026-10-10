<?php
declare(strict_types=1);

use function CalculadoraImportacion\Web\e;

/** @var string $titulo */
?>
<div class="contenedor seccion">
  <nav class="migas" aria-label="Migas de pan"><a href="/guias">Guías</a> › ITP</nav>
  <article class="prosa">
    <h1><?= e($titulo) ?></h1>
    <p>El Impuesto sobre Transmisiones Patrimoniales Onerosas (ITP) lo paga el comprador cuando adquiere un bien usado a un particular, también un coche. Está cedido a las comunidades autónomas, que fijan su tipo, y se paga en la de tu residencia, no en la de Alemania ni en la de la matriculación anterior.</p>

    <h2>¿Cuándo se paga?</h2>
    <div class="tabla-scroll" role="region" tabindex="0" aria-label="Tabla: ¿Cuándo se paga?">
    <table>
      <thead><tr><th>Compras…</th><th>Pagas en España</th></tr></thead>
      <tbody>
        <tr><td>Un coche usado a un <strong>particular</strong></td><td>ITP de tu comunidad</td></tr>
        <tr><td>Un coche usado a un <strong>profesional</strong></td><td>Ni ITP ni IVA: el IVA alemán ya va en el precio (régimen general o régimen de bienes usados)</td></tr>
        <tr><td>Un coche <strong>nuevo</strong> (menos de 6 meses o hasta 6.000 km)</td><td>IVA español del 21 % (modelo 309), no ITP</td></tr>
      </tbody>
    </table>
    </div>

    <h2>¿Sobre qué valor?</h2>
    <p>Sobre el valor del coche según las tablas del BOE, no sobre lo que pagaste: el precio medio de tu modelo multiplicado por el porcentaje de su antigüedad. Es el mismo valor que se usa para el impuesto de matriculación (<a href="/guias/impuesto-de-matriculacion">cómo se calcula</a>).</p>

    <h2>¿A qué tipo?</h2>
    <p>Si la comunidad no aprueba otro, rige el 4 % estatal para bienes muebles. Estos son los tipos de 2026 que hemos verificado en la normativa autonómica:</p>
    <div class="tabla-scroll" role="region" tabindex="0" aria-label="Tabla: ¿A qué tipo?">
    <table>
      <thead><tr><th>Comunidad</th><th>Tipo para un turismo usado</th></tr></thead>
      <tbody>
        <tr><td>Comunidad de Madrid</td><td>4 %. Sin cuota si el valor es inferior a 500 € (compra de persona física)</td></tr>
        <tr><td>Castilla y León</td><td>5 %. 8 % si supera los 15 caballos fiscales</td></tr>
        <tr><td>Andalucía</td><td>4 %. 8 % si supera los 15 caballos fiscales. 1 % en turismos con distintivo «0 emisiones»</td></tr>
        <tr><td>Castilla-La Mancha</td><td>6 %</td></tr>
        <tr><td>La Rioja</td><td>4 %</td></tr>
      </tbody>
    </table>
    </div>
    <p class="nota">En Aragón, Asturias, Baleares, Cantabria, Cataluña, la Comunitat Valenciana, Extremadura, Galicia y Murcia hay <strong>tipos o cuotas especiales para vehículos</strong> (por ejemplo, cuotas fijas según la cilindrada) que todavía no hemos incorporado. Por eso, para esas comunidades la calculadora te lo dice en lugar de darte una cifra que podría ser errónea. Tu gestoría o la agencia tributaria de tu comunidad te darán el importe exacto.</p>

    <h2>Cómo se paga</h2>
    <p>Con el modelo de autoliquidación de tu comunidad (el 620 o el 621, según el caso) y en el plazo que ella fije. Normalmente te pedirán el justificante de pago al matricular el coche.</p>
    <p class="fuente">Fuentes: <a href="https://www.boe.es/buscar/act.php?id=BOE-A-1993-25359" rel="noopener noreferrer">Texto refundido del ITP</a> (art. 11) · Hacienda, <a href="https://www.hacienda.gob.es/sgfal/financiacionterritorial/autonomica/capitulo-i-tributacion-autonomica-2026.pdf" rel="noopener noreferrer">«Tributación autonómica. Medidas 2026»</a> · <a href="https://www.boe.es/buscar/act.php?id=BOCL-h-2013-90254" rel="noopener noreferrer">Decreto Legislativo 1/2013 de Castilla y León</a> · <a href="https://www.boe.es/buscar/act.php?id=BOCM-m-2010-90068" rel="noopener noreferrer">Decreto Legislativo 1/2010 de la Comunidad de Madrid</a> · <a href="https://www.juntadeandalucia.es/organismos/atrian/areas/informacion-tributaria/impuestos/preguntas-frecuentes/impuestos-transmisiones-actos/aplicar-tp.html" rel="noopener noreferrer">Agencia Tributaria de Andalucía</a>.</p>
    <p class="botones"><a class="boton" href="/">Calcular mi coche</a></p>
  </article>
</div>
