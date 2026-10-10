<?php
declare(strict_types=1);

use function CalculadoraImportacion\Web\e;

/** @var string $titulo */
?>
<div class="contenedor seccion">
  <nav class="migas" aria-label="Migas de pan"><a href="/guias">Guías</a> › Impuesto de matriculación</nav>
  <article class="prosa">
    <h1><?= e($titulo) ?></h1>
    <p>El Impuesto Especial sobre Determinados Medios de Transporte, el «impuesto de matriculación», se paga una vez, en la primera matriculación definitiva del coche en España. También lo pagan los coches importados de la UE, aunque ya estuvieran matriculados en otro país. Se presenta con el modelo 576.</p>

    <h2>El tipo depende del CO₂</h2>
    <p>Según las emisiones oficiales de CO₂ (ciclo WLTP) que constan en la ficha técnica:</p>
    <table>
      <thead><tr><th>Emisiones</th><th>Tipo estatal</th></tr></thead>
      <tbody>
        <tr><td>Hasta 120 g/km (y los eléctricos puros)</td><td>0 %</td></tr>
        <tr><td>Más de 120 y menos de 160 g/km</td><td>4,75 %</td></tr>
        <tr><td>160 o más, y menos de 200 g/km</td><td>9,75 %</td></tr>
        <tr><td>200 g/km o más</td><td>14,75 %</td></tr>
      </tbody>
    </table>
    <p>Las comunidades autónomas pueden subir estos tipos hasta un 15 %. En 2026 lo han hecho solo en el tramo de 200 g/km o más: <strong>16 %</strong> en Asturias, Baleares, Cataluña y la Comunitat Valenciana, <strong>15,9 %</strong> en Murcia y <strong>15 %</strong> en Cantabria. En Andalucía y en el resto, el tipo es el estatal. Si el CO₂ no se acredita con el certificado del fabricante, la ley aplica el tipo más alto.</p>

    <h2>La base: ¿sobre qué importe se paga?</h2>
    <ul>
      <li><strong>Coche nuevo</strong> (menos de 6 meses o hasta 6.000 km): la base es el precio sin IVA alemán.</li>
      <li><strong>Coche usado</strong>: la base es su <em>valor de mercado</em>, que se calcula con la tabla de precios medios del BOE y no con lo que pagaste.</li>
    </ul>

    <h2>Cómo se calcula el valor de un usado</h2>
    <p>Cada año, una orden del Ministerio de Hacienda publica el precio medio de cada modelo. Para 2026, la <a href="https://www.boe.es/diario_boe/txt.php?id=BOE-A-2025-26357" rel="noopener noreferrer">Orden HAC/1501/2025</a>. Ese precio se multiplica por un porcentaje que baja con la antigüedad del coche:</p>
    <table>
      <thead><tr><th>Años de uso</th><th>Porcentaje</th><th>Años de uso</th><th>Porcentaje</th></tr></thead>
      <tbody>
        <tr><td>Hasta 1</td><td>100 %</td><td>Más de 6, hasta 7</td><td>34 %</td></tr>
        <tr><td>Más de 1, hasta 2</td><td>84 %</td><td>Más de 7, hasta 8</td><td>28 %</td></tr>
        <tr><td>Más de 2, hasta 3</td><td>67 %</td><td>Más de 8, hasta 9</td><td>24 %</td></tr>
        <tr><td>Más de 3, hasta 4</td><td>56 %</td><td>Más de 9, hasta 10</td><td>19 %</td></tr>
        <tr><td>Más de 4, hasta 5</td><td>47 %</td><td>Más de 10, hasta 11</td><td>17 %</td></tr>
        <tr><td>Más de 5, hasta 6</td><td>39 %</td><td>Más de 11, hasta 12</td><td>13 %</td></tr>
        <tr><td></td><td></td><td>Más de 12</td><td>10 %</td></tr>
      </tbody>
    </table>

    <h2>El descuento por venir ya matriculado en el extranjero</h2>
    <p>Un coche usado que ya estuvo matriculado en otro país lleva incorporados impuestos indirectos en su valor. La orden permite quitarlos con esta fórmula:</p>
    <p class="nota"><strong>Base = valor de mercado ÷ (1 + tipo de IVA + tipo del impuesto de matriculación)</strong>, con los tipos expresados en tanto por uno (IVA 21 % = 0,21).</p>
    <p><strong>Ejemplo.</strong> Un coche de 5 años cuyo precio medio en el BOE es 38.000 €, con 170 g/km de CO₂ en la península:</p>
    <ol>
      <li>Valor de mercado: 38.000 € × 39 % = <strong>14.820,00 €</strong>.</li>
      <li>Tipo: 160 g/km o más y menos de 200 → <strong>9,75 %</strong>.</li>
      <li>Base: 14.820 ÷ (1 + 0,21 + 0,0975) = <strong>11.334,61 €</strong>.</li>
      <li>Cuota: 11.334,61 × 9,75 % = <strong>1.105,12 €</strong>.</li>
    </ol>
    <p class="nota">En la calculadora ves este mismo desglose para tu coche, con la norma de cada paso.</p>

    <h2>Lo que no está en la calculadora</h2>
    <p>Las exenciones y reducciones de la ley (artículo 66: por ejemplo, para personas con discapacidad en determinados supuestos) no se aplican. Si crees que tienes derecho a alguna, consúltalo con tu gestoría.</p>
    <p class="fuente">Fuentes: <a href="https://www.boe.es/buscar/act.php?id=BOE-A-1992-28741" rel="noopener noreferrer">Ley 38/1992, de Impuestos Especiales</a> (arts. 65, 69 y 70) · <a href="https://www.boe.es/diario_boe/txt.php?id=BOE-A-2025-26357" rel="noopener noreferrer">Orden HAC/1501/2025</a> (anexo IV y artículo 5) · <a href="https://sede.agenciatributaria.gob.es/Sede/vehiculos-embarcaciones/primera-matriculacion-medios-transporte/son-tipos-impuesto-aplicar-caso/tipos-impositivos.html" rel="noopener noreferrer">Agencia Tributaria: tipos impositivos</a>.</p>
    <p class="botones"><a class="boton" href="/">Calcular mi coche</a></p>
  </article>
</div>
