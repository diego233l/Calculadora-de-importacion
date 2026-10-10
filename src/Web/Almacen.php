<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Web;

/**
 * Datos generados en ejecución, en /var/lib/<proyecto>/ (nunca en el repositorio):
 *   calculos.sqlite  cálculos compartidos (instantánea de la entrada y del resultado) y contadores de uso
 *   secreto.key      clave aleatoria para firmar los tokens CSRF (se crea sola la primera vez)
 * Todas las consultas son preparadas.
 */
final class Almacen
{
    private ?\PDO $pdo = null;

    public function __construct(private readonly string $dir)
    {
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /** @throws \RuntimeException si el directorio no existe y no se puede crear, o no se puede escribir en él */
    private function asegurarDirectorio(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0750, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('No se puede crear el directorio de datos ' . $this->dir);
        }
        if (!is_writable($this->dir)) {
            throw new \RuntimeException('No se puede escribir en el directorio de datos ' . $this->dir);
        }
    }

    private function bd(): \PDO
    {
        if ($this->pdo === null) {
            $this->asegurarDirectorio();
            $pdo = new \PDO('sqlite:' . $this->dir . '/calculos.sqlite', null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec('PRAGMA busy_timeout = 4000');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('CREATE TABLE IF NOT EXISTS calculos (
                id TEXT PRIMARY KEY,
                creado INTEGER NOT NULL,
                entrada TEXT NOT NULL,
                resultado TEXT NOT NULL
            )');
            $pdo->exec('CREATE INDEX IF NOT EXISTS calculos_creado ON calculos (creado)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS limites (
                clave TEXT NOT NULL,
                ventana INTEGER NOT NULL,
                n INTEGER NOT NULL,
                PRIMARY KEY (clave, ventana)
            )');
            $this->pdo = $pdo;
        }
        return $this->pdo;
    }

    public static function idValido(string $id): bool
    {
        return preg_match('/^[a-f0-9]{12}$/', $id) === 1;
    }

    /** Guarda una instantánea del cálculo y devuelve su identificador público (12 caracteres hexadecimales al azar). */
    public function guardarCalculo(array $entrada, array $resultado): string
    {
        $bd = $this->bd();
        $ins = $bd->prepare('INSERT INTO calculos (id, creado, entrada, resultado) VALUES (:id, :creado, :entrada, :resultado)');
        for ($intento = 0; $intento < 5; $intento++) {
            $id = bin2hex(random_bytes(6));
            try {
                $ins->execute([
                    ':id' => $id,
                    ':creado' => time(),
                    ':entrada' => json_encode($entrada, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ':resultado' => json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
                return $id;
            } catch (\PDOException $e) {
                if ($intento === 4) {
                    throw $e;
                }
            }
        }
        throw new \RuntimeException('No se pudo guardar el cálculo');
    }

    /** @return array{id: string, creado: int, entrada: array, resultado: array}|null */
    public function leerCalculo(string $id): ?array
    {
        if (!self::idValido($id)) {
            return null;
        }
        $q = $this->bd()->prepare('SELECT id, creado, entrada, resultado FROM calculos WHERE id = :id');
        $q->execute([':id' => $id]);
        $fila = $q->fetch();
        if (!$fila) {
            return null;
        }
        $entrada = json_decode((string) $fila['entrada'], true);
        $resultado = json_decode((string) $fila['resultado'], true);
        if (!is_array($entrada) || !is_array($resultado)) {
            return null;
        }
        return ['id' => $fila['id'], 'creado' => (int) $fila['creado'], 'entrada' => $entrada, 'resultado' => $resultado];
    }

    /**
     * Cuenta una acción de $clave (por ejemplo, la IP) y dice si todavía está dentro del límite de $max
     * acciones por ventana de $segundos.
     */
    public function permitir(string $clave, int $max, int $segundos = 60, ?int $ahora = null): bool
    {
        $ahora ??= time();
        $ventana = intdiv($ahora, $segundos);
        $bd = $this->bd();
        $bd->prepare('DELETE FROM limites WHERE ventana < :v')->execute([':v' => $ventana - 5]);
        $bd->prepare('INSERT INTO limites (clave, ventana, n) VALUES (:c, :v, 1)
                      ON CONFLICT (clave, ventana) DO UPDATE SET n = n + 1')
            ->execute([':c' => hash('sha256', $clave), ':v' => $ventana]);
        $q = $bd->prepare('SELECT n FROM limites WHERE clave = :c AND ventana = :v');
        $q->execute([':c' => hash('sha256', $clave), ':v' => $ventana]);
        return (int) $q->fetchColumn() <= $max;
    }

    /** Borra los cálculos caducados y los contadores antiguos. Se llama de vez en cuando, no en cada petición. */
    public function purgar(int $diasConservacion, ?int $ahora = null): void
    {
        $ahora ??= time();
        $bd = $this->bd();
        $bd->prepare('DELETE FROM calculos WHERE creado < :limite')->execute([':limite' => $ahora - $diasConservacion * 86400]);
        $bd->prepare('DELETE FROM limites WHERE ventana < :v')->execute([':v' => intdiv($ahora, 60) - 120]);
    }

    /** Clave para firmar los tokens CSRF. Se genera una vez y se guarda con permisos 0600. */
    public function secreto(): string
    {
        $this->asegurarDirectorio();
        $ruta = $this->dir . '/secreto.key';
        $actual = @file_get_contents($ruta);
        if (is_string($actual) && strlen($actual) >= 32) {
            return $actual;
        }
        $nuevo = bin2hex(random_bytes(32));
        if (@file_put_contents($ruta, $nuevo, LOCK_EX) === false) {
            throw new \RuntimeException('No se puede escribir la clave de seguridad en ' . $ruta);
        }
        @chmod($ruta, 0600);
        // Si dos peticiones la crean a la vez, gana la que quedó escrita
        $leida = @file_get_contents($ruta);
        return is_string($leida) && strlen($leida) >= 32 ? $leida : $nuevo;
    }
}
