<?php
namespace Omeka\Db\Connection;

use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;

/**
 * A DBAL Connection wrapper that transparently translates MySQL-specific SQL
 * to SQLite equivalents. This allows third-party modules that use MySQL-only
 * syntax (SHOW TABLES, SET FOREIGN_KEY_CHECKS, etc.) to work with SQLite.
 */
class SqliteCompatConnection extends Connection
{
    private const TRANSLATABLE_KEYWORDS = ['SHOW', 'SET', 'TRUNCATE', 'DESCRIBE', 'DESC'];

    public function exec($sql): int
    {
        $statements = $this->translateSql($sql);
        if ($statements === null) {
            return 0;
        }
        $result = 0;
        foreach ($statements as $stmt) {
            $result = parent::exec($stmt);
        }
        return $result;
    }

    public function query(...$args)
    {
        if (isset($args[0]) && is_string($args[0])) {
            $last = $this->execLeading($args[0]);
            if ($last === null) {
                return parent::query('SELECT 1 WHERE 0');
            }
            $args[0] = $last;
        }
        return parent::query(...$args);
    }

    public function executeQuery($sql, array $params = [], $types = [], ?QueryCacheProfile $qcp = null)
    {
        $last = $this->execLeading($sql);
        if ($last === null) {
            return parent::executeQuery('SELECT 1 WHERE 0', [], []);
        }
        return parent::executeQuery($last, $params, $types, $qcp);
    }

    public function executeStatement($sql, array $params = [], array $types = [])
    {
        $last = $this->execLeading($sql);
        if ($last === null) {
            return 0;
        }
        return parent::executeStatement($last, $params, $types);
    }

    /**
     * Translate and execute all leading statements, returning the final one.
     *
     * @return string|null The last translated statement to execute, or null to skip.
     */
    private function execLeading(string $sql): ?string
    {
        $statements = $this->translateSql($sql);
        if ($statements === null) {
            return null;
        }
        for ($i = 0, $last = count($statements) - 1; $i < $last; $i++) {
            parent::exec($statements[$i]);
        }
        return $statements[$last];
    }

    /**
     * Translate MySQL-specific SQL to SQLite equivalents.
     *
     * Returns null to suppress execution entirely (e.g. SET NAMES).
     * Returns a single-element array for direct translations.
     * Returns a multi-element array for compound statements where all but the
     * last are executed as leading statements.
     *
     * @return string[]|null
     */
    protected function translateSql(string $sql): ?array
    {
        $trimmed = trim($sql, " \t\n\r\0\x0B;");

        // Fast path: most queries don't need translation.
        $firstSpace = strpos($trimmed, ' ');
        $firstWord = $firstSpace !== false ? strtoupper(substr($trimmed, 0, $firstSpace)) : strtoupper($trimmed);
        if (!in_array($firstWord, self::TRANSLATABLE_KEYWORDS, true)) {
            return [$sql];
        }

        if (preg_match('/^SHOW\s+(FULL\s+)?TABLES/i', $trimmed)) {
            return ["SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"];
        }

        if (preg_match('/^SET\s+FOREIGN_KEY_CHECKS\s*=\s*0/i', $trimmed)) {
            return ['PRAGMA foreign_keys = OFF'];
        }
        if (preg_match('/^SET\s+FOREIGN_KEY_CHECKS\s*=\s*1/i', $trimmed)) {
            return ['PRAGMA foreign_keys = ON'];
        }

        // SET NAMES and other unsupported SET statements are no-ops for SQLite.
        if (preg_match('/^SET\s+/i', $trimmed)) {
            return null;
        }

        if (preg_match('/^(?:SHOW\s+COLUMNS\s+FROM|DESCRIBE|DESC)\s+[`"\']?(\w+)[`"\']?/i', $trimmed, $m)) {
            return ['PRAGMA table_info(' . $m[1] . ')'];
        }

        if (preg_match('/^TRUNCATE\s+(?:TABLE\s+)?[`"\']?(\w+)[`"\']?/i', $trimmed, $m)) {
            return ['DELETE FROM ' . $m[1]];
        }

        // Compound statements like "SET FOREIGN_KEY_CHECKS=0; DROP TABLE x".
        if (str_contains($trimmed, ';')) {
            $parts = array_filter(array_map('trim', explode(';', $trimmed)));
            if (count($parts) > 1) {
                $result = [];
                foreach ($parts as $part) {
                    $translated = $this->translateSql($part);
                    if ($translated !== null) {
                        array_push($result, ...$translated);
                    }
                }
                return $result ?: null;
            }
        }

        return [$sql];
    }
}
