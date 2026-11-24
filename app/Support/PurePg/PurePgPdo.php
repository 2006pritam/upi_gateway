<?php

namespace App\Support\PurePg;

use PDO;
use PDOStatement;

/**
 * A PDO look-alike backed by PgWire. It implements only what Laravel's PostgreSQL grammar and connection use.
 * Values are sent as quoted literals of unknown type (like pdo_pgsql's text binding), so Postgres infers
 * the right type from context.
 */
final class PurePgPdo extends PDO
{
    private array $attributes = [];

    public function __construct(private PgWire $wire)
    {
        // Intentionally not calling parent::__construct(): there is no real PDO driver behind this object.
    }

    public function wire(): PgWire
    {
        return $this->wire;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new PurePgStatement($this, $query);
    }

    public function exec(string $statement): int|false
    {
        $affected = 0;
        foreach ($this->wire->query($statement) as $result) {
            $affected += self::affected($result['tag']);
        }

        return $affected;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $statement = new PurePgStatement($this, $query);
        $statement->execute();

        return $statement;
    }

    public function beginTransaction(): bool
    {
        $this->wire->query('BEGIN');

        return true;
    }

    public function commit(): bool
    {
        $this->wire->query('COMMIT');

        return true;
    }

    public function rollBack(): bool
    {
        $this->wire->query('ROLLBACK');

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->wire->txStatus !== 'I';
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return false; // Laravel's Postgres processor uses INSERT ... RETURNING instead.
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        $this->attributes[$attribute] = $value;

        return true;
    }

    public function getAttribute(int $attribute): mixed
    {
        return match ($attribute) {
            PDO::ATTR_DRIVER_NAME => 'pgsql',
            PDO::ATTR_SERVER_VERSION => '16.0',
            default => $this->attributes[$attribute] ?? null,
        };
    }

    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        return self::literal($string);
    }

    public function errorCode(): ?string
    {
        return null;
    }

    public function errorInfo(): array
    {
        return ['00000', null, null];
    }

    /** Quote any value as a string literal (or NULL). Safe because client_encoding is UTF8 and backslashes are literal. */
    public static function literal(mixed $value, int $type = PDO::PARAM_STR): string
    {
        if ($value === null || $type === PDO::PARAM_NULL) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? "'t'" : "'f'";
        }
        if (is_float($value)) {
            $value = json_encode($value);
        } else {
            $value = (string) $value;
        }
        if (str_contains($value, "\0")) {
            throw PurePgException::make('invalid byte sequence for encoding "UTF8": 0x00', '22021');
        }
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw PurePgException::make('invalid byte sequence for encoding "UTF8"', '22021');
        }

        return "'".str_replace("'", "''", $value)."'";
    }

    public static function affected(string $tag): int
    {
        // "INSERT 0 3", "UPDATE 2", "DELETE 1", "SELECT 5", "MERGE 4"
        return preg_match('/^(?:INSERT \d+|UPDATE|DELETE|SELECT|MERGE|MOVE|FETCH|COPY) (\d+)$/', $tag, $m) ? (int) $m[1] : 0;
    }
}
