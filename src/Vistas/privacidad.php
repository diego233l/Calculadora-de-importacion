<?php
declare(strict_types=1);

use function CalculadoraImportacion\Web\e;

/** @var array $sitio */
/** @var int $dias */
$dato = static fn(string $clave): string => trim((string) ($sitio[$clave] ?? ''));
?>
<div class="contenedor seccion">
  <article class="prosa">
    <h1>Política de privacidad</h1>
    <p>Esta calculadora está pensada para funcionar sin cuentas, sin registro y sin pedirte datos personales. Aquí explicamos qué ocurre exactamente con la información que usas.</p>

    <h2>Responsable</h2>
    <p><?= $dato('titular') !== '' ? e($dato('titular')) : 'Titular pendiente de completar' ?><?= $dato('nif') !== '' ? ' · NIF ' . e($dato('nif')) : '' ?>
      <?= $dato('domicilio') !== '' ? ' · ' . e($dato('domicilio')) : '' ?>.
      Contacto: <?= $dato('email') !== '' ? '<a href="mailto:' . e($dato('email')) . '">' . e($dato('email')) . '</a>' : 'pendiente de completar' ?>.</p>

    <h2>Qué datos tratamos y para qué</h2>
    <div class="tabla-scroll" role="region" tabindex="0" aria-label="Tabla: Qué datos tratamos y para qué">
    <table>
      <thead><tr><th>Dato</th><th>Para qué</th><th>Cuánto tiempo</th></tr></thead>
      <tbody>
        <tr>
          <td>Los datos del coche que escribes (marca, matriculación, kilómetros, precio, municipio, enlace al anuncio…)</td>
          <td>Calcular el coste y mostrarte el resultado. Al calcular se guarda una copia con un identificador aleatorio para poder enseñártela y compartirla.</td>
          <td><?= e($dias) ?> días; después se borra</td>
        </tr>
        <tr>
          <td>Una cookie técnica de seguridad (<code>ci_csrf</code>)</td>
          <td>Comprobar que los formularios los envías tú desde esta web (protección frente a envíos falsificados). No identifica a la persona ni sirve para seguirla.</td>
          <td>Hasta que cierres el navegador</td>
        </tr>
        <tr>
          <td>Tu dirección IP</td>
          <td>Limitar el número de cálculos por minuto para evitar abusos. Solo se guarda un código irreversible (hash), nunca la IP en claro, y se descarta pasados unos minutos. El servidor web puede registrar la IP en sus registros de acceso por motivos de seguridad.</td>
          <td>Minutos (contador) · según la configuración del servidor (registros de acceso)</td>
        </tr>
        <tr>
          <td>Tu historial y la comparación de coches</td>
          <td>Se guardan únicamente en tu navegador (almacenamiento local). No se envían a nuestros servidores y puedes borrarlos cuando quieras desde la página «Historial».</td>
          <td>Hasta que los borres</td>
        </tr>
      </tbody>
    </table>
    </div>
    <p>No usamos cookies de analítica, publicidad ni seguimiento, ni cargamos recursos de terceros: el autocompletado de municipios consulta a nuestro propio servidor. Como las cookies que usamos son estrictamente técnicas, no necesitan tu consentimiento.</p>

    <h2>Enlaces para compartir</h2>
    <p>Cada cálculo tiene una dirección con un identificador largo y aleatorio. <strong>Cualquiera que tenga esa dirección puede ver el cálculo</strong>, que contiene los datos del coche pero ningún dato tuyo identificativo (ni nombre ni correo). Los buscadores no deben indexarlo. Comparte el enlace solo con quien quieras.</p>

    <h2>Cesiones</h2>
    <p>No vendemos ni cedemos datos a terceros. No hay comunicación de datos a otros países.</p>

    <h2>Tus derechos</h2>
    <p>Puedes pedir acceso, rectificación, supresión, oposición, limitación o portabilidad de tus datos escribiendo al contacto indicado arriba, indicando la dirección del cálculo que quieres que borremos. Si crees que no hemos tratado bien tus datos, puedes reclamar ante la <a href="https://www.aepd.es/" rel="noopener noreferrer">Agencia Española de Protección de Datos</a>.</p>
  </article>
</div>
