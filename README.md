# Calculadora de importación

Coste real de importar a España un coche de mobile.de: precio, impuesto de matriculación (IEDMT), ITP o IVA, IVTM del municipio, tasas de la DGT,
ITV, gestoría y transporte. Se pega el enlace del anuncio (o el texto copiado de la página), se elige el municipio donde se va a matricular y sale el desglose,
con la **fuente y el cálculo de cada cifra**. Solo web: sin cuentas, sin pagos, sin extensión del navegador, sin APIs externas.

Instalación en una VM: `bash install.sh` (un script con las comprobaciones de seguridad; empieza con `--solo-comprobar`) o, a mano, [`deploy/INSTALL.md`](deploy/INSTALL.md). Actualizar: `git -C /opt/apps/calculadora-importacion pull`.

## Estado

| Fase | Qué es | Estado |
|---|---|---|
| 1 | Motor de cálculo y tablas fiscales (`src/Calculo`, `data/fiscal/2026`) | Hecha y verificada con las normas (ver abajo) |
| 2 | Precios medios del BOE en SQLite y emparejador de la fila del coche (`src/Boe`, `data/boe`) | Hecha: 61.634 filas del anexo I (turismos y todo terreno) |
| 3 | Lectura del anuncio por enlace o por texto pegado (`src/Anuncio`) | Hecha **con una limitación importante** (abajo) |
| 4 | Página web | Hecha: axe sin violaciones (claro y oscuro) y Lighthouse 100/100/100/100 en portada y guías, servidas por nginx + PHP-FPM (en las páginas de resultado, SEO baja a propósito por llevar `noindex`) |
| 5 | nginx, PHP-FPM, instalación, CI y documentación | Hecha y probada en un contenedor con nginx 1.24 + PHP-FPM 8.3 |

### Limitaciones que conviene conocer

- **mobile.de bloquea las lecturas automáticas desde servidores** (Akamai responde `403 Access denied`; así ocurrió desde el entorno de desarrollo). La aplicación hace **una sola
  petición educada y no intenta saltarse el bloqueo**; si falla, explica por qué y ofrece pegar el texto del anuncio, que funciona igual. En la VM puede que la lectura por enlace
  funcione o no: depende de si mobile.de acepta esa IP.
- **No se ha podido validar la lectura contra páginas reales de mobile.de.** Las 42 fixtures de `tests/fixtures/anuncios/` son sintéticas y están dicho claramente en su
  [README](tests/fixtures/anuncios/README.md). Entienden varias estructuras de ficha (listas de definiciones, pares de `<div>`, tablas, JSON-LD, texto copiado) y rótulos en alemán,
  inglés y español, pero **hace falta comprobarlas con 2 o 3 anuncios reales**: ver «Arreglar la lectura de anuncios».
- **ITP de 9 comunidades sin incorporar** (Aragón, Asturias, Baleares, Cantabria, Cataluña, Comunitat Valenciana, Extremadura, Galicia y Murcia tienen tipos o cuotas especiales para
  vehículos que aún no se han leído de su norma). Para ellas el motor **no calcula el ITP y lo dice**, en lugar de dar una cifra errónea. Verificados: Madrid, Castilla y León, Andalucía,
  Castilla-La Mancha y La Rioja. Quedan fuera por régimen fiscal distinto: Canarias, Navarra, País Vasco, Ceuta y Melilla (el motor las rechaza con un mensaje claro).
- **IVTM**: verificadas las ordenanzas de Madrid y Salamanca. En el resto de municipios se aplica la tarifa mínima legal y se avisa.
- **Estimaciones editables** (no son normas): ITV, COC, placas, gestoría, matrícula de exportación y los parámetros de transporte. Cada partida lo indica y se pueden cambiar en el resultado.
- **Antes de publicar**: rellenar los datos del titular del aviso legal (`deploy/INSTALL.md`, paso 7). Todo el cálculo es **orientativo**; la web lo dice en cada página.

`php scripts/verificar_datos.php` lista en cada momento todo lo que sigue marcado «verificar» en las tablas.

## Cómo funciona

```
enlace o texto del anuncio ──► Extractor ──► Analizador ──► campos con valor, confianza y origen ──► formulario que la persona revisa
                                                                    │
marca + modelo + versión + año + cilindrada + potencia + combustible ─► Emparejador ─► 3 filas del BOE con puntuación ─► valor venal
                                                                                                                         │
valor venal + CO₂ + CVF + vendedor + municipio + código postal ──► Calculadora (funciones puras) ──► partidas con fuente y explicación
```

- **El motor** (`src/Calculo`) son funciones puras: reciben la entrada y las tablas como arrays y devuelven el resultado; no leen disco ni red, así que se prueba con facilidad. Cada partida trae
  `concepto, importe, base, tipo, fuente, url, explicacion` y, si no se puede calcular, `estado = no_calculable` con el motivo: **nunca inventa un valor**.
- **La lectura** da a cada dato una confianza (0 a 1) y un origen (`estructurado` JSON-LD 0,95 · `etiqueta` rótulo conocido 0,90 · `título` 0,55–0,85 · `regex` 0,5–0,6 · `derivado` · `BOE`).
  Lo que está por debajo de 0,8 o no se encuentra se marca con «Revisa» en el formulario. La persona tiene siempre la última palabra.
- **El emparejador** compara nombre, año dentro del periodo comercial, cilindrada (±1 %/5 %/10 %), potencia y combustible con las filas del BOE, y propone las 3 mejores con el porqué. En el resultado
  se puede cambiar de fila y se ve cuánto cambia el total con cada una.

## Estructura

```
public/       única carpeta servida por nginx: index.php (controlador frontal) y assets/ (CSS, JS, icono)
src/
  Calculo/    motor de cálculo (funciones puras)
  Datos/      lector de las tablas de data/ (la única parte del cálculo que toca el disco)
  Boe/        base del BOE: normalizador, marcas, repositorio SQLite (solo lectura) y emparejador
  Anuncio/    lectura de anuncios: enlaces, diccionarios, extractor, analizador y descargador
  Web/        aplicación web: Peticion → App → Respuesta, CSRF, SQLite de ejecución, formularios
  Vistas/     plantillas PHP (todo lo que se imprime pasa por e())
data/
  fiscal/<año>/        tipos y tablas fiscales versionados por año; cada valor con su fuente y la marca «verificar»
  boe/<año>.sqlite     anexo I de la Orden de precios medios (solo lectura)
  diccionarios/        marcas.json, modelos/<marca>.json, etiquetas.json, combustibles.json
  mobile_de_selectores.json   pistas CSS para leer anuncios (sin validar con páginas reales)
  plz_de.json, municipios_es.json   coordenadas de códigos postales alemanes y municipios españoles
  sitio.json           datos del titular por defecto (vacíos); los reales van en /var/lib/…/sitio.json
install.sh    instalador para la VM (comprueba, instala, deshace si falla y desinstala)
deploy/       nginx, pool de PHP-FPM e INSTALL.md
scripts/      herramientas OFFLINE (extraer el BOE, generar diccionarios y geografía, analizar un anuncio)
tests/        runner propio sin dependencias: php tests/run.php
```

Requisitos: PHP 8.1 o superior con `curl`, `dom`, `pdo_sqlite`, `mbstring` y `json`. Sin Composer, sin `node_modules`, sin pasos de compilación. El código no escribe nunca en el repositorio:
los datos de ejecución (cálculos compartidos, clave de seguridad, registros) van a `/var/lib/calculadora-importacion` (`Config::DIR_VAR_POR_DEFECTO`).

## Desarrollo en local

```bash
CALCULADORA_VAR=/tmp/calculadora php -S 127.0.0.1:8082 -t public public/index.php     # http://127.0.0.1:8082
php tests/run.php                  # todas las pruebas (≈ 110)
php tests/run.php IEDMT            # solo las que contengan «IEDMT» en el nombre
php scripts/verificar_datos.php    # qué sigue pendiente de verificar en las tablas
php scripts/analizar_anuncio.php anuncio.html          # qué datos saca el lector de una página guardada o de un texto copiado
```

Las pruebas **no hacen ninguna petición de red** (la descarga se sustituye por un descargador falso). La integración continua (`.github/workflows/tests.yml`) ejecuta `php -l` sobre todos los ficheros
y `php tests/run.php` con PHP 8.1, 8.2 y 8.3, y comprueba que las fixtures del repositorio son las que genera su script.

Uso del motor sin la web:

```php
require 'src/autoload.php';
use CalculadoraImportacion\Datos\Tablas;
use CalculadoraImportacion\Calculo\Calculadora;

$tablas = Tablas::cargar(__DIR__ . '/data', 2026);
$resultado = Calculadora::calcular($entrada, $tablas);   // ver el docblock de src/Calculo/Entrada.php
```

## Actualización anual de los datos

Se hace en el repositorio (no en la VM); después, `git pull` en la VM. El año de las tablas lo decide la fecha de hoy: si todavía no existe `data/fiscal/<año nuevo>`, se sigue usando la última disponible (y la base del BOE de ese mismo año).

1. **Precios medios del BOE** (la Orden sale en diciembre o enero):
   ```bash
   # necesita poppler-utils (pdftotext) y Python 3; no se ejecuta nunca en producción
   python3 -I scripts/boe/extraer_boe.py orden.pdf data/boe/2027.sqlite 2027 --url https://www.boe.es/…
   python3 -I scripts/boe/generar_diccionarios.py data/boe/2027.sqlite data/diccionarios
   ```
   El extractor lee las **coordenadas** de cada palabra del PDF (columnas por posición, nombres partidos en varias líneas) y termina con «0 problemas» si todo cuadra. Si la Orden cambia de maquetación,
   los parámetros están al principio de `scripts/boe/extraer_boe.py` (columnas, página de inicio del anexo, alto de la cabecera). Después, actualiza en `tests/Boe/BoeTest.php` el año y el número de filas (B3).
   `generar_diccionarios.py` solo **añade** marcas nuevas a `marcas.json` (conserva los alias escritos a mano) y regenera los modelos.
2. **Tablas fiscales**: copia `data/fiscal/2026` a `data/fiscal/<año>` y revisa, con su fuente en cada valor (el campo `fuente`/`url` y `verificar`):
   - `depreciacion.json`: tabla del anexo IV de la misma Orden.
   - `iedmt.json`: tipos por CO₂ y tipos autonómicos (Ley 38/1992, art. 70) y fórmula de minoración (Orden HAC/1501).
   - `itp.json`: tipo y reglas de cada comunidad para vehículos usados (su ley de tributos cedidos). **Aquí está lo pendiente de las 9 comunidades.** `reglas_vehiculos: "pendientes"` hace que el motor no calcule.
   - `ivtm_tarifas.json` (Ley de Haciendas Locales, art. 95) y `ivtm_municipios.json` (ordenanza de cada municipio: coeficiente o cuotas propias). Hoy solo Madrid y Salamanca están verificados.
   - `costes_fijos.json` (tasa 1.1 de la DGT verificada; el resto son estimaciones de mercado a revisar), `transporte.json` (precios de combustible, factor de carretera…), `iva.json`, `ccaa.json`.
   - `php scripts/verificar_datos.php <año>` debe ir bajando la lista de pendientes. Añade el caso de pruebas correspondiente en `tests/` (cada regla fiscal tiene su prueba con el cálculo a mano).
3. **Coordenadas** (casi nunca cambian): `python3 -I scripts/geo/generar_geo.py <descargas> data`.
4. `php tests/run.php` en verde, commit y push. En la VM: `git pull`.

## Ampliar los diccionarios y arreglar la lectura de anuncios

Todo lo que depende de cómo escribe mobile.de está en ficheros de datos, no en código:

- **Una marca con otro nombre** (p. ej. «Mercedes-Benz» → `MERCEDES` en el BOE): `data/diccionarios/marcas.json` → `alias` de esa marca.
- **Un rótulo nuevo** («Kilometerstand» escrito de otra forma, otro idioma): `data/diccionarios/etiquetas.json` → lista del campo (`km`, `matriculacion`, `potencia`, `cilindrada`, `combustible`, `co2`, `precio`, `ubicacion`…).
  Se comparan sin mayúsculas, sin notas al pie (¹ ²), sin lo que va entre paréntesis y sin los dos puntos.
- **Un combustible nuevo**: `data/diccionarios/combustibles.json`.
- **Una pista de selector CSS** para el título, el precio, el vendedor o la ubicación: `data/mobile_de_selectores.json` (solo selectores sencillos: etiqueta, `[atributo='valor']`, `[atributo*='valor']`, `.clase`, `#id`, espacio y `>`).
  Un selector equivocado no estropea nada: la lectura sigue con los rótulos.
- Los términos que el diccionario no reconoce (un combustible, una marca) se apuntan en `/var/lib/calculadora-importacion/registro.log` como `termino_desconocido`, sin IP ni enlaces, para saber qué añadir.

**Cómo validar con anuncios reales** (lo que falta): en el navegador abre un anuncio de mobile.de y guarda la página (`Guardar como…`, «Página web, solo HTML») o copia todo el texto (Ctrl+A, Ctrl+C). Luego
`php scripts/analizar_anuncio.php anuncio.html` enseña cada dato con su confianza y origen. Lo que falte se añade a los diccionarios de arriba. Copia el fichero (sin nombres ni teléfonos) a
`tests/fixtures/anuncios/` con su `.json` esperado (`--json` da el punto de partida; revísalo contra la página) y `php tests/run.php fixtures` las recoge solas. Con 2 o 3 páginas reales se ve enseguida si los rótulos
y el orden de los datos coinciden.

## Seguridad y privacidad

- Solo se descargan enlaces **https** de `suchen.mobile.de`, `www.mobile.de` y `m.mobile.de`; del enlace solo se conserva el número del anuncio y la URL se reconstruye (protección SSRF). Una petición, 10 s, 3 MB, sin cookies,
  redirecciones solo dentro de esos hosts, `User-Agent` que dice quiénes somos, y **nunca** se cambian cabeceras ni IP para esquivar un bloqueo.
- Límites: 10 lecturas por minuto y por IP y 1 por segundo en total (SQLite); 20 cálculos por minuto y por IP; nginx limita las peticiones por IP. Las IP se guardan solo como hash y se descartan a los pocos minutos.
- Todo lo que se imprime pasa por `htmlspecialchars`; CSP sin `unsafe-inline` (ni estilos ni manejadores en línea); formularios con CSRF sin sesiones (cookie técnica + HMAC); consultas SQLite preparadas.
- No hay secretos en el repositorio. Los datos del titular y la clave de seguridad están en `/var/lib/calculadora-importacion`.
- De un anuncio solo se guardan **los datos leídos** (24 h, para no volver a pedirlo), nunca su HTML. Los cálculos compartidos se conservan un año. Detalle en la página de privacidad de la propia web.

## Créditos de los datos

Precios medios y depreciación: Orden HAC/1501/2025 (BOE). Tipos, reglas y tasas: BOE, Agencia Tributaria, DGT y haciendas autonómicas y locales (cada valor lo cita en `data/fiscal/`).
Coordenadas de códigos postales: [GeoNames](https://www.geonames.org/) (CC BY 4.0), a través de [zauberware/postal-codes-json-xml-csv](https://github.com/zauberware/postal-codes-json-xml-csv).
Municipios y provincias: INE, a través de [codeforspain/ds-organizacion-administrativa](https://github.com/codeforspain/ds-organizacion-administrativa).
Esta web es independiente y no está afiliada a mobile.de ni a ninguna administración.
