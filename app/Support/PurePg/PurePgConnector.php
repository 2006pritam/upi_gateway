<?php

namespace App\Support\PurePg;

use Illuminate\Database\Connectors\PostgresConnector;
use PDO;

/** Drop-in for Laravel's pgsql connector on hosts without pdo_pgsql. Enable with DB_PURE_PHP=true. */
final class PurePgConnector extends PostgresConnector
{
    /** One live connection per process, reused between requests when the host keeps the PHP process warm. */
    private static array $pool = [];

    protected function createPdoConnection($dsn, $username, $password, $options)
    {
        $p = [];
        preg_match_all("/(\w+)=(?:'((?:[^'\\\\]|\\\\.)*)'|([^;]*))/", preg_replace('/^pgsql:/', '', $dsn), $m, PREG_SET_ORDER);
        foreach ($m as $kv) {
            $p[$kv[1]] = stripslashes(($kv[2] ?? '') !== '' ? $kv[2] : ($kv[3] ?? ''));
        }
        $host = $p['host'] ?? '127.0.0.1';
        $port = (int) ($p['port'] ?? 5432);
        $db = $p['dbname'] ?? '';
        $key = md5(implode('|', [$host, $port, $db, $username, $password]));

        if (isset(self::$pool[$key]) && self::$pool[$key]->wire()->isConnected()) {
            $pdo = self::$pool[$key];
            try {
                if ($pdo->inTransaction()) {
                    $pdo->exec('ROLLBACK');
                }
                $pdo->exec('SELECT 1');

                return $pdo;
            } catch (\Throwable) {
                $pdo->wire()->close();
            }
        }

        $wire = new PgWire($host, $port, $db, (string) $username, (string) $password, $p['sslmode'] ?? 'require');
        $wire->connect();

        return self::$pool[$key] = new PurePgPdo($wire);
    }
}
