/* Calculadora de importación · JavaScript propio, sin dependencias ni compilación.
   Todo es mejora progresiva: sin JavaScript, los formularios y las páginas siguen funcionando. */
(function () {
  'use strict';

  var $ = function (selector, raiz) { return (raiz || document).querySelector(selector); };
  var $$ = function (selector, raiz) { return Array.prototype.slice.call((raiz || document).querySelectorAll(selector)); };

  /* ---------- historial en localStorage (siempre dentro de try/catch) ---------- */
  var CLAVE = 'ci_historial_v1';
  var MAX_HISTORIAL = 20;

  function esValido(x) {
    return x && typeof x === 'object' && /^[a-f0-9]{12}$/.test(String(x.id)) &&
      typeof x.titulo === 'string' && typeof x.total === 'number';
  }

  /** Devuelve la lista guardada, o null si el navegador no deja usar el almacenamiento. */
  function leerHistorial() {
    try {
      var texto = window.localStorage.getItem(CLAVE);
      var lista = texto ? JSON.parse(texto) : [];
      return Array.isArray(lista) ? lista.filter(esValido) : [];
    } catch (e) {
      return null;
    }
  }

  function escribirHistorial(lista) {
    try {
      window.localStorage.setItem(CLAVE, JSON.stringify(lista.slice(0, MAX_HISTORIAL)));
      return true;
    } catch (e) {
      return false;
    }
  }

  var formatoEuro = new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR' });
  function euros(n) { return formatoEuro.format(n); }
  function fechaCorta(iso) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || '');
    return m ? m[3] + '/' + m[2] + '/' + m[1] : '';
  }

  /* ---------- errores: llevar el foco al resumen ---------- */
  var resumen = $('[data-resumen-errores]');
  if (resumen) { resumen.focus(); }

  /* ---------- autocompletado de municipios ---------- */
  function sinAcentos(t) { return t.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, ' ').trim(); }
  /** «Madrid (Madrid)» sobra: el paréntesis solo se pone si la provincia se llama distinto (igual que en el servidor). */
  function igualNombre(municipio, provincia) {
    var nombres = municipio.split('/').map(sinAcentos);
    return provincia.split('/').some(function (p) { return nombres.indexOf(sinAcentos(p)) !== -1; });
  }

  function iniciarCombo(contenedor) {
    var entrada = $('input[type="text"]', contenedor);
    var oculto = $('input[type="hidden"]', contenedor);
    if (!entrada || !oculto || !window.fetch) { return; }

    var idLista = 'lista-' + entrada.id;
    var lista = document.createElement('ul');
    lista.className = 'combo__lista';
    lista.id = idLista;
    lista.setAttribute('role', 'listbox');
    lista.setAttribute('aria-label', 'Municipios');
    lista.hidden = true;
    var estado = document.createElement('div');
    estado.className = 'solo-lectores';
    estado.setAttribute('role', 'status');
    estado.setAttribute('aria-live', 'polite');
    contenedor.appendChild(lista);
    contenedor.appendChild(estado);

    entrada.setAttribute('role', 'combobox');
    entrada.setAttribute('aria-autocomplete', 'list');
    entrada.setAttribute('aria-expanded', 'false');
    entrada.setAttribute('aria-controls', idLista);

    var opciones = [];
    var activa = -1;
    var temporizador = null;
    var peticion = null;

    function cerrar() {
      lista.hidden = true;
      entrada.setAttribute('aria-expanded', 'false');
      entrada.removeAttribute('aria-activedescendant');
      activa = -1;
    }

    function marcar(i) {
      var items = $$('li', lista);
      items.forEach(function (li, n) { li.setAttribute('aria-selected', n === i ? 'true' : 'false'); });
      activa = i;
      if (i >= 0 && items[i]) {
        entrada.setAttribute('aria-activedescendant', items[i].id);
        items[i].scrollIntoView({ block: 'nearest' });
      } else {
        entrada.removeAttribute('aria-activedescendant');
      }
    }

    function elegir(i) {
      var o = opciones[i];
      if (!o) { return; }
      entrada.value = o.nombre + (igualNombre(o.nombre, o.provincia) ? '' : ' (' + o.provincia + ')');
      oculto.value = o.ine;
      entrada.removeAttribute('aria-invalid');
      cerrar();
      contenedor.dispatchEvent(new CustomEvent('municipio-elegido', { bubbles: true, detail: { ine: o.ine, texto: entrada.value } }));
    }

    function pintar(resultados) {
      opciones = resultados;
      lista.textContent = '';
      resultados.forEach(function (o, i) {
        var li = document.createElement('li');
        li.id = idLista + '-' + i;
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', 'false');
        var nombre = document.createElement('span');
        nombre.textContent = o.nombre;
        var provincia = document.createElement('small');
        provincia.textContent = o.provincia;
        li.appendChild(nombre);
        li.appendChild(provincia);
        li.addEventListener('mousedown', function (ev) { ev.preventDefault(); elegir(i); });
        lista.appendChild(li);
      });
      var hay = resultados.length > 0;
      lista.hidden = !hay;
      entrada.setAttribute('aria-expanded', hay ? 'true' : 'false');
      estado.textContent = hay ? resultados.length + ' municipios. Usa las flechas para elegir.' : 'Sin resultados';
      activa = -1;
    }

    function buscar() {
      var q = entrada.value.trim();
      if (q.length < 2) { pintar([]); estado.textContent = ''; return; }
      if (peticion) { peticion.abort(); }
      peticion = typeof AbortController === 'function' ? new AbortController() : null;
      fetch('/api/municipios?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, signal: peticion ? peticion.signal : undefined })
        .then(function (r) { return r.ok ? r.json() : { resultados: [] }; })
        .then(function (d) { pintar(Array.isArray(d.resultados) ? d.resultados : []); })
        .catch(function () { /* petición cancelada o sin red: el servidor resolverá el texto al enviar */ });
    }

    entrada.addEventListener('input', function () {
      oculto.value = '';
      clearTimeout(temporizador);
      temporizador = setTimeout(buscar, 160);
    });
    entrada.addEventListener('keydown', function (ev) {
      var abierta = !lista.hidden;
      if (ev.key === 'ArrowDown') {
        ev.preventDefault();
        if (!abierta) { buscar(); return; }
        marcar((activa + 1) % opciones.length);
      } else if (ev.key === 'ArrowUp' && abierta) {
        ev.preventDefault();
        marcar((activa - 1 + opciones.length) % opciones.length);
      } else if (ev.key === 'Enter' && abierta && activa >= 0) {
        ev.preventDefault();
        elegir(activa);
      } else if (ev.key === 'Escape' && abierta) {
        ev.preventDefault();
        cerrar();
      }
    });
    entrada.addEventListener('blur', function () { setTimeout(cerrar, 120); });
  }
  $$('[data-combo="municipios"]').forEach(iniciarCombo);

  /* ---------- destino: «Cambiar» y municipio recordado (solo en este navegador) ---------- */
  var CLAVE_MUNICIPIO = 'ci_municipio_v1';
  function recordarMunicipio(ine, texto) {
    try { window.localStorage.setItem(CLAVE_MUNICIPIO, JSON.stringify({ ine: ine, texto: texto })); } catch (e) { /* sin almacenamiento: no pasa nada */ }
  }
  function municipioRecordado() {
    try {
      var d = JSON.parse(window.localStorage.getItem(CLAVE_MUNICIPIO) || 'null');
      return d && /^\d{5}$/.test(String(d.ine)) && typeof d.texto === 'string' && d.texto.length < 120 ? d : null;
    } catch (e) { return null; }
  }
  var destino = $('[data-destino]');
  if (destino) {
    var nombreDestino = $('[data-destino-nombre]', destino);
    var cambiar = $('.destino__cambiar', destino);
    var icono = $('use', destino);
    var campoIne = $('#f-municipio', destino);
    var campoTexto = $('#f-municipio_texto', destino);
    var marcarElegido = function (ine, texto, cerrar) {
      campoIne.value = ine;
      campoTexto.value = texto;
      nombreDestino.textContent = texto;
      cambiar.textContent = 'Cambiar';
      destino.classList.remove('destino--falta');
      if (icono) { icono.setAttribute('href', '#i-check'); }
      if (cerrar) { destino.open = false; }
    };
    destino.addEventListener('municipio-elegido', function (ev) { marcarElegido(ev.detail.ine, ev.detail.texto, true); });
    var recordado = municipioRecordado();
    if (campoIne && campoTexto && campoIne.value === '' && campoTexto.value === '' && recordado) {
      marcarElegido(recordado.ine, recordado.texto, true);
    }
    var formulario = destino.closest('form');
    if (formulario) {
      formulario.addEventListener('submit', function () {
        if (/^\d{5}$/.test(campoIne.value) && campoTexto.value) { recordarMunicipio(campoIne.value, campoTexto.value); }
      });
    }
  }

  /* ---------- interruptores que recalculan al cambiarlos ---------- */
  $$('[data-autoenviar]').forEach(function (caja) {
    caja.addEventListener('change', function () {
      var f = caja.closest('form');
      if (f && typeof f.requestSubmit === 'function') { f.requestSubmit(); }
    });
    var boton = $('[data-autoenviar-boton]', caja.closest('form') || document);
    if (boton) { boton.hidden = true; }
  });

  /* ---------- campos que dependen de otros ---------- */
  function actualizarDependientes() {
    var comb = $('#f-combustible');
    var electrico = comb && comb.value === 'electrico';
    $$('[data-solo-combustion]').forEach(function (el) { el.hidden = !!electrico; });
    var prof = $('input[name="vendedor"][value="profesional"]');
    if (prof) {
      $$('[data-solo-profesional]').forEach(function (el) { el.hidden = !prof.checked; });
    }
  }
  document.addEventListener('change', function (ev) {
    if (ev.target && (ev.target.id === 'f-combustible' || ev.target.name === 'vendedor')) { actualizarDependientes(); }
  });
  actualizarDependientes();

  /* ---------- botones de la página de resultado ---------- */
  $$('[data-imprimir]').forEach(function (b) {
    if (typeof window.print !== 'function') { return; }
    b.hidden = false;
    b.addEventListener('click', function () { window.print(); });
  });

  function copiarTexto(texto) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(texto);
    }
    return new Promise(function (resolver, rechazar) {
      var area = document.createElement('textarea');
      area.value = texto;
      area.setAttribute('readonly', '');
      area.style.position = 'fixed';
      area.style.opacity = '0';
      document.body.appendChild(area);
      area.select();
      try { document.execCommand('copy') ? resolver() : rechazar(new Error('copia')); } catch (e) { rechazar(e); }
      document.body.removeChild(area);
    });
  }
  $$('[data-copiar]').forEach(function (b) {
    var origen = $(b.getAttribute('data-copiar'));
    var aviso = $('[data-estado-copia]');
    if (!origen) { return; }
    b.hidden = false;
    b.addEventListener('click', function () {
      copiarTexto(origen.textContent.trim()).then(function () {
        if (aviso) { aviso.textContent = 'Enlace copiado.'; }
      }, function () {
        if (aviso) { aviso.textContent = 'No se ha podido copiar: selecciona el enlace y cópialo a mano.'; }
      });
    });
  });

  $$('[data-guardar-historial]').forEach(function (b) {
    var datos;
    try { datos = JSON.parse(b.getAttribute('data-guardar-historial')); } catch (e) { return; }
    if (!esValido(datos)) { return; }
    var aviso = $('[data-estado-historial]');
    var lista = leerHistorial();
    if (lista === null) { return; } // sin almacenamiento: el botón sigue oculto y la página funciona igual
    b.hidden = false;
    if (lista.some(function (x) { return x.id === datos.id; })) { b.textContent = 'Guardado en tu historial'; }
    b.addEventListener('click', function () {
      var actual = leerHistorial() || [];
      actual = actual.filter(function (x) { return x.id !== datos.id; });
      actual.unshift(datos);
      if (escribirHistorial(actual)) {
        b.textContent = 'Guardado en tu historial';
        if (aviso) { aviso.innerHTML = ''; aviso.appendChild(document.createTextNode('Guardado. Lo encontrarás en ')); var a = document.createElement('a'); a.href = '/historial'; a.textContent = 'tu historial'; aviso.appendChild(a); aviso.appendChild(document.createTextNode('.')); }
      } else if (aviso) {
        aviso.textContent = 'No se ha podido guardar en este navegador (¿navegación privada?). El cálculo sigue disponible en esta dirección.';
      }
    });
  });

  /* ---------- página del historial ---------- */
  var panel = $('[data-historial]');
  if (panel) {
    panel.hidden = false;
    var ul = $('[data-historial-lista]', panel);
    var vacio = $('[data-historial-vacio]', panel);
    var noDisponible = $('[data-historial-no-disponible]', panel);
    var acciones = $('[data-historial-acciones]', panel);
    var botonComparar = $('[data-comparar]', panel);
    var estadoHistorial = $('[data-historial-estado]', panel);

    var pintarHistorial = function () {
      var lista = leerHistorial();
      ul.textContent = '';
      if (lista === null) { noDisponible.hidden = false; vacio.hidden = true; acciones.hidden = true; return; }
      vacio.hidden = lista.length > 0;
      acciones.hidden = lista.length === 0;
      lista.forEach(function (x) {
        var li = document.createElement('li');
        var casilla = document.createElement('div');
        casilla.className = 'casilla';
        var cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.id = 'cmp-' + x.id;
        cb.value = x.id;
        var etiqueta = document.createElement('label');
        etiqueta.setAttribute('for', cb.id);
        etiqueta.textContent = x.titulo + (x.fecha ? ' · ' + fechaCorta(x.fecha) : '');
        casilla.appendChild(cb);
        casilla.appendChild(etiqueta);
        var importe = document.createElement('span');
        importe.className = 'importe';
        importe.textContent = euros(x.total) + (x.completo === false ? ' (parcial)' : '');
        var abrir = document.createElement('a');
        abrir.className = 'boton boton--secundario boton--pequeno';
        abrir.href = '/c/' + x.id;
        abrir.textContent = 'Abrir';
        var quitar = document.createElement('button');
        quitar.type = 'button';
        quitar.className = 'boton boton--secundario boton--pequeno';
        quitar.textContent = 'Quitar';
        quitar.setAttribute('aria-label', 'Quitar ' + x.titulo + ' del historial');
        quitar.addEventListener('click', function () {
          escribirHistorial((leerHistorial() || []).filter(function (y) { return y.id !== x.id; }));
          pintarHistorial();
          estadoHistorial.textContent = 'Quitado del historial.';
        });
        li.appendChild(casilla);
        li.appendChild(importe);
        li.appendChild(abrir);
        li.appendChild(quitar);
        ul.appendChild(li);
      });
      actualizarSeleccion();
    };

    var actualizarSeleccion = function () {
      var marcadas = $$('input[type="checkbox"]:checked', ul);
      $$('input[type="checkbox"]', ul).forEach(function (cb) { cb.disabled = !cb.checked && marcadas.length >= 3; });
      botonComparar.disabled = marcadas.length < 2;
    };
    ul.addEventListener('change', actualizarSeleccion);
    botonComparar.addEventListener('click', function () {
      var ids = $$('input[type="checkbox"]:checked', ul).map(function (cb) { return cb.value; }).slice(0, 3);
      if (ids.length >= 2) { window.location.href = '/comparar?c=' + ids.join(','); }
    });
    $('[data-vaciar]', panel).addEventListener('click', function () {
      if (window.confirm('¿Vaciar todo el historial de este navegador?')) {
        escribirHistorial([]);
        pintarHistorial();
        estadoHistorial.textContent = 'Historial vaciado.';
      }
    });
    pintarHistorial();
  }
})();
