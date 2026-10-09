<?php
namespace Omeka\Db\Query;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\ORM\Query\SqlWalker;
use DoctrineExtensions\Query\Mysql\GroupConcat as MysqlGroupConcat;

/**
 * Dialect-aware GROUP_CONCAT DQL function.
 *
 * MySQL syntax:  GROUP_CONCAT([DISTINCT] x [ORDER BY ...] [SEPARATOR 'sep'])
 * SQLite syntax: GROUP_CONCAT([DISTINCT] x[, 'sep'] [ORDER BY ...])
 *
 * The parent class always emits the MySQL form; SEPARATOR is a MySQL-only
 * keyword that SQLite rejects as a syntax error, so on SQLite the separator
 * is emitted as a second argument instead.
 */
class GroupConcat extends MysqlGroupConcat
{
    public function getSql(SqlWalker $sqlWalker): string
    {
        if (!$sqlWalker->getConnection()->getDatabasePlatform() instanceof SqlitePlatform) {
            return parent::getSql($sqlWalker);
        }

        $result = 'GROUP_CONCAT(' . ($this->isDistinct ? 'DISTINCT ' : '');

        $fields = [];
        foreach ($this->pathExp as $pathExp) {
            $fields[] = $pathExp->dispatch($sqlWalker);
        }
        $result .= count($fields) === 1 ? $fields[0] : 'CONCAT(' . implode(', ', $fields) . ')';

        if ($this->separator) {
            $separator = $sqlWalker->walkStringPrimary($this->separator);
            if ($this->isDistinct && $separator !== "','") {
                throw new \RuntimeException('SQLite GROUP_CONCAT cannot combine DISTINCT with a custom separator.');
            }
            if (!$this->isDistinct) {
                $result .= ', ' . $separator;
            }
        }

        // SQLite supports ORDER BY inside aggregate calls since 3.44, placed
        // after the arguments: GROUP_CONCAT(x, 'sep' ORDER BY y).
        if ($this->orderBy) {
            if (version_compare($sqlWalker->getConnection()->fetchOne('SELECT sqlite_version()'), '3.44.0', '<')) {
                throw new \RuntimeException('Ordered GROUP_CONCAT requires SQLite 3.44 or later.');
            }
            $result .= ' ' . $sqlWalker->walkOrderByClause($this->orderBy);
        }

        return $result . ')';
    }
}
