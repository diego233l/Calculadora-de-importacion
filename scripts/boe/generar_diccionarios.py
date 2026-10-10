#!/usr/bin/env python3
"""
Genera o actualiza los diccionarios de marcas y modelos a partir de la base del BOE (herramienta OFFLINE).

Uso:
    python3 -I scripts/boe/generar_diccionarios.py data/boe/2026.sqlite data/diccionarios

Escribe:
  marcas.json            marca del BOE -> nombre visible + alias (nombres con los que aparece en mobile.de o se escribe a mano).
                         Se puede editar a mano: el script solo AÑADE las marcas nuevas del BOE y conserva lo que ya hay.
  modelos/<marca>.json   familias de modelo de cada marca, sacadas de los nombres del BOE («Golf», «A3 Sportback»…),
                         con el periodo comercial y los combustibles en que aparecen. Se regeneran enteros cada vez.
"""
from __future__ import annotations

import json
import re
import sqlite3
import sys
import unicodedata
from collections import Counter, defaultdict
from pathlib import Path

SIGLAS = {'BMW', 'BYD', 'DS', 'KGM', 'MG', 'JAC', 'GMC', 'FSM', 'EVO', 'EMC', 'KTM', 'PGO', 'SWM', 'LEVC', 'SEAT', 'BAIC', 'DFSK MOTORS', 'DR MOTOR', 'MINI'}
VISIBLE = {
    'MERCEDES': 'Mercedes-Benz', 'SSANYONG': 'SsangYong', 'CITROEN': 'Citroën', 'SKODA': 'Škoda',
    'LYNK & CO': 'Lynk & Co', 'DFSK MOTORS': 'DFSK', 'DR MOTOR': 'DR', 'DS': 'DS Automobiles', 'VOLKSWAGEN': 'Volkswagen',
    'MK SPORTSCAR': 'MK Sportscar', 'LEVC': 'LEVC', 'CENNTRO': 'Cenntro', 'KGM': 'KGM',
}
# Cómo escriben la marca mobile.de y los usuarios, además del nombre del BOE
ALIAS = {
    'MERCEDES': ['mercedes-benz', 'mercedes benz', 'mercedes', 'mercedes-amg', 'amg', 'mb'],
    'VOLKSWAGEN': ['vw', 'volkswagen', 'volkswagen nutzfahrzeuge', 'vw nutzfahrzeuge'],
    'SSANYONG': ['ssangyong', 'ssang yong'],
    'CITROEN': ['citroën', 'citroen'],
    'SKODA': ['škoda', 'skoda'],
    'DS': ['ds automobiles', 'ds'],
    'KGM': ['kgm', 'kg mobility'],
    'MG': ['mg', 'mg roewe', 'mg motor'],
    'LAND ROVER': ['land rover', 'land-rover', 'landrover'],
    'ALFA ROMEO': ['alfa romeo', 'alfa'],
    'ROLLS-ROYCE': ['rolls-royce', 'rolls royce'],
    'DR MOTOR': ['dr motor', 'dr automobiles', 'dr'],
    'LYNK & CO': ['lynk & co', 'lynk&co', 'lynk co'],
    'DFSK MOTORS': ['dfsk', 'dfsk motors'],
    'XPENG': ['xpeng', 'xiaopeng'],
    'ASTON MARTIN': ['aston martin'],
    'SMART': ['smart'],
    'CHEVROLET': ['chevrolet'],
    'CORVETTE': ['corvette'],
}
# Primeras palabras que no son el modelo por sí solas: el nombre de la familia incluye la siguiente («Range Rover», «Model 3»)
PREFIJOS = {'range', 'grand', 'land', 'new', 'nuevo', 'nvo.', 'model', 'serie', 'clase', 'gr', 'mercedes-amg', 'amg', 'id.', 'e-', 'mx'}


def sin_acentos(t: str) -> str:
    return ''.join(c for c in unicodedata.normalize('NFD', t) if unicodedata.category(c) != 'Mn')


def visible(boe: str) -> str:
    if boe in VISIBLE:
        return VISIBLE[boe]
    if boe in SIGLAS:
        return boe
    return boe.title()


def clave_familia(modelo_tipo: str) -> str:
    """
    Familia = la primera palabra del nombre del BOE (la segunda también tras «Range», «Model», «Grand»…, o si la primera
    es una letra suelta como en «C 220» o «E 350»). Es una pista para reconocer el modelo en un título, no una norma:
    la comparación con las filas del BOE usa el nombre completo.
    """
    palabras = [p for p in re.split(r'[\s()]+', modelo_tipo) if p]
    if not palabras:
        return ''
    primera = sin_acentos(palabras[0]).lower()
    if len(palabras) > 1 and (primera in PREFIJOS or (len(primera) == 1 and primera.isalpha() and re.match(r'^\d{2,3}', palabras[1]) is None)):
        return sin_acentos(' '.join(palabras[:2])).lower()
    return primera


def main() -> int:
    if len(sys.argv) != 3:
        print(__doc__)
        return 2
    bd = sqlite3.connect(sys.argv[1])
    destino = Path(sys.argv[2])
    (destino / 'modelos').mkdir(parents=True, exist_ok=True)

    marcas_boe = [r[0] for r in bd.execute('SELECT DISTINCT marca FROM vehiculos ORDER BY marca')]
    ruta_marcas = destino / 'marcas.json'
    actuales = json.loads(ruta_marcas.read_text(encoding='utf-8'))['marcas'] if ruta_marcas.exists() else {}
    marcas = dict(actuales)
    for m in marcas_boe:
        if m not in marcas:
            marcas[m] = {'nombre': visible(m), 'alias': ALIAS.get(m, [])}
    ruta_marcas.write_text(json.dumps({
        '_nota': 'Marca del BOE -> nombre visible y alias. Editable a mano; scripts/boe/generar_diccionarios.py solo añade marcas nuevas.',
        'marcas': dict(sorted(marcas.items())),
    }, ensure_ascii=False, indent=1) + '\n', encoding='utf-8')

    total = 0
    for m in marcas_boe:
        fam: dict[str, dict] = defaultdict(lambda: {'filas': 0, 'desde': 9999, 'hasta': 0, 'abierto': False, 'combustibles': set(), 'grafias': Counter()})
        for modelo, ini, fin, comb in bd.execute('SELECT modelo_tipo, inicio, fin, combustible FROM vehiculos WHERE marca = ?', (m,)):
            k = clave_familia(modelo)
            if not k:
                continue
            d = fam[k]
            d['filas'] += 1
            d['grafias'][' '.join(re.split(r'[\s()]+', modelo)[:k.count(' ') + 1])] += 1
            if ini:
                d['desde'] = min(d['desde'], ini)
                d['hasta'] = max(d['hasta'], fin or ini)
            if fin is None:
                d['abierto'] = True
            d['combustibles'].add(comb)
        salida = [
            {'modelo': d['grafias'].most_common(1)[0][0], 'filas': d['filas'], 'desde': d['desde'] if d['desde'] != 9999 else None,
             'hasta': None if d['abierto'] else (d['hasta'] or None), 'combustibles': sorted(d['combustibles'])}
            for k, d in sorted(fam.items(), key=lambda kv: (-kv[1]['filas'], kv[0]))
        ]
        total += len(salida)
        nombre_fichero = re.sub(r'[^a-z0-9]+', '_', sin_acentos(m).lower()).strip('_')
        (destino / 'modelos' / f'{nombre_fichero}.json').write_text(
            json.dumps({'marca': m, 'modelos': salida}, ensure_ascii=False, separators=(',', ':')) + '\n', encoding='utf-8')
    print(f'{len(marcas_boe)} marcas, {total} familias de modelo')
    return 0


if __name__ == '__main__':
    sys.exit(main())
