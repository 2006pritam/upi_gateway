<?php

namespace App\Support\PurePg;

use PDO;
use PDOStatement;

final class PurePgStatement extends PDOStatement
{
    private const OIDS_INT = [20, 21, 23, 26];

    private const OIDS_FLOAT = [700, 701];

    private array $bound = [];

    private array $rows = [];

    private array $fields = [];

    private int $cursor = 0;

    private int $affected = 0;

    private int $fetchMode = PDO::FETCH_BOTH;

    public function __construct(private PurePgPdo $pdo, string $sql)
    {
        $this->queryString = $sql;
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->bound[$param] = [$value, $type];

        return true;
    }

    public function execute(?array $params = null): bool
    {
        if ($params !== null) {
            $this->bound = [];
            foreach (array_values($params) as $i => $v) {
                $this->bound[$i + 1] = [$v, PDO::PARAM_STR];
            }
        }

        $sql = $this->interpolate($this->queryString);
        $this->rows = [];
        $this->fields = [];
        $this->cursor = 0;
        $this->affected = 0;

        foreach ($this->pdo->wire()->query($sql) as $result) {
            $this->affected += PurePgPdo::affected($result['tag']);
            if ($result['fields']) {
                $this->fields = $result['fields'];
                $this->rows = array_map(fn ($r) => $this->castRow($r), $result['rows']);
            }
        }

        return true;
    }

    public function setFetchMode(int $mode, mixed ...$args): bool
    {
        $this->fetchMode = $mode;

        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (! isset($this->rows[$this->cursor])) {
            return false;
        }

        return $this->shape($this->rows[$this->cursor++], $mode === PDO::FETCH_DEFAULT ? $this->fetchMode : $mode);
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $mode = $mode === PDO::FETCH_DEFAULT ? $this->fetchMode : $mode;
        $out = [];
        while (($row = $this->fetch($mode)) !== false) {
            $out[] = $mode === PDO::FETCH_COLUMN ? $row : $row;
        }

        return $out;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->rows[$this->cursor++] ?? null;

        return $row === null ? false : array_values($row)[$column] ?? false;
    }

    public function rowCount(): int
    {
        return $this->affected;
    }

    public function columnCount(): int
    {
        return count($this->fields);
    }

    public function closeCursor(): bool
    {
        $this->rows = [];
        $this->cursor = 0;

        return true;
    }

    /** @param array<int, ?string> $raw */
    private function castRow(array $raw): array
    {
        $row = [];
        foreach ($this->fields as $i => [$name, $oid]) {
            $v = $raw[$i];
            if ($v !== null) {
                if (in_array($oid, self::OIDS_INT, true)) {
                    $v = (int) $v;
                } elseif (in_array($oid, self::OIDS_FLOAT, true)) {
                    $v = (float) $v;
                } elseif ($oid === 16) {
                    $v = $v === 't';
                }
            }
            $row[$name] = $v;
        }

        return $row;
    }

    private function shape(array $row, int $mode): mixed
    {
        return match ($mode) {
            PDO::FETCH_ASSOC => $row,
            PDO::FETCH_NUM => array_values($row),
            PDO::FETCH_COLUMN => array_values($row)[0] ?? null,
            PDO::FETCH_BOTH => $row + array_values($row),
            default => (object) $row, // FETCH_OBJ (Laravel's default)
        };
    }

    /** Replace each `?` that is outside quotes, identifiers, comments and dollar quotes with a quoted literal. `??` stays a literal `?`. */
    private function interpolate(string $sql): string
    {
        $out = '';
        $n = strlen($sql);
        $index = 0;

        for ($i = 0; $i < $n; $i++) {
            $c = $sql[$i];

            if ($c === "'" || $c === '"') {
                $j = $i + 1;
                while ($j < $n) {
                    if ($sql[$j] === $c) {
                        if (($sql[$j + 1] ?? '') === $c) {
                            $j += 2;
                            continue;
                        }
                        break;
                    }
                    $j++;
                }
                $out .= substr($sql, $i, $j - $i + 1);
                $i = $j;
            } elseif ($c === '-' && ($sql[$i + 1] ?? '') === '-') {
                $j = strpos($sql, "\n", $i);
                $j = $j === false ? $n : $j;
                $out .= substr($sql, $i, $j - $i);
                $i = $j - 1;
            } elseif ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
                $j = strpos($sql, '*/', $i + 2);
                $j = $j === false ? $n : $j + 2;
                $out .= substr($sql, $i, $j - $i);
                $i = $j - 1;
            } elseif ($c === '$' && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $m, 0, $i)) {
                $end = strpos($sql, $m[0], $i + strlen($m[0]));
                $j = $end === false ? $n : $end + strlen($m[0]);
                $out .= substr($sql, $i, $j - $i);
                $i = $j - 1;
            } elseif ($c === '?') {
                if (($sql[$i + 1] ?? '') === '?') {
                    $out .= '?';
                    $i++;
                    continue;
                }
                $index++;
                if (! array_key_exists($index, $this->bound)) {
                    throw PurePgException::make('Invalid parameter number: parameter was not defined', 'HY093');
                }
                [$value, $type] = $this->bound[$index];
                $out .= PurePgPdo::literal($value, $type);
            } else {
                $out .= $c;
            }
        }

        if ($index !== count($this->bound) && $this->bound !== []) {
            throw PurePgException::make('Invalid parameter number: number of bound variables does not match number of tokens', 'HY093');
        }

        return $out;
    }
}
