<?php
declare(strict_types=1);
?>
<div class="contenedor estrecho">
  <nav class="migas" aria-label="Migas de pan"><a href="/">Inicio</a> › Historial</nav>
  <h1>Mi historial</h1>
  <p class="suave">Los cálculos que guardes aparecen aquí. Se almacenan solo en este navegador: no hay cuenta ni se envían a ningún sitio. Puedes comparar hasta 3 coches.</p>

  <noscript><div class="alerta alerta--info"><span class="alerta__icono" aria-hidden="true">ℹ</span><p>El historial necesita JavaScript. Mientras tanto, puedes comparar coches pegando los enlaces de sus cálculos: <code>/comparar?c=ID1,ID2</code>.</p></div></noscript>

  <div data-historial hidden>
    <p data-historial-vacio hidden>Aún no has guardado ningún cálculo. Haz uno desde <a href="/">el inicio</a> y pulsa «Guardar en mi historial» en el resultado.</p>
    <p data-historial-no-disponible hidden class="alerta alerta--atencion">Tu navegador no permite guardar datos en este sitio (por ejemplo, en navegación privada), así que no podemos mostrar un historial. La calculadora sigue funcionando con normalidad.</p>
    <ul class="lista-historial" data-historial-lista></ul>
    <div class="botones acciones" data-historial-acciones hidden>
      <button class="boton" type="button" data-comparar disabled>Comparar los seleccionados</button>
      <button class="boton boton--secundario" type="button" data-vaciar>Vaciar el historial</button>
    </div>
    <p class="estado-copia" role="status" aria-live="polite" data-historial-estado></p>
  </div>
</div>
