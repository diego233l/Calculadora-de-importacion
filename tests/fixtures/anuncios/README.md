# Fixtures del lector de anuncios

**Son sintéticas.** Están modeladas sobre cómo suelen presentar los datos las fichas de coches (listas de definiciones, pares de `<div>`, tablas,
JSON-LD de schema.org y texto copiado de la página) con ruido realista (menús con «Händler», precios de financiación, scripts…), pero **no son
copias de páginas de mobile.de**: desde el servidor de desarrollo mobile.de contesta `403 Access denied` (Akamai) y no se ha intentado saltarse ese
bloqueo. Las personas, ciudades y precios son inventados.

Lo que sí demuestran los tests: que el lector entiende esas estructuras, los rótulos en alemán, inglés y español, los formatos de números y fechas, y
que no se deja engañar por menús, scripts, textos ocultos o frases que contienen «Händler».

Lo que **no** demuestran: que los selectores y rótulos coincidan con el HTML real de mobile.de hoy. Para comprobarlo:

1. En el navegador, abre un anuncio de mobile.de y guarda la página (`Guardar como…`, «Página web, solo HTML») o copia todo el texto (`Ctrl+A`, `Ctrl+C`).
2. `php scripts/analizar_anuncio.php anuncio.html` enseña qué datos saca, con su confianza y origen.
3. Si falta algo, añade el rótulo a `data/diccionarios/etiquetas.json` o el selector a `data/mobile_de_selectores.json` (no hace falta tocar código).
4. Copia el fichero aquí (anonimizado: sin nombres ni teléfonos) con su `.json` esperado (`php scripts/analizar_anuncio.php anuncio.html --json` da el punto de partida;
   revísalo a mano contra la página). `php tests/run.php fixtures` lo recoge solo.

`generar.py` regenera las fixtures sintéticas (`python3 -I tests/fixtures/anuncios/generar.py`); borra los `.html`, `.txt` y `.json` de esta carpeta, así que las
reales conviene guardarlas en una subcarpeta `reales/` (el test las recorre también).
