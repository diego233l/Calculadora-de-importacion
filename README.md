# Calculadora de importación

Coste real de importar a España un coche de mobile.de: precio, impuesto de matriculación (IEDMT),
ITP o IVA, IVTM del municipio, tasas de la DGT, ITV, gestoría y transporte. Cada cifra muestra su
fuente y su cálculo.

> **Estado:** motor de cálculo (Fase 1) y página web (Fase 4) hechos. De momento el usuario escribe los datos del
> coche y el precio medio del BOE a mano. Pendientes: Fase 2 (datos del BOE), 3 (lectura del anuncio) y
> 5 (instalación y documentación completa).

## Estructura

```
public/     única carpeta servida por nginx: index.php (controlador frontal) y assets/ (CSS, JS, icono)
src/
  Calculo/  motor de cálculo: funciones puras, sin I/O, reciben las tablas como array
  Datos/    lector de las tablas de data/ (la única parte que toca el disco)
  Anuncio/  validación de los enlaces de mobile.de (protección SSRF)
  Web/      aplicación web: Peticion → App → Respuesta, CSRF, SQLite, formularios
  Vistas/   plantillas PHP (todo lo que se imprime pasa por e())
data/
  fiscal/<año>/   tipos y tablas versionados por año; cada valor con fuente y marca "verificar"
  plz_de.json     prefijo de código postal alemán -> coordenadas
  municipios_es.json   8.132 municipios del INE -> coordenadas, provincia y CCAA
scripts/    herramientas offline (no se ejecutan en producción)
tests/      runner propio: php tests/run.php
```

## La web, en local

```
CALCULADORA_VAR=/tmp/calculadora php -S 127.0.0.1:8082 -t public public/index.php
```

Los datos generados (SQLite con los cálculos compartidos, clave de seguridad y registro) van a
`/var/lib/calculadora-importacion` (`Config::DIR_VAR_POR_DEFECTO`), o a la carpeta de la variable de entorno
`CALCULADORA_VAR`. La aplicación nunca escribe en el repositorio. Los datos del titular del aviso legal y de
la política de privacidad están en `data/sitio.json` y hay que rellenarlos antes de publicar.

Sin dependencias ni compilación: HTML, CSS y JavaScript a mano; PHP 8.1+ con curl, dom, pdo_sqlite, mbstring y json.

## Uso del motor

```php
require 'src/autoload.php';
use CalculadoraImportacion\Datos\Tablas;
use CalculadoraImportacion\Calculo\Calculadora;

$tablas = Tablas::cargar(__DIR__ . '/data', 2026);
$resultado = Calculadora::calcular($entrada, $tablas);   // ver el docblock de src/Calculo/Entrada.php
```

El resultado trae `partidas` (cada una con `concepto, importe, base, tipo, fuente, explicacion`),
`total`, `completo`, `avisos` y `derivados`. Una partida con `estado = no_calculable` explica qué dato
falta; el motor nunca inventa un valor.

## Pruebas

```
php tests/run.php          # todas
php tests/run.php IEDMT    # solo las que contengan «IEDMT»
php scripts/verificar_datos.php   # lista lo que sigue marcado VERIFICAR
```

## Estado de verificación de los datos

Cada valor de `data/fiscal/` lleva su fuente y la marca `verificar`. `php scripts/verificar_datos.php`
lista lo que sigue pendiente. A fecha de esta versión:

- **Verificado con el texto oficial:** depreciación (anexo IV de la Orden HAC/1501/2025), fórmula de
  minoración del IEDMT, tipos del IEDMT estatales y autonómicos, definición de coche nuevo, IVA alemán (UStG),
  tarifas y prorrateo del IVTM, fórmula de caballos fiscales (con truncado), tasa 1.1 de la DGT, tipo
  estatal del ITP, y el ITP completo de Madrid, Castilla y León, Andalucía, Castilla-La Mancha y La Rioja.
- **IVTM municipal:** verificadas las ordenanzas de Madrid y Salamanca. En el resto el motor aplica la
  tarifa mínima legal y lo avisa.
- **ITP de las demás comunidades:** el tipo general está verificado (informe de Hacienda), pero
  Aragón, Asturias, Baleares, Cantabria, Cataluña, Valencia, Extremadura, Galicia y Murcia tienen
  tipos o cuotas especiales para vehículos que aún no se han incorporado. El motor no calcula su ITP y lo
  explica, en lugar de dar una cifra errónea.
- **Fuera del alcance:** Canarias, Navarra, País Vasco, Ceuta y Melilla (régimen fiscal distinto); el motor las rechaza con un mensaje claro.
- **Estimaciones editables** (no son normas): ITV, COC, placas, gestoría, matrícula de exportación y los
  parámetros de transporte. Cada partida lo indica con `estimacion`.

## Créditos de los datos

Coordenadas de códigos postales: [GeoNames](https://www.geonames.org/) (CC BY 4.0), a través de
[zauberware/postal-codes-json-xml-csv](https://github.com/zauberware/postal-codes-json-xml-csv).
Relación de municipios y provincias: INE, a través de
[codeforspain/ds-organizacion-administrativa](https://github.com/codeforspain/ds-organizacion-administrativa).
Se regeneran con `python3 -I scripts/geo/generar_geo.py <carpeta_descargas> data`.
