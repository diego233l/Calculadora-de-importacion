# Calculadora de importación

Coste real de importar a España un coche de mobile.de: precio, impuesto de matriculación (IEDMT),
ITP o IVA, IVTM del municipio, tasas de la DGT, ITV, gestoría y transporte. Cada cifra muestra su
fuente y su cálculo.

> **Estado:** Fase 1 (motor de cálculo) terminada. Pendientes: Fase 2 (datos del BOE), 3 (lectura del
> anuncio), 4 (página web) y 5 (instalación y documentación completa).

## Estructura

```
public/     (Fase 4)  única carpeta servida por nginx
src/
  Calculo/  motor de cálculo: funciones puras, sin I/O, reciben las tablas como array
  Datos/    lector de las tablas de data/ (la única parte que toca el disco)
data/
  fiscal/<año>/   tipos y tablas versionados por año; cada valor con fuente y marca "verificar"
  plz_de.json     prefijo de código postal alemán -> coordenadas
  municipios_es.json   8.132 municipios del INE -> coordenadas, provincia y CCAA
scripts/    herramientas offline (no se ejecutan en producción)
tests/      runner propio: php tests/run.php
```

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

## Datos pendientes de verificar

Los valores de `data/fiscal/` proceden de fuentes secundarias y están marcados `verificar: true` hasta
contrastarlos con la norma. Donde las fuentes se contradicen (ITP de varias comunidades, tipos
autonómicos del IEDMT) el tipo queda en `null` con los candidatos anotados, y el motor lo comunica en
lugar de elegir uno. `php scripts/verificar_datos.php` da la lista actualizada.

## Créditos de los datos

Coordenadas de códigos postales: [GeoNames](https://www.geonames.org/) (CC BY 4.0), a través de
[zauberware/postal-codes-json-xml-csv](https://github.com/zauberware/postal-codes-json-xml-csv).
Relación de municipios y provincias: INE, a través de
[codeforspain/ds-organizacion-administrativa](https://github.com/codeforspain/ds-organizacion-administrativa).
Se regeneran con `python3 -I scripts/geo/generar_geo.py <carpeta_descargas> data`.
