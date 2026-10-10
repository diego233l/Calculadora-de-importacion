#!/usr/bin/env python3
"""
Genera las fixtures del lector de anuncios (tests/fixtures/anuncios/*.html|*.txt + *.json esperado).

IMPORTANTE: son SINTÉTICAS. Están modeladas sobre cómo suelen presentar los datos las fichas de coches (listas de
definiciones, pares de <div>, tablas, JSON-LD de schema.org y texto copiado de la página), con ruido realista
(menús con «Händler», precios de financiación, scripts…), pero NO son copias de páginas de mobile.de: desde el
servidor de desarrollo mobile.de contesta 403 (Akamai) y no se ha intentado saltarse ese bloqueo. Las personas, ids y
direcciones son inventados. Cuando se guarden páginas reales (en el navegador: «Guardar página como…»), se añaden aquí
junto a su JSON esperado (`php scripts/analizar_anuncio.php fichero --json` da el punto de partida) y se corrigen los
rótulos de data/diccionarios/etiquetas.json o los selectores de data/mobile_de_selectores.json si falla alguna.

Uso:  python3 -I tests/fixtures/anuncios/generar.py    (sobrescribe los ficheros de esta carpeta)
"""
from __future__ import annotations

import html
import json
from pathlib import Path

DESTINO = Path(__file__).resolve().parent
RUIDO_NAV = '''
<header><nav><a href="/">Start</a> <a href="/haendler">Händler-Suche</a> <a href="/verkaufen">Privat verkaufen</a>
<a href="/finanzierung">Finanzierung ab 99 € mtl.</a> <span>Händlerbereich</span> <span>Für Händler</span></nav></header>
<script>window.__DATA__ = {"price": 1, "mileage": 99999, "seller": "Händler", "plz": "10115"};</script>
<style>.price { color: red; } /* 12.345 € */</style>
'''
RUIDO_PIE = '''
<footer><p>© mobile-modelo · Entfernung 12 km · Ähnliche Angebote ab 8.990 € · Finanzierungsbeispiel: 199 € mtl. Rate</p>
<a href="/impressum">Impressum</a> <a href="/datenschutz">Datenschutz</a></footer>
'''

CASOS: list[dict] = []


def caso(nombre: str, estilo: str, titulo: str, esperado: dict, *, datos: dict | None = None, dudosos: list | None = None,
         faltan: list | None = None, avisos: list | None = None, fuente: str | None = None, **opciones) -> None:
    CASOS.append(dict(nombre=nombre, estilo=estilo, titulo=titulo, esperado=esperado, datos=datos or {},
                      dudosos=sorted(dudosos if dudosos is not None else ['version']), faltan=sorted(faltan or []),
                      avisos=avisos or [], fuente=fuente, opciones=opciones))


def e(t) -> str:
    return html.escape(str(t), quote=True)


# ---------------------------------------------------------------- estilos HTML

def fichas_dl(d: dict) -> str:
    filas = ''.join(f'<dt>{e(k)}</dt><dd>{e(v)}</dd>' for k, v in d['filas'])
    return f'<dl class="key-features">{filas}</dl>'


def fichas_div(d: dict) -> str:
    filas = ''.join(f'<div class="key-feature"><div class="key-feature__label">{e(k)}</div><div class="key-feature__content">{e(v)}</div></div>'
                    for k, v in d['filas'])
    return f'<div data-testid="vip-key-features-list">{filas}</div>'


def fichas_tabla(d: dict) -> str:
    filas = ''.join(f'<tr><th scope="row">{e(k)}</th><td>{e(v)}</td></tr>' for k, v in d['filas'])
    return f'<table class="technical-data"><tbody>{filas}</tbody></table>'


def fichas_li(d: dict) -> str:
    return '<ul>' + ''.join(f'<li>{e(k)}: {e(v)}</li>' for k, v in d['filas']) + '</ul>'


def fichas_span_sup(d: dict) -> str:
    filas = ''.join(f'<li><span class="label">{e(k)}<sup>1</sup></span> <span class="value">{e(v)}</span></li>' for k, v in d['filas'])
    return f'<ul class="specs">{filas}</ul>'


def fichas_envuelto(d: dict) -> str:
    filas = ''.join(f'<div class="row"><div class="c1"><p><b>{e(k)}</b></p></div><div class="c2"><p>{e(v)}</p></div></div>' for k, v in d['filas'])
    return f'<section>{filas}</section>'


def fichas_li_inline(d: dict) -> str:
    return '<ul>' + ''.join(f'<li><strong>{e(k)}</strong> {e(v)}</li>' for k, v in d['filas']) + '</ul>'


ESTILOS = {'dl': fichas_dl, 'div': fichas_div, 'tabla': fichas_tabla, 'li': fichas_li, 'sup': fichas_span_sup,
           'envuelto': fichas_envuelto, 'li_inline': fichas_li_inline}


def pagina_html(c: dict) -> str:
    d = c['datos']
    o = c['opciones']
    jsonld = ''
    if o.get('jsonld'):
        jsonld = '<script type="application/ld+json">' + json.dumps(o['jsonld'], ensure_ascii=False) + '</script>'
    cuerpo_fichas = ESTILOS[c['estilo']](d) if c['estilo'] in ESTILOS and d.get('filas') else ''
    precio = ''
    if d.get('precio_html'):
        precio = d['precio_html']
    vendedor = d.get('vendedor_html', '')
    ubicacion = d.get('ubicacion_html', '')
    extra = d.get('extra_html', '')
    charset = o.get('charset', 'utf-8')
    doc = f'''<!DOCTYPE html>
<html lang="de"><head><meta charset="{charset}"><title>{e(c['titulo'])} - mobile-modelo.example</title>
<meta property="og:title" content="{e(c['titulo'])}">
{jsonld}</head>
<body>{RUIDO_NAV}
<main><h1>{e(c['titulo'])}</h1>
{precio}
{vendedor}
{ubicacion}
{cuerpo_fichas}
{extra}
</main>{RUIDO_PIE}</body></html>'''
    return doc


def pagina_texto(c: dict) -> str:
    return c['datos']['texto']


# ---------------------------------------------------------------- datos comunes

def fila_estandar(km='105.000 km', pot='140 kW (190 PS)', comb='Diesel', ez='03/2019', cc='1.995 cm³', co2='119 g/km',
                  extra=()):
    f = [('Kilometerstand', km), ('Leistung', pot), ('Kraftstoffart', comb), ('Getriebe', 'Automatik'), ('Erstzulassung', ez),
         ('Hubraum', cc), ('CO₂-Emissionen (kombiniert)', co2), ('Anzahl der Fahrzeughalter', '2'), ('Fahrzeugzustand', 'Unfallfrei')]
    return [x for x in f if x[1] is not None] + list(extra)


PRECIO_TESTID = '<div class="price-block"><span data-testid="prime-price">{p}</span> <span class="vat">Brutto</span></div>'
PRIVAT = '<div data-testid="seller-type">Privatanbieter</div>'
HAENDLER = '<div data-testid="seller-type">Händler</div>'
UBI = '<div data-testid="seller-address">{u}</div>'

BASE_ESPERADO = {'marca': 'BMW', 'modelo': '320d', 'km': '105000', 'combustible': 'diesel', 'matriculacion': '03/2019', 'cc': '1995',
                 'kw': '140', 'cv': '190', 'co2': '119', 'precio': '16500', 'vendedor': 'particular', 'cp_vendedor': '80331'}


def esp(**cambios) -> dict:
    r = dict(BASE_ESPERADO)
    for k, v in cambios.items():
        if v is None:
            r.pop(k, None)
        else:
            r[k] = v
    return r


def datos_base(**kw) -> dict:
    d = dict(filas=fila_estandar(), precio_html=PRECIO_TESTID.format(p='16.500 €'), vendedor_html=PRIVAT,
             ubicacion_html=UBI.format(u='DE-80331 München'))
    d.update(kw)
    return d


T_BMW = 'BMW 320d Touring Sport Line Navi LED'
# ---------------------------------------------------------------- casos HTML (estructuras de ficha)
caso('01-dl-privado', 'dl', T_BMW, esp(), datos=datos_base())
caso('02-div-testid-profesional-iva', 'div', 'BMW 320d Touring M Sport', esp(vendedor='profesional', iva_deducible='1', cp_vendedor='10115'),
     datos=datos_base(precio_html='<div data-testid="prime-price">16.500 €</div> <div class="vat">Brutto · 13.865,55 € Netto · MwSt. ausweisbar</div>',
                      vendedor_html=HAENDLER, ubicacion_html=UBI.format(u='DE-10115 Berlin')))
caso('03-tabla-th-td', 'tabla', T_BMW, esp(), datos=datos_base())
caso('04-li-dospuntos', 'li', T_BMW, esp(), datos=datos_base())
caso('05-sup-notas-al-pie', 'sup', T_BMW, esp(), datos=datos_base())
caso('06-etiqueta-envuelta-en-p', 'envuelto', T_BMW, esp(), datos=datos_base())
caso('07-li-strong-inline', 'li_inline', T_BMW, esp(), datos=datos_base())
JSONLD_CAR = {'@context': 'https://schema.org', '@type': 'Car', 'name': T_BMW, 'brand': {'@type': 'Brand', 'name': 'BMW'}, 'model': '320d',
              'mileageFromOdometer': {'@type': 'QuantitativeValue', 'value': 105000, 'unitCode': 'KMT'},
              'fuelType': 'Diesel', 'dateVehicleFirstRegistered': '2019-03',
              'vehicleEngine': {'@type': 'EngineSpecification', 'enginePower': {'@type': 'QuantitativeValue', 'value': 140, 'unitCode': 'KWT'},
                                'engineDisplacement': {'@type': 'QuantitativeValue', 'value': 1995, 'unitCode': 'CMQ'}},
              'offers': {'@type': 'Offer', 'price': '16500', 'priceCurrency': 'EUR', 'seller': {'@type': 'Person', 'name': 'Anónimo'}}}
caso('08-jsonld-completo', 'ninguno', T_BMW, esp(cv='190', co2=None, cp_vendedor=None), datos=dict(filas=[], precio_html='', vendedor_html='', ubicacion_html=''),
     jsonld=JSONLD_CAR, faltan=['cp_vendedor'])
JSONLD_PROD = {'@context': 'https://schema.org', '@type': 'Product', 'name': 'Audi A3 Sportback 35 TFSI S line', 'brand': 'Audi',
               'offers': [{'@type': 'Offer', 'price': 22900, 'priceCurrency': 'EUR', 'seller': {'@type': 'AutoDealer', 'name': 'Autohaus Ejemplo'},
                           'availableAtOrFrom': {'address': {'postalCode': '50667', 'addressCountry': 'DE'}}}]}
caso('09-jsonld-producto-oferta-lista', 'dl', 'Audi A3 Sportback 35 TFSI S line',
     {'marca': 'Audi', 'modelo': 'A3', 'km': '48000', 'combustible': 'gasolina', 'matriculacion': '06/2021', 'cc': '1498', 'kw': '110', 'cv': '150',
      'co2': '126', 'precio': '22900', 'vendedor': 'profesional', 'cp_vendedor': '50667'},
     datos=dict(filas=fila_estandar('48.000 km', '110 kW (150 PS)', 'Benzin', '06/2021', '1.498 cm³', '126 g/km'), precio_html='', vendedor_html='', ubicacion_html=''),
     jsonld=JSONLD_PROD)
caso('10-electrico-sin-cc-ni-co2', 'dl', 'Tesla Model 3 Long Range AWD',
     {'marca': 'Tesla', 'modelo': 'Model 3', 'km': '62000', 'combustible': 'electrico', 'matriculacion': '09/2021', 'kw': '324', 'cv': '441',
      'precio': '28900', 'vendedor': 'profesional', 'cp_vendedor': '20095'},
     datos=dict(filas=[('Kilometerstand', '62.000 km'), ('Leistung', '324 kW (441 PS)'), ('Kraftstoffart', 'Elektro'), ('Getriebe', 'Automatik'),
                       ('Erstzulassung', '09/2021')], precio_html=PRECIO_TESTID.format(p='28.900 €'), vendedor_html=HAENDLER,
                ubicacion_html=UBI.format(u='DE-20095 Hamburg')))
caso('11-hibrido-no-seguro', 'dl', 'Toyota Yaris Hybrid 116 Active Tech',
     {'marca': 'Toyota', 'modelo': 'Yaris', 'km': '31000', 'combustible': 'hibrido', 'matriculacion': '02/2022', 'cc': '1490', 'kw': '85', 'cv': '116',
      'co2': '87', 'precio': '17950', 'vendedor': 'profesional', 'cp_vendedor': '70173'},
     datos=dict(filas=fila_estandar('31.000 km', '85 kW (116 PS)', 'Hybrid (Benzin/Elektro)', '02/2022', '1.490 cm³', '87 g/km'),
                precio_html=PRECIO_TESTID.format(p='17.950 €'), vendedor_html=HAENDLER, ubicacion_html=UBI.format(u='DE-70173 Stuttgart')),
     dudosos=['combustible', 'version'])
caso('12-enchufable-phev', 'dl', 'Volkswagen Golf GTE 1.4 TSI Plug-in-Hybrid',
     {'marca': 'Volkswagen', 'modelo': 'Golf', 'km': '54000', 'combustible': 'phev', 'matriculacion': '11/2020', 'cc': '1395', 'kw': '110', 'cv': '150',
      'co2': '36', 'precio': '21490', 'vendedor': 'profesional', 'cp_vendedor': '60311'},
     datos=dict(filas=fila_estandar('54.000 km', '110 kW (150 PS)', 'Plug-in-Hybrid', '11/2020', '1.395 cm³', '36 g/km'),
                precio_html=PRECIO_TESTID.format(p='21.490 €'), vendedor_html=HAENDLER, ubicacion_html=UBI.format(u='DE-60311 Frankfurt am Main')),
     dudosos=['combustible', 'version'] if False else ['version'])
caso('13-solo-anio-matriculacion', 'dl', T_BMW, esp(matriculacion='01/2019'),
     datos=datos_base(filas=fila_estandar(ez='2019')), dudosos=['matriculacion', 'version'], avisos=['solo indica el año'])
caso('14-vendedor-austriaco', 'dl', T_BMW, esp(cp_vendedor='1010', vendedor='profesional'),
     datos=datos_base(ubicacion_html=UBI.format(u='AT-1010 Wien'), vendedor_html=HAENDLER), dudosos=['cp_vendedor', 'version'],
     avisos=['otro país'])
caso('15-sin-kilometraje', 'dl', T_BMW, esp(km=None),
     datos=datos_base(filas=[f for f in fila_estandar() if f[0] != 'Kilometerstand']), faltan=['km'])
caso('16-etiquetas-en-ingles', 'dl', 'Audi A4 Avant 2.0 TDI S line',
     {'marca': 'Audi', 'modelo': 'A4', 'km': '88000', 'combustible': 'diesel', 'matriculacion': '05/2018', 'cc': '1968', 'kw': '110', 'cv': '150',
      'co2': '112', 'precio': '19800', 'vendedor': 'particular', 'cp_vendedor': '04109'},
     datos=dict(filas=[('Mileage', '88,000 km'), ('Power', '110 kW (150 hp)'), ('Fuel', 'Diesel'), ('Transmission', 'Manual'), ('First registration', '05/2018'),
                       ('Cubic capacity', '1,968 cc'), ('CO₂ emissions (combined)', '112 g/km'), ('Condition', 'Used')],
                precio_html='<div data-testid="prime-price">€ 19,800</div>', vendedor_html='<div data-testid="seller-type">Private seller</div>',
                ubicacion_html=UBI.format(u='DE-04109 Leipzig')))
caso('17-etiquetas-en-espanol', 'dl', 'SEAT León 1.5 TSI FR',
     {'marca': 'SEAT', 'modelo': 'León', 'km': '72000', 'combustible': 'gasolina', 'matriculacion': '10/2020', 'cc': '1498', 'kw': '96', 'cv': '130',
      'co2': '118', 'precio': '15200', 'vendedor': 'particular', 'cp_vendedor': '80331'},
     datos=dict(filas=[('Kilometraje', '72.000 km'), ('Potencia', '96 kW (130 CV)'), ('Combustible', 'Gasolina'), ('Cambio', 'Manual'),
                       ('Primera matriculación', '10/2020'), ('Cilindrada', '1.498 cm³'), ('Emisiones de CO₂ (combinadas)', '118 g/km')],
                precio_html='<div data-testid="prime-price">15.200 €</div>', vendedor_html='<div data-testid="seller-type">Particular</div>',
                ubicacion_html=UBI.format(u='DE-80331 München')))
caso('18-contenido-hostil', 'dl', '<img src=x onerror=alert(1)> BMW 320d Touring ' + 'A' * 300,
     esp(), datos=datos_base(extra_html='<div style="display:none">Kilometerstand 1 km</div><p>Preis 1 €</p><script>alert("Händler")</script>'),
     dudosos=['version'], hostil=True)
caso('19-iso-8859-1', 'dl', 'Mercedes-Benz C 220 d T-Modell AMG Line',
     {'marca': 'Mercedes-Benz', 'modelo': 'C 220', 'km': '120000', 'combustible': 'diesel', 'matriculacion': '04/2018', 'cc': '1950', 'kw': '143', 'cv': '194',
      'co2': '130', 'precio': '24900', 'vendedor': 'profesional', 'cp_vendedor': '40213'},
     datos=dict(filas=fila_estandar('120.000 km', '143 kW (194 PS)', 'Diesel', '04/2018', '1.950 cm³', '130 g/km'),
                precio_html=PRECIO_TESTID.format(p='24.900 €'), vendedor_html=HAENDLER, ubicacion_html=UBI.format(u='DE-40213 Düsseldorf')),
     charset='iso-8859-1')
caso('20-autogas-glp', 'dl', 'Dacia Duster 1.0 TCe 100 ECO-G Comfort',
     {'marca': 'Dacia', 'modelo': 'Duster', 'km': '23000', 'combustible': 'glp', 'matriculacion': '07/2023', 'cc': '999', 'kw': '74', 'cv': '101',
      'co2': '117', 'precio': '17400', 'vendedor': 'profesional', 'cp_vendedor': '01067'},
     datos=dict(filas=fila_estandar('23.000 km', '74 kW (101 PS)', 'Autogas (LPG)', '07/2023', '999 cm³', '117 g/km'),
                precio_html=PRECIO_TESTID.format(p='17.400 €'), vendedor_html=HAENDLER, ubicacion_html=UBI.format(u='DE-01067 Dresden')))
caso('21-erdgas-cng', 'dl', 'Volkswagen Golf 1.5 TGI BlueMotion',
     {'marca': 'Volkswagen', 'modelo': 'Golf', 'km': '99000', 'combustible': 'gnc', 'matriculacion': '01/2019', 'cc': '1498', 'kw': '96', 'cv': '130',
      'co2': '100', 'precio': '14300', 'vendedor': 'particular', 'cp_vendedor': '30159'},
     datos=dict(filas=fila_estandar('99.000 km', '96 kW (130 PS)', 'Erdgas (CNG)', '01/2019', '1.498 cm³', '100 g/km'),
                precio_html=PRECIO_TESTID.format(p='14.300 €'), vendedor_html=PRIVAT, ubicacion_html=UBI.format(u='DE-30159 Hannover')))
caso('22-co2-no-indicado', 'dl', T_BMW, esp(co2=None),
     datos=datos_base(filas=fila_estandar(co2='k. A.')))
caso('23-km-contradictorios-json-y-etiqueta', 'dl', T_BMW, esp(km='150000'),
     datos=datos_base(filas=fila_estandar(km='105.000 km')),
     jsonld={'@context': 'https://schema.org', '@type': 'Car', 'name': T_BMW, 'mileageFromOdometer': {'value': 150000, 'unitCode': 'KMT'}},
     dudosos=['km', 'version'])
caso('24-precio-con-guion-y-neto', 'dl', 'BMW 320d Touring Advantage',
     esp(precio='17990', vendedor='profesional', iva_deducible='1', cp_vendedor='10115'),
     datos=datos_base(precio_html='<span data-testid="prime-price">€ 17.990,-</span> <span>MwSt. ausweisbar</span> <span>Netto 15.118 €</span>',
                      vendedor_html=HAENDLER, ubicacion_html=UBI.format(u='DE-10115 Berlin')))
caso('25-matriculacion-con-mes-escrito', 'dl', T_BMW, esp(matriculacion='03/2019'),
     datos=datos_base(filas=fila_estandar(ez='März 2019')))
caso('26-potencia-en-ps-primero', 'dl', T_BMW, esp(), datos=datos_base(filas=fila_estandar(pot='190 PS (140 kW)')))
caso('27-modelo-con-rotulo-propio', 'dl', 'Peugeot 308 SW GT Line BlueHDi 130',
     {'marca': 'Peugeot', 'modelo': '308', 'km': '67000', 'combustible': 'diesel', 'matriculacion': '08/2020', 'cc': '1499', 'kw': '96', 'cv': '130',
      'co2': '104', 'precio': '16900', 'vendedor': 'particular', 'cp_vendedor': '45127'},
     datos=dict(filas=[('Marke', 'Peugeot'), ('Modell', '308')] + fila_estandar('67.000 km', '96 kW (130 PS)', 'Diesel', '08/2020', '1.499 cm³', '104 g/km'),
                precio_html=PRECIO_TESTID.format(p='16.900 €'), vendedor_html=PRIVAT, ubicacion_html=UBI.format(u='DE-45127 Essen')))
caso('28-marca-desconocida', 'dl', 'Ora Funky Cat 63 kWh',
     {'km': '12000', 'combustible': 'electrico', 'matriculacion': '05/2023', 'kw': '126', 'cv': '171', 'precio': '25500', 'vendedor': 'profesional', 'cp_vendedor': '80331'},
     datos=dict(filas=[('Kilometerstand', '12.000 km'), ('Leistung', '126 kW (171 PS)'), ('Kraftstoffart', 'Elektro'), ('Erstzulassung', '05/2023')],
                precio_html=PRECIO_TESTID.format(p='25.500 €'), vendedor_html=HAENDLER, ubicacion_html=UBI.format(u='DE-80331 München')),
     dudosos=[], faltan=['marca', 'modelo'])
caso('29-sin-selectores-solo-etiquetas', 'dl', 'Porsche 911 Carrera S Cabriolet',
     {'marca': 'Porsche', 'modelo': '911', 'km': '38000', 'combustible': 'gasolina', 'matriculacion': '05/2020', 'cc': '2981', 'kw': '331', 'cv': '450',
      'co2': '210', 'precio': '119900', 'vendedor': 'profesional', 'cp_vendedor': '80331'},
     datos=dict(filas=fila_estandar('38.000 km', '331 kW (450 PS)', 'Benzin', '05/2020', '2.981 cm³', '210 g/km') ,
                precio_html='<p class="big">119.900 €</p><p>Brutto</p>', vendedor_html='<p>Händler</p>', ubicacion_html='<p>DE-80331 München</p>'))
caso('30-pagina-minima', 'ninguno', 'Seat Ibiza 1.0 TSI Style',
     {'marca': 'SEAT', 'modelo': 'Ibiza'}, datos=dict(filas=[], precio_html='', vendedor_html='', ubicacion_html=''),
     dudosos=['version'], faltan=['matriculacion', 'km', 'combustible', 'precio', 'vendedor', 'cp_vendedor', 'cc'])

# ---------------------------------------------------------------- casos de texto pegado
TXT_BASE = '''BMW 320d Touring Sport Line Navi LED Kamera
16.500 €
Brutto
Privatanbieter
DE-80331 München
Kilometerstand
105.000 km
Leistung
140 kW (190 PS)
Kraftstoffart
Diesel
Getriebe
Automatik
Erstzulassung
03/2019
Hubraum
1.995 cm³
CO₂-Emissionen (kombiniert)¹
119 g/km
Anzahl der Fahrzeughalter
2
Fahrzeugzustand
Unfallfrei
'''
caso('31-texto-rotulo-y-valor-en-lineas', 'texto', 'BMW 320d Touring Sport Line Navi LED Kamera', esp(), datos=dict(texto=TXT_BASE), fuente='texto')
caso('32-texto-con-dos-puntos', 'texto', 'BMW 320d Touring Sport Line Navi LED Kamera', esp(),
     datos=dict(texto='''BMW 320d Touring Sport Line Navi LED Kamera
16.500 € Brutto
Privatanbieter
DE-80331 München
Kilometerstand: 105.000 km
Leistung: 140 kW (190 PS)
Kraftstoffart: Diesel
Erstzulassung: 03/2019
Hubraum: 1.995 cm³
CO₂-Emissionen (kombiniert): 119 g/km
'''), fuente='texto')
caso('33-texto-rotulo-y-valor-en-la-misma-linea', 'texto', 'BMW 320d Touring Sport Line Navi LED Kamera', esp(),
     datos=dict(texto='''BMW 320d Touring Sport Line Navi LED Kamera
16.500 €
Brutto
Privatanbieter
DE-80331 München
Kilometerstand 105.000 km
Leistung 140 kW (190 PS)
Kraftstoffart Diesel
Erstzulassung 03/2019
Hubraum 1.995 cm³
CO₂-Emissionen (kombiniert) 119 g/km
'''), fuente='texto')
caso('34-texto-en-ingles', 'texto', 'Audi A4 Avant 2.0 TDI S line',
     {'marca': 'Audi', 'modelo': 'A4', 'km': '88000', 'combustible': 'diesel', 'matriculacion': '05/2018', 'cc': '1968', 'kw': '110', 'cv': '150',
      'co2': '112', 'precio': '19800', 'vendedor': 'particular', 'cp_vendedor': '04109'},
     datos=dict(texto='''Audi A4 Avant 2.0 TDI S line
€ 19,800
Private seller
DE-04109 Leipzig
Mileage
88,000 km
Power
110 kW (150 hp)
Fuel
Diesel
First registration
05/2018
Cubic capacity
1,968 cc
CO₂ emissions (combined)
112 g/km
'''), fuente='texto', dudosos=['precio', 'version'])
caso('35-texto-en-espanol', 'texto', 'SEAT León 1.5 TSI FR',
     {'marca': 'SEAT', 'modelo': 'León', 'km': '72000', 'combustible': 'gasolina', 'matriculacion': '10/2020', 'cc': '1498', 'kw': '96', 'cv': '130',
      'co2': '118', 'precio': '15200', 'vendedor': 'particular', 'cp_vendedor': '80331'},
     datos=dict(texto='''SEAT León 1.5 TSI FR
15.200 €
Particular
DE-80331 München
Kilometraje: 72.000 km
Potencia: 96 kW (130 CV)
Combustible: Gasolina
Primera matriculación: 10/2020
Cilindrada: 1.498 cm³
Emisiones de CO₂ (combinadas): 118 g/km
'''), fuente='texto', dudosos=['precio', 'version'])
caso('36-texto-solo-titulo-y-precio', 'texto', 'Seat Ibiza 1.0 TSI Style',
     {'marca': 'SEAT', 'modelo': 'Ibiza', 'precio': '9990'},
     datos=dict(texto='Seat Ibiza 1.0 TSI Style\n9.990 €\n'), fuente='texto', dudosos=['version', 'precio'],
     faltan=['matriculacion', 'km', 'combustible', 'vendedor', 'cp_vendedor', 'cc'])
caso('37-texto-con-financiacion-y-neto-no-es-precio', 'texto', 'BMW 320d Touring M Sport',
     esp(precio='16500', vendedor='profesional', iva_deducible='1', cp_vendedor='10115'),
     datos=dict(texto='''BMW 320d Touring M Sport
Finanzierung ab 199 € mtl.
16.500 €
Brutto
13.865 € Netto
MwSt. ausweisbar
Händler
DE-10115 Berlin
Kilometerstand
105.000 km
Leistung
140 kW (190 PS)
Kraftstoffart
Diesel
Erstzulassung
03/2019
Hubraum
1.995 cm³
CO₂-Emissionen (kombiniert)
119 g/km
'''), fuente='texto')
caso('38-texto-con-tabuladores-y-espacios-duros', 'texto', 'BMW 320d Touring Sport Line Navi LED Kamera', esp(),
     datos=dict(texto='BMW 320d Touring Sport Line Navi LED Kamera\n16.500 €\nBrutto\nPrivatanbieter\nDE-80331 München\n'
                      'Kilometerstand\t105.000 km\nLeistung\t140 kW (190 PS)\nKraftstoffart\tDiesel\nErstzulassung\t03/2019\n'
                      'Hubraum\t1.995 cm³\nCO₂-Emissionen (kombiniert)\t119 g/km\n'), fuente='texto')
caso('39-texto-electrico', 'texto', 'Tesla Model 3 Long Range AWD',
     {'marca': 'Tesla', 'modelo': 'Model 3', 'km': '62000', 'combustible': 'electrico', 'matriculacion': '09/2021', 'kw': '324', 'cv': '441',
      'precio': '28900', 'vendedor': 'profesional', 'cp_vendedor': '20095'},
     datos=dict(texto='''Tesla Model 3 Long Range AWD
28.900 €
Brutto
Händler
DE-20095 Hamburg
Kilometerstand
62.000 km
Leistung
324 kW (441 PS)
Kraftstoffart
Elektro
Erstzulassung
09/2021
'''), fuente='texto')
caso('40-texto-es-html-pegado', 'texto', T_BMW, esp(), datos=dict(texto=''), fuente='html', html_pegado=True)
caso('41-texto-solo-anio-y-mes-escrito', 'texto', 'Volkswagen Golf VII 2.0 TDI Highline',
     {'marca': 'Volkswagen', 'modelo': 'Golf', 'km': '95000', 'combustible': 'diesel', 'matriculacion': '03/2019', 'cc': '1968', 'kw': '110', 'cv': '150',
      'co2': '119', 'precio': '16500', 'vendedor': 'particular', 'cp_vendedor': '80331'},
     datos=dict(texto='''Volkswagen Golf VII 2.0 TDI Highline
16.500 €
Privatanbieter
80331 München
Standort
80331 München
Kilometerstand
95.000 km
Leistung
110 kW (150 PS)
Kraftstoffart
Diesel
Erstzulassung
März 2019
Hubraum
1.968 cm³
CO₂-Emissionen (kombiniert)
119 g/km
'''), fuente='texto', dudosos=['version', 'precio'])
caso('42-texto-frase-con-haendler-no-es-vendedor', 'texto', T_BMW,
     esp(vendedor=None, cp_vendedor=None), datos=dict(texto='''BMW 320d Touring Sport Line Navi LED Kamera
16.500 €
Brutto
Händler-Suche und Händlerbereich
Kilometerstand
105.000 km
Leistung
140 kW (190 PS)
Kraftstoffart
Diesel
Erstzulassung
03/2019
Hubraum
1.995 cm³
CO₂-Emissionen (kombiniert)
119 g/km
'''), fuente='texto', faltan=['vendedor', 'cp_vendedor'])


def main() -> None:
    for f in list(DESTINO.glob('*.html')) + list(DESTINO.glob('*.txt')) + list(DESTINO.glob('*.json')):
        f.unlink()
    for c in CASOS:
        es_texto = c['estilo'] == 'texto'
        base = DESTINO / c['nombre']
        if c['opciones'].get('html_pegado'):
            contenido = pagina_html({**c, 'estilo': 'dl', 'datos': datos_base()})
            base.with_suffix('.txt').write_bytes(contenido.encode('utf-8'))
        elif es_texto:
            base.with_suffix('.txt').write_bytes(pagina_texto(c).encode('utf-8'))
        else:
            contenido = pagina_html(c)
            charset = c['opciones'].get('charset', 'utf-8')
            if charset.lower() in ('iso-8859-1', 'windows-1252'):
                datos_bytes = contenido.encode('windows-1252', errors='xmlcharrefreplace')   # lo que no cabe (₂) va como &#8322;
            else:
                datos_bytes = contenido.encode('utf-8')
            base.with_suffix('.html').write_bytes(datos_bytes)
        fuente = c['fuente'] or 'html'
        esperado = {
            'fuente': fuente,
            'campos': c['esperado'],
            'dudosos': c['dudosos'],
            'faltan': c['faltan'],
            'avisos_contienen': c['avisos'],
        }
        if c['opciones'].get('hostil'):
            esperado['hostil'] = True
        base.with_suffix('.json').write_text(json.dumps(esperado, ensure_ascii=False, indent=1) + '\n', encoding='utf-8')
    print(f'{len(CASOS)} fixtures escritas en {DESTINO}')


if __name__ == '__main__':
    main()
