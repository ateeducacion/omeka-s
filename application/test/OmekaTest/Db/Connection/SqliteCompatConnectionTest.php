<?php
namespace OmekaTest\Db\Connection;

use Doctrine\DBAL\DriverManager;
use Omeka\Db\Connection\SqliteCompatConnection;
use Omeka\Test\TestCase;

class SqliteCompatConnectionTest extends TestCase
{
    protected $connection;

    public function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'wrapperClass' => SqliteCompatConnection::class,
        ]);
        $this->connection->exec('CREATE TABLE `setting` (`id` VARCHAR(190) PRIMARY KEY, `value` TEXT)');
    }

    public function testInsertIgnoreKeepsTheExistingRow()
    {
        // Modules built on Common run MySQL's INSERT IGNORE when installing.
        $sql = 'INSERT IGNORE INTO `setting` (`id`, `value`) VALUES (?, ?)';
        $this->connection->executeStatement($sql, ['foo', '"first"']);
        $this->connection->executeStatement($sql, ['foo', '"second"']);

        $this->assertSame('"first"', $this->connection->fetchOne('SELECT `value` FROM `setting` WHERE `id` = ?', ['foo']));
    }
}
