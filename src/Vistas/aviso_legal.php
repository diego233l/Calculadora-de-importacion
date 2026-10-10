<?php
declare(strict_types=1);

use function CalculadoraImportacion\Web\e;

/** @var array $sitio */
$dato = static fn(string $clave): string => trim((string) ($sitio[$clave] ?? ''));
?>
<div class="contenedor seccion">
  <article class="prosa">
    <h1>Aviso legal</h1>

    <h2>Titular del sitio</h2>
    <p>En cumplimiento de la Ley 34/2002, de servicios de la sociedad de la información y de comercio electrónico (LSSI-CE), se informa de que el titular de este sitio web es:</p>
    <ul>
      <li><strong>Titular:</strong> <?= $dato('titular') !== '' ? e($dato('titular')) : 'Pendiente de completar por el titular del sitio' ?></li>
      <li><strong>NIF:</strong> <?= $dato('nif') !== '' ? e($dato('nif')) : 'Pendiente de completar' ?></li>
      <li><strong>Domicilio:</strong> <?= $dato('domicilio') !== '' ? e($dato('domicilio')) : 'Pendiente de completar' ?></li>
      <li><strong>Correo electrónico de contacto:</strong> <?= $dato('email') !== '' ? '<a href="mailto:' . e($dato('email')) . '">' . e($dato('email')) . '</a>' : 'Pendiente de completar' ?></li>
    </ul>

    <h2>Objeto y naturaleza orientativa</h2>
    <p>Este sitio ofrece una calculadora que estima el coste de importar a España un coche anunciado en mobile.de. <strong>Los resultados son estimaciones orientativas, no una liquidación tributaria ni asesoramiento fiscal.</strong> Confirma siempre las cifras con tu gestoría, con la Agencia Tributaria o con la administración de tu comunidad autónoma antes de tomar decisiones de compra.</p>
    <p>Las cifras se calculan con datos de normativa y tablas oficiales (BOE, Agencia Tributaria, DGT y haciendas autonómicas y locales) que se actualizan una vez al año; algunos valores son estimaciones de mercado editables y así se indica en cada partida. Pueden existir errores, cambios normativos posteriores o circunstancias personales (exenciones, bonificaciones, régimen foral o canario) que la calculadora no contempla.</p>

    <h2>Limitación de responsabilidad</h2>
    <p>El titular no se hace responsable de las decisiones que el usuario adopte a partir de la información de este sitio ni de las diferencias entre las estimaciones y los importes que finalmente liquiden las administraciones o los profesionales. Tampoco responde de la disponibilidad continuada del servicio.</p>

    <h2>Relación con terceros</h2>
    <p>Este sitio es independiente y no está afiliado, patrocinado ni respaldado por mobile.de, por la Agencia Tributaria ni por ninguna otra administración. «mobile.de» es una marca de su titular. Los enlaces a sitios de terceros (BOE, administraciones, etc.) se ofrecen como fuente de la información; su contenido no es responsabilidad de este sitio.</p>

    <h2>Propiedad intelectual</h2>
    <p>El diseño, los textos y el código de este sitio pertenecen a su titular. Las coordenadas geográficas proceden de <a href="https://www.geonames.org/" rel="noopener noreferrer">GeoNames</a> (licencia <a href="https://creativecommons.org/licenses/by/4.0/" rel="noopener noreferrer">CC BY 4.0</a>) y la relación de municipios, del Instituto Nacional de Estadística. La normativa citada es de dominio público.</p>

    <h2>Legislación y jurisdicción</h2>
    <p>Este aviso se rige por la legislación española. Para cualquier controversia serán competentes los juzgados y tribunales que correspondan conforme a la normativa de consumidores y usuarios.</p>
  </article>
</div>
