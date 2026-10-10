<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Boe;

use PDO;

/** Lectura (solo lectura) de data/boe/<año>.sqlite: los precios medios del anexo I de la Orden del BOE. */
final class Repositorio
{
    /** SQLITE_OPEN_READONLY: la aplicación nunca escribe en el repositorio. */
    private const SOLO_LECTURA = 1;

    /** @var array<string, list<array<string, mixed>>> */
    private array $cacheMarca = [];

    private function __construct(private readonly PDO $bd)
    {
    }

    public static function abrir(string $dirData, int $anio): ?self
    {
        $ruta = $dirData . '/boe/' . $anio . '.sqlite';
        if (!is_file($ruta)) {
            return null;
        }
        $bd = new PDO('sqlite:' . $ruta, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::SQLITE_ATTR_OPEN_FLAGS => self::SOLO_LECTURA,
        ]);
        return new self($bd);
    }

    /** @return array<string, string> clave => valor (año, fuente, url, filas) */
    public function meta(): array
    {
        $r = [];
        foreach ($this->bd->query('SELECT clave, valor FROM meta') as $f) {
            $r[(string) $f['clave']] = (string) $f['valor'];
        }
        return $r;
    }

    /** @return list<string> marcas tal como las escribe el BOE */
    public function marcas(): array
    {
        return array_map('strval', $this->bd->query('SELECT DISTINCT marca FROM vehiculos ORDER BY marca')->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<array<string, mixed>> */
    public function filasDeMarca(string $marca): array
    {
        if (!isset($this->cacheMarca[$marca])) {
            $st = $this->bd->prepare('SELECT * FROM vehiculos WHERE marca = :m ORDER BY id');
            $st->execute([':m' => $marca]);
            $this->cacheMarca[$marca] = array_map(self::tipar(...), $st->fetchAll());
        }
        return $this->cacheMarca[$marca];
    }

    /** @return array<string, mixed>|null */
    public function fila(int $id): ?array
    {
        $st = $this->bd->prepare('SELECT * FROM vehiculos WHERE id = :id');
        $st->execute([':id' => $id]);
        $f = $st->fetch();
        return $f === false ? null : self::tipar($f);
    }

    /**
     * @param array<string, mixed> $f
     * @return array<string, mixed>
     */
    private static function tipar(array $f): array
    {
        $entero = static fn(mixed $v): ?int => $v === null ? null : (int) $v;
        return [
            'id' => (int) $f['id'],
            'marca' => (string) $f['marca'],
            'modelo_tipo' => (string) $f['modelo_tipo'],
            'inicio' => $entero($f['inicio']),
            'fin' => $entero($f['fin']),
            'cc' => $entero($f['cc']),
            'cilindros' => $entero($f['cilindros']),
            'combustible' => (string) $f['combustible'],
            'motor_codigo' => (string) $f['motor_codigo'],
            'kw' => (float) $f['kw'],
            'cvf' => $f['cvf'] === null ? null : (float) $f['cvf'],
            'cv' => (int) $f['cv'],
            'valor' => (int) $f['valor'],
            'pagina' => (int) $f['pagina'],
        ];
    }
}
