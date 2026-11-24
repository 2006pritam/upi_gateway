<?php

namespace App\Support\PurePg;

use PDOException;

/** PDOException carrying a string SQLSTATE as its code, like pdo_pgsql does (Laravel checks "23505" etc.). */
final class PurePgException extends PDOException
{
    public static function make(string $message, string $sqlState): self
    {
        $e = new self("SQLSTATE[{$sqlState}]: {$message}");
        $e->errorInfo = [$sqlState, null, $message];
        $e->code = $sqlState;

        return $e;
    }
}
