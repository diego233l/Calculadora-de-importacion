#!/usr/bin/env python3
"""
Extrae el anexo I (turismos y todo terreno) de la Orden de precios medios del BOE a una base SQLite.

Herramienta OFFLINE: se ejecuta a mano una vez al año y el resultado (data/boe/<año>.sqlite) se sube al repositorio.
La web nunca ejecuta esto. Necesita `pdftotext` (poppler-utils).

Uso:
    python3 -I scripts/boe/extraer_boe.py orden.pdf data/boe/2026.sqlite 2026 [--url URL_DEL_BOE] [--verboso]

Cada fila del anexo tiene, de izquierda a derecha:
    Modelo-Tipo | Inicio | Fin | C.C. | N.º cilind. | Tipo de motor | P kW | cvf | cv | Valor euros
Inicio, Fin, C.C. y cilindros pueden estar en blanco (eléctricos, modelos que aún se fabrican); el cvf, en algunos
eléctricos. Los nombres largos se parten en varias líneas y las cifras quedan centradas en vertical entre ellas,
así que se leen las COORDENADAS de cada palabra (pdftotext -tsv): la columna de una cifra se decide por su posición
horizontal y las líneas de texto se reparten entre las filas con una regla de simetría (ver `agrupar`).
"""
from __future__ import annotations

import re
import sqlite3
import subprocess
import sys
from pathlib import Path

MOTORES = {
    'G': 'gasolina',
    'D': 'diesel',
    'M': 'etanol',        # Etanol + gasolina o bio
    'S': 'glp',           # Gasolina/GLP
    'Elc': 'electrico',
    'H': 'hidrogeno',
    'PHEV': 'phev',
    'GyE': 'hibrido',     # híbrido no enchufable gasolina + eléctrico
    'DyE': 'hibrido',     # híbrido no enchufable diésel + eléctrico
    'SyE': 'hibrido',     # híbrido no enchufable gasolina GLP + eléctrico
}

# Columnas por la posición horizontal del CENTRO de la palabra (los números van centrados bajo su cabecera)
COLUMNAS = [
    ('inicio', 280, 308), ('fin', 308, 338), ('cc', 338, 370), ('cil', 370, 398), ('motor', 398, 430),
    ('kw', 430, 461), ('cvf', 461, 490), ('cv', 490, 518), ('valor', 518, 556),
]
X_TEXTO_MAX = 280          # a la izquierda de esto es el nombre del modelo
X_MARGEN = 556             # a la derecha, el margen con «Verificable en…» / «cve:»
Y_CABECERA_PAGINA = 90     # por encima, el rótulo «BOLETÍN OFICIAL DEL ESTADO / Núm. / Pág.»
ALTO_CABECERA_TABLA = 66   # desde «Marca: X»: la última línea de la cabecera de la tabla está a +57 y la primera fila, a partir de +73
TOLERANCIA_Y = 1.0
NUMERO_MILES = re.compile(r'^\d{1,3}(\.\d{3})*$')
NUMERO_DEC = re.compile(r'^\d+(,\d+)?$')


def num_es(texto: str) -> float:
    return float(texto.replace('.', '').replace(',', '.')) if ',' in texto else float(texto.replace('.', ''))


def palabras_tsv(pdf: Path, primera: int, ultima: int) -> dict[int, list[tuple[float, float, float, str]]]:
    """pagina -> [(izquierda, arriba, ancho, texto)] de las palabras de esas páginas."""
    salida = subprocess.run(
        ['pdftotext', '-tsv', '-f', str(primera), '-l', str(ultima), str(pdf), '-'],
        check=True, capture_output=True, text=True, encoding='utf-8',
    ).stdout
    paginas: dict[int, list[tuple[float, float, float, str]]] = {}
    for linea in salida.split('\n'):
        c = linea.split('\t')
        if len(c) != 12 or c[0] != '5':
            continue
        paginas.setdefault(int(c[1]), []).append((float(c[6]), float(c[7]), float(c[8]), c[11]))
    return paginas


def lineas_de(palabras: list[tuple[float, float, float, str]]) -> list[tuple[float, list[tuple[float, float, str]]]]:
    """Agrupa palabras por su coordenada vertical: [(arriba, [(izq, ancho, texto)…])] de arriba abajo."""
    grupos: list[tuple[float, list[tuple[float, float, str]]]] = []
    for izq, arriba, ancho, texto in sorted(palabras, key=lambda p: (p[1], p[0])):
        if grupos and abs(grupos[-1][0] - arriba) <= TOLERANCIA_Y:
            grupos[-1][1].append((izq, ancho, texto))
        else:
            grupos.append((arriba, [(izq, ancho, texto)]))
    for _, ws in grupos:
        ws.sort()
    return grupos


def columna(izq: float, ancho: float) -> str:
    centro = izq + ancho / 2
    if centro < X_TEXTO_MAX:
        return 'texto'
    for nombre, a, b in COLUMNAS:
        if a <= centro < b:
            return nombre
    return '?'


def celdas(ws: list[tuple[float, float, str]]) -> tuple[str, dict[str, str]]:
    texto: list[str] = []
    num: dict[str, str] = {}
    for izq, ancho, t in ws:
        col = columna(izq, ancho)
        if col == 'texto':
            texto.append(t)
        elif col == '?' or col in num:
            raise ValueError(f'cifra fuera de columna: {t!r} en x={izq:.0f}')
        else:
            num[col] = t
    return ' '.join(texto), num


def unir(partes: list[str]) -> str:
    """Une las líneas de un nombre partido: «S-» + «Tronic» -> «S-Tronic»; el resto, con un espacio."""
    resultado = ''
    for p in partes:
        resultado = resultado + p if resultado.endswith('-') else (resultado + ' ' + p).strip()
    return resultado


def fila_desde(marca: str, texto: str, num: dict[str, str], pagina: int) -> tuple:
    for obligatoria in ('motor', 'kw', 'cv', 'valor'):
        if obligatoria not in num:
            raise ValueError(f'falta la columna {obligatoria}: {texto!r} {num}')
    if num['motor'] not in MOTORES:
        raise ValueError(f'tipo de motor desconocido {num["motor"]!r}: {texto!r}')
    if not NUMERO_MILES.match(num['valor']) or not NUMERO_MILES.match(num['cv']):
        raise ValueError(f'valor o cv ilegibles: {texto!r} {num}')
    if not NUMERO_DEC.match(num['kw']) or ('cvf' in num and not re.match(r'^\d+,\d+$', num['cvf'])):
        raise ValueError(f'kW o cvf ilegibles: {texto!r} {num}')
    for k in ('inicio', 'fin', 'cc', 'cil'):
        if k in num and not num[k].isdigit():
            raise ValueError(f'{k} ilegible {num[k]!r}: {texto!r}')
    ini = int(num['inicio']) if 'inicio' in num else None
    fin = int(num['fin']) if 'fin' in num else None
    for a in (ini, fin):
        if a is not None and not 1980 <= a <= 2030:
            raise ValueError(f'año fuera de rango {a}: {texto!r}')
    cc = int(num['cc']) if 'cc' in num else None
    cil = int(num['cil']) if 'cil' in num else None
    if cil is not None and cc is None:
        raise ValueError(f'cilindros sin cilindrada: {texto!r} {num}')
    return (
        marca, texto, ini, fin, cc, cil, MOTORES[num['motor']], num['motor'],
        num_es(num['kw']), (num_es(num['cvf']) or None) if 'cvf' in num else None,   # «0,00» en la Orden = sin dato
        int(num['cv'].replace('.', '')),
        int(num['valor'].replace('.', '')), pagina,
    )


class Agrupador:
    """
    Reparte las líneas de la tabla entre las filas. Las cifras de una fila quedan centradas en vertical respecto a
    su nombre, así que una fila con k líneas de nombre por encima de las cifras tiene otras k por debajo (o la línea
    de las cifras lleva su propio texto, en el centro). Recorriendo de arriba abajo: las líneas de solo texto que
    llevan esperando al llegar a unas cifras son las k primeras; las k siguientes cierran ese nombre y el resto abre
    el de la fila siguiente. Una fila puede partirse entre dos páginas, así que el estado pasa de página a página
    mientras no cambie la marca (la cabecera «Marca: X» se repite en cada página).
    """

    def __init__(self, problemas: list[str]) -> None:
        self.problemas = problemas
        self.filas: list[tuple] = []
        self.marca: str | None = None
        self.pagina = 0
        self.esperando: list[str] = []
        self.abierta: list | None = None          # [cifras, partes del nombre, líneas que faltan por debajo, página]

    def _cerrar(self) -> None:
        if self.abierta is None:
            return
        cifras, partes, faltan, pagina = self.abierta
        self.abierta = None
        if faltan:
            self.problemas.append(f'p.{pagina} {self.marca}: al nombre {unir(partes)!r} le faltan {faltan} línea(s) de texto')
        try:
            self.filas.append(fila_desde(self.marca or '', unir(partes), cifras, pagina))
        except ValueError as e:
            self.problemas.append(f'p.{pagina} {self.marca}: {e}')

    def cambiar_marca(self, marca: str) -> None:
        if marca == self.marca:
            return                                  # cabecera repetida al empezar página: el estado sigue
        self.terminar()
        self.marca = marca

    def terminar(self) -> None:
        self._cerrar()
        if self.esperando:
            self.problemas.append(f'p.{self.pagina} {self.marca}: líneas de texto sin cifras: {self.esperando}')
        self.esperando = []

    def linea(self, texto: str, cifras: dict[str, str], pagina: int) -> None:
        self.pagina = pagina
        if not cifras:                              # línea de solo texto
            if self.abierta is not None and self.abierta[2] > 0:
                self.abierta[1].append(texto)
                self.abierta[2] -= 1
                if self.abierta[2] == 0:
                    self._cerrar()
            else:
                self.esperando.append(texto)
            return
        self._cerrar()
        self.abierta = [cifras, self.esperando + ([texto] if texto else []), len(self.esperando), pagina]
        self.esperando = []
        if self.abierta[2] == 0:
            self._cerrar()


def procesar_pagina(palabras: list[tuple[float, float, float, str]], pagina: int, agrupador: Agrupador,
                    y_fin: float | None, problemas: list[str]) -> None:
    palabras = [p for p in palabras if p[1] >= Y_CABECERA_PAGINA and p[0] < X_MARGEN and (y_fin is None or p[1] < y_fin)]
    salto_hasta = 0.0
    for y, ws in lineas_de(palabras):
        textos = [t for _, _, t in ws]
        if textos and textos[0] == 'Marca:':
            agrupador.cambiar_marca(' '.join(textos[1:]))
            salto_hasta = y + ALTO_CABECERA_TABLA
            continue
        if y < salto_hasta or agrupador.marca is None:
            continue                                # cabecera de la tabla, o título y leyenda anteriores a la primera marca
        try:
            texto, cifras = celdas(ws)
        except ValueError as e:
            problemas.append(f'p.{pagina} {agrupador.marca}: {e}')
            continue
        agrupador.linea(texto, cifras, pagina)


def extraer(pdf: Path) -> tuple[list[tuple], list[str]]:
    problemas: list[str] = []
    agrupador = Agrupador(problemas)
    pagina = 4                                            # el anexo I empieza en la página 4 de la Orden de 2026
    paso = 40
    fin = False
    while not fin:
        paginas = palabras_tsv(pdf, pagina, pagina + paso - 1)
        if not paginas:
            break
        for n in sorted(paginas):
            palabras = paginas[n]
            y_fin = None
            # «Precios medios de autocaravanas usadas…» cierra la parte de turismos y todo terreno
            for y, ws in lineas_de(palabras):
                if [t for _, _, t in ws][:4] == ['Precios', 'medios', 'de', 'autocaravanas']:
                    y_fin, fin = y, True
                    break
            procesar_pagina(palabras, n, agrupador, y_fin, problemas)
            if fin:
                break
        pagina += paso
    agrupador.terminar()
    return agrupador.filas, problemas


def escribir(filas: list[tuple], destino: Path, anio: int, url: str) -> None:
    destino.parent.mkdir(parents=True, exist_ok=True)
    if destino.exists():
        destino.unlink()
    bd = sqlite3.connect(destino)
    bd.executescript('''
        CREATE TABLE meta (clave TEXT PRIMARY KEY, valor TEXT NOT NULL);
        CREATE TABLE vehiculos (
            id INTEGER PRIMARY KEY,
            marca TEXT NOT NULL,
            modelo_tipo TEXT NOT NULL,
            inicio INTEGER,
            fin INTEGER,
            cc INTEGER,
            cilindros INTEGER,
            combustible TEXT NOT NULL,
            motor_codigo TEXT NOT NULL,
            kw REAL NOT NULL,
            cvf REAL,
            cv INTEGER NOT NULL,
            valor INTEGER NOT NULL,
            pagina INTEGER NOT NULL
        );
        CREATE INDEX vehiculos_marca ON vehiculos (marca);
    ''')
    bd.executemany(
        'INSERT INTO vehiculos (marca, modelo_tipo, inicio, fin, cc, cilindros, combustible, motor_codigo, kw, cvf, cv, valor, pagina)'
        ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', filas)
    bd.executemany('INSERT INTO meta VALUES (?,?)', [
        ('anio', str(anio)),
        ('fuente', 'Orden HAC/1501/2025, anexo I (turismos y todo terreno)'),
        ('url', url),
        ('filas', str(len(filas))),
    ])
    bd.commit()
    bd.execute('VACUUM')
    bd.close()


def main() -> int:
    argv = sys.argv[1:]
    url = 'https://www.boe.es/diario_boe/txt.php?id=BOE-A-2025-26357'
    if '--url' in argv:
        i = argv.index('--url')
        url = argv[i + 1]
        del argv[i:i + 2]
    verboso = '--verboso' in argv
    args = [a for a in argv if not a.startswith('--')]
    if len(args) != 3:
        print(__doc__)
        return 2
    pdf, destino, anio = Path(args[0]), Path(args[1]), int(args[2])
    filas, problemas = extraer(pdf)
    escribir(filas, destino, anio, url)
    print(f'{len(filas)} filas escritas en {destino}')
    if problemas:
        print(f'{len(problemas)} problemas:')
        for p in (problemas if verboso else problemas[:60]):
            print('  ', p)
    return 1 if problemas else 0


if __name__ == '__main__':
    sys.exit(main())
