#!/usr/bin/env python3
"""Genera data/plz_de.json y data/municipios_es.json a partir de datos abiertos.

Herramienta OFFLINE: no se ejecuta en producción; el resultado se commitea.

Uso:
    python3 -I scripts/geo/generar_geo.py <carpeta_descargas> <carpeta_data>

Fuentes (todas descargadas desde raw.githubusercontent.com):
  - zauberware/postal-codes-json-xml-csv (derivado de GeoNames, CC BY 4.0):
    códigos postales de Alemania y España con latitud/longitud.
  - codeforspain/ds-organizacion-administrativa: municipios y provincias del INE.

El mapa provincia -> comunidad autónoma está escrito aquí a mano (es estable
desde 1983) y se identifica con los códigos de 2 letras de data/ccaa.
"""
import io
import json
import os
import sys
import urllib.request
import zipfile
from collections import defaultdict

ZIP_BASE = "https://raw.githubusercontent.com/zauberware/postal-codes-json-xml-csv/master/data"
INE_BASE = "https://raw.githubusercontent.com/codeforspain/ds-organizacion-administrativa/master/data"

# Código INE de provincia -> código de CCAA (ver data/fiscal/<año>/ccaa.json)
PROVINCIA_CCAA = {
    "01": "PV", "02": "CM", "03": "VC", "04": "AN", "05": "CL", "06": "EX", "07": "IB",
    "08": "CT", "09": "CL", "10": "EX", "11": "AN", "12": "VC", "13": "CM", "14": "AN",
    "15": "GA", "16": "CM", "17": "CT", "18": "AN", "19": "CM", "20": "PV", "21": "AN",
    "22": "AR", "23": "AN", "24": "CL", "25": "CT", "26": "RI", "27": "GA", "28": "MD",
    "29": "AN", "30": "MC", "31": "NC", "32": "GA", "33": "AS", "34": "CL", "35": "CN",
    "36": "GA", "37": "CL", "38": "CN", "39": "CB", "40": "CL", "41": "AN", "42": "CL",
    "43": "CT", "44": "AR", "45": "CM", "46": "VC", "47": "CL", "48": "PV", "49": "CL",
    "50": "AR", "51": "CE", "52": "ML",
}


def descargar(url, destino):
    if os.path.exists(destino):
        return
    with urllib.request.urlopen(url, timeout=90) as r, open(destino, "wb") as f:
        f.write(r.read())


def cargar_zip_json(ruta_zip, nombre):
    with zipfile.ZipFile(ruta_zip) as z:
        with z.open(nombre) as f:
            return json.load(io.TextIOWrapper(f, encoding="utf-8"))


ARTICULOS = ("El", "La", "Los", "Las", "L'", "A", "O", "As", "Os", "Els", "Les", "Sa", "Es", "Ses")


def nombre_natural(nombre):
    """El INE escribe los artículos al final: 'Palmas de Gran Canaria, Las' -> 'Las Palmas de Gran Canaria'."""
    for art in ARTICULOS:
        sufijo = ", " + art
        if nombre.endswith(sufijo):
            base = nombre[: -len(sufijo)]
            return art + base if art.endswith("'") else art + " " + base
    return nombre


def mediana(valores):
    v = sorted(valores)
    n = len(v)
    return v[n // 2] if n % 2 else (v[n // 2 - 1] + v[n // 2]) / 2


def media(puntos):
    """Mediana por coordenada: robusta frente a localidades vecinas con el mismo código INE."""
    return round(mediana([p[0] for p in puntos]), 4), round(mediana([p[1] for p in puntos]), 4)


def plz_de(filas):
    """Prefijos de 2 y 3 cifras -> media de las coordenadas de sus códigos postales."""
    por_plz = {}
    for r in filas:
        try:
            por_plz[r["zipcode"]] = (float(r["latitude"]), float(r["longitude"]))
        except (KeyError, ValueError):
            continue
    grupos = defaultdict(list)
    for plz, pto in por_plz.items():
        if len(plz) != 5 or not plz.isdigit():
            continue
        grupos[plz[:2]].append(pto)
        grupos[plz[:3]].append(pto)
    return {k: list(media(v)) for k, v in sorted(grupos.items())}


def municipios_es(zip_es, ine_mun, ine_prov):
    por_zip = {}
    for r in zip_es:
        cod = r.get("community_code") or ""
        try:
            pto = (float(r["latitude"]), float(r["longitude"]))
        except (KeyError, ValueError):
            continue
        por_zip[(cod, r["zipcode"])] = pto
    por_mun = defaultdict(list)
    por_prov = defaultdict(list)
    for (cod, _cp), pto in por_zip.items():
        if cod:
            por_mun[cod].append(pto)
            por_prov[cod[:2]].append(pto)

    salida = []
    aprox = 0
    sin_coord = 0
    for m in sorted(ine_mun, key=lambda x: x["municipio_id"]):
        cod = m["municipio_id"]
        nombre = nombre_natural(m["nombre"])
        if cod in por_mun:
            lat, lon = media(por_mun[cod])
            salida.append([cod, nombre, lat, lon])
        elif cod[:2] in por_prov:
            lat, lon = media(por_prov[cod[:2]])
            salida.append([cod, nombre, lat, lon, 1])  # 1 = coordenada aproximada (media provincial)
            aprox += 1
        else:
            sin_coord += 1
    provincias = {p["provincia_id"]: {"nombre": p["nombre"], "ccaa": PROVINCIA_CCAA[p["provincia_id"]]}
                  for p in ine_prov}
    return salida, provincias, aprox, sin_coord


def main():
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    descargas, data = sys.argv[1], sys.argv[2]
    os.makedirs(descargas, exist_ok=True)
    os.makedirs(data, exist_ok=True)

    for pais in ("DE", "ES"):
        descargar(f"{ZIP_BASE}/{pais}.zip", os.path.join(descargas, f"{pais}.zip"))
    descargar(f"{INE_BASE}/municipios.json", os.path.join(descargas, "municipios.json"))
    descargar(f"{INE_BASE}/provincias.json", os.path.join(descargas, "provincias.json"))

    de = cargar_zip_json(os.path.join(descargas, "DE.zip"), "zipcodes.de.json")
    es = cargar_zip_json(os.path.join(descargas, "ES.zip"), "zipcodes.es.json")
    with open(os.path.join(descargas, "municipios.json"), encoding="utf-8") as f:
        ine_mun = json.load(f)
    with open(os.path.join(descargas, "provincias.json"), encoding="utf-8") as f:
        ine_prov = json.load(f)

    prefijos = plz_de(de)
    with open(os.path.join(data, "plz_de.json"), "w", encoding="utf-8") as f:
        json.dump({
            "fuente": "GeoNames (CC BY 4.0) vía zauberware/postal-codes-json-xml-csv",
            "licencia": "https://creativecommons.org/licenses/by/4.0/",
            "descripcion": "Prefijo de código postal alemán (2 o 3 cifras) -> [latitud, longitud] "
                           "(mediana de los códigos postales del prefijo)",
            "prefijos": prefijos,
        }, f, ensure_ascii=False, separators=(",", ":"))

    filas, provincias, aprox, sin_coord = municipios_es(es, ine_mun, ine_prov)
    with open(os.path.join(data, "municipios_es.json"), "w", encoding="utf-8") as f:
        json.dump({
            "fuente": "INE (relación de municipios 2026, codeforspain/ds-organizacion-administrativa) "
                      "+ coordenadas de GeoNames (CC BY 4.0)",
            "licencia": "https://creativecommons.org/licenses/by/4.0/",
            "descripcion": "municipios: [codigo_ine, nombre, latitud, longitud, aproximada?]; "
                           "la provincia son las 2 primeras cifras del código INE",
            "provincias": provincias,
            "municipios": filas,
        }, f, ensure_ascii=False, separators=(",", ":"))

    print(f"plz_de: {len(prefijos)} prefijos")
    print(f"municipios_es: {len(filas)} municipios ({aprox} con coordenada aproximada, {sin_coord} sin coordenada)")


if __name__ == "__main__":
    main()
