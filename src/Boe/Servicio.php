<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Boe;

/**
 * Lo que necesita la web de la Orden del BOE: elegir la fila de un coche, listar las alternativas y traducir una
 * fila a lo que espera el motor de cálculo (`fila_boe`) y a lo que se guarda para enseñarla después (`boe`).
 */
final class Servicio
{
    /** Cuántas filas se proponen al usuario. */
    public const MAX_CANDIDATAS = 3;

    private function __construct(
        private readonly Repositorio $repositorio,
        private readonly Marcas $marcas,
        private readonly Emparejador $emparejador,
        private readonly int $anio,
    ) {
    }

    /** Null si no hay base del BOE para ese año (la web sigue funcionando con el precio medio escrito a mano). */
    public static function crear(string $dirData, int $anio): ?self
    {
        $repo = Repositorio::abrir($dirData, $anio);
        if ($repo === null) {
            return null;
        }
        $marcas = Marcas::cargar($dirData);
        return new self($repo, $marcas, new Emparejador($repo, $marcas), $anio);
    }

    public function anio(): int
    {
        return $this->anio;
    }

    public function marcas(): Marcas
    {
        return $this->marcas;
    }

    /**
     * Datos del coche (la entrada guardada) en la forma que entiende el emparejador. Sin marca y modelo no se busca:
     * sin ellos cualquier fila con la misma potencia valdría.
     *
     * @param array<string, mixed> $entrada
     * @return array<string, mixed>|null
     */
    public function consulta(array $entrada): ?array
    {
        $marca = trim((string) ($entrada['marca'] ?? ''));
        $modelo = trim((string) ($entrada['modelo'] ?? '') . ' ' . (string) ($entrada['version'] ?? ''));
        if ($marca === '' || trim((string) ($entrada['modelo'] ?? '')) === '') {
            return null;
        }
        $anio = preg_match('/^(\d{4})-/', (string) ($entrada['fecha_matriculacion'] ?? ''), $m) ? (int) $m[1] : null;
        return [
            'marca' => $marca,
            'modelo' => $modelo,
            'anio' => $anio,
            'cc' => isset($entrada['cc']) ? (int) $entrada['cc'] : null,
            'cv' => isset($entrada['cv']) ? (float) $entrada['cv'] : null,
            'kw' => isset($entrada['kw']) ? (float) $entrada['kw'] : null,
            'combustible' => isset($entrada['combustible']) ? (string) $entrada['combustible'] : null,
        ];
    }

    /**
     * Las filas más parecidas, de más a menos.
     *
     * @param array<string, mixed> $entrada
     * @return array{marca_boe: ?string, candidatas: list<array<string, mixed>>}
     */
    public function candidatas(array $entrada, int $max = self::MAX_CANDIDATAS): array
    {
        $consulta = $this->consulta($entrada);
        if ($consulta === null) {
            return ['marca_boe' => null, 'candidatas' => []];
        }
        return $this->emparejador->buscar($consulta, $max);
    }

    /**
     * Una fila concreta puntuada contra el coche, o null si no hay datos para compararla.
     *
     * @param array<string, mixed> $entrada
     * @param array<string, mixed> $fila
     * @return array<string, mixed>|null
     */
    public function evaluar(array $entrada, array $fila): ?array
    {
        $consulta = $this->consulta($entrada);
        return $consulta === null ? null : $this->emparejador->evaluar($consulta, $fila);
    }

    /** @return array<string, mixed>|null */
    public function fila(int $id): ?array
    {
        return $this->repositorio->fila($id);
    }

    /** ¿Es una fila de la marca de este coche? Evita que un formulario manipulado cuele una fila de otra marca. */
    public function esDeLaMarca(array $fila, array $entrada): bool
    {
        return $this->marcas->resolver((string) ($entrada['marca'] ?? '')) === $fila['marca'];
    }

    /**
     * Pone en la entrada la fila elegida: `fila_boe` (lo que usa el motor) y `boe` (lo que se enseña en el resultado).
     *
     * @param array<string, mixed> $entrada
     * @param array<string, mixed> $fila
     * @return array<string, mixed>
     */
    public function aplicar(array $entrada, array $fila, string $origen, ?float $puntuacion = null, ?string $confianza = null): array
    {
        $entrada['fila_boe'] = ['precio' => (float) $fila['valor']]
            + ($fila['cvf'] !== null ? ['cvf' => (float) $fila['cvf']] : [])
            + ($fila['cilindros'] !== null ? ['cilindros' => (int) $fila['cilindros']] : []);
        $entrada['boe'] = [
            'id' => $fila['id'], 'anio' => $this->anio, 'origen' => $origen, 'puntuacion' => $puntuacion, 'confianza' => $confianza,
            'marca' => $fila['marca'], 'modelo_tipo' => $fila['modelo_tipo'], 'inicio' => $fila['inicio'], 'fin' => $fila['fin'],
            'cc' => $fila['cc'], 'cilindros' => $fila['cilindros'], 'combustible' => $fila['combustible'], 'kw' => $fila['kw'],
            'cv' => $fila['cv'], 'cvf' => $fila['cvf'], 'valor' => $fila['valor'],
        ];
        return $entrada;
    }

    /**
     * Si el usuario no ha escrito el precio medio, propone la mejor fila. Devuelve la entrada con la fila puesta, o tal
     * cual si no hay ninguna fila que se parezca lo bastante.
     *
     * @param array<string, mixed> $entrada
     * @return array<string, mixed>
     */
    public function elegirAutomaticamente(array $entrada): array
    {
        if (isset($entrada['fila_boe'])) {
            return $entrada;
        }
        $mejor = $this->candidatas($entrada, 1)['candidatas'][0] ?? null;
        if ($mejor === null) {
            return $entrada;
        }
        return $this->aplicar($entrada, $mejor['fila'], 'auto', $mejor['puntuacion'], $mejor['confianza']);
    }

    /** Quita lo que venga de una fila anterior, para volver a buscar. @param array<string, mixed> $entrada @return array<string, mixed> */
    public static function olvidar(array $entrada): array
    {
        unset($entrada['fila_boe'], $entrada['boe']);
        return $entrada;
    }
}
