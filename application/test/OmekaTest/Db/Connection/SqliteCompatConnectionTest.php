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

    /** @dataProvider executionMethods */
    public function testCompoundStatementsKeepAllOperations($method)
    {
        $this->connection->$method('SET FOREIGN_KEY_CHECKS=0; DROP TABLE setting; SET FOREIGN_KEY_CHECKS=1');
        $this->assertSame([], $this->connection->getSchemaManager()->listTableNames());
        $this->assertSame(1, (int) $this->connection->fetchOne('PRAGMA foreign_keys'));
    }

    public function executionMethods()
    {
        return [['exec'], ['query'], ['executeQuery'], ['executeStatement']];
    }

    public function testCompoundSqlPreservesQuotedSeparatorsAndComments()
    {
        $this->connection->exec("-- install; NOW()\nINSERT INTO setting VALUES ('foo', 'a; b'); /* ; */ INSERT INTO setting VALUES ('bar', 'c; d')");
        $this->assertSame(['a; b', 'c; d'], $this->connection->fetchFirstColumn('SELECT value FROM setting ORDER BY id DESC'));
    }

    public function testPreparedQueriesAreTranslatedWithoutExecutingAtPrepareTime()
    {
        $statement = $this->connection->prepare('INSERT IGNORE INTO setting VALUES (?, NOW())');
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM setting'));
        $statement->execute(['foo']);
        $statement->execute(['foo']);
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM setting'));
        $this->assertNotNull($this->connection->fetchOne('SELECT value FROM setting'));
    }

    public function testPreparedCompoundQueriesDoNotExecuteLeadingStatements()
    {
        try {
            $this->connection->prepare("DROP TABLE setting; SELECT 1");
            $this->fail('Preparing multiple statements must fail before any are executed.');
        } catch (\RuntimeException $e) {
            $this->assertContains('setting', $this->connection->getSchemaManager()->listTableNames());
        }
    }

    public function testCompoundParametersAreRejectedBeforeAnyChanges()
    {
        $this->connection->exec("INSERT INTO setting VALUES ('existing', 'keep')");
        try {
            $this->connection->executeStatement('DELETE FROM setting; INSERT INTO setting VALUES (?, ?)', ['foo', 'bar']);
            $this->fail('Parameterized batches must not execute with unbound leading statements.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('parameters', $e->getMessage());
            $this->assertSame('keep', $this->connection->fetchOne('SELECT value FROM setting'));
        }
    }

    public function testShowTablesHonorsLikeAndFull()
    {
        $this->connection->exec('CREATE TABLE other (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        $this->assertSame(['setting'], $this->connection->fetchFirstColumn("SHOW TABLES LIKE 'set%'"));
        $this->assertSame(['BASE TABLE'], array_column($this->connection->fetchAllAssociative("SHOW FULL TABLES LIKE 'other'"), 'Table_type'));
        $this->assertNotContains('sqlite_sequence', $this->connection->fetchFirstColumn('SHOW TABLES'));
    }

    /** @dataProvider columnQueries */
    public function testColumnMetadataHasMysqlFieldNames($sql)
    {
        $columns = $this->connection->fetchAllAssociative($sql);
        $this->assertSame('id', $columns[0]['Field']);
        $this->assertSame('PRI', $columns[0]['Key']);
        $this->assertSame('NO', $columns[0]['Null']);
        $this->assertSame('varchar(190)', strtolower($columns[0]['Type']));
        $this->assertArrayHasKey('Default', $columns[0]);
        $this->assertArrayHasKey('Extra', $columns[0]);
    }

    public function columnQueries()
    {
        return [['SHOW COLUMNS FROM setting'], ['DESCRIBE setting'], ['DESC setting']];
    }

    public function testShowColumnsHonorsLike()
    {
        $this->assertSame(['value'], $this->connection->fetchFirstColumn("SHOW COLUMNS FROM setting LIKE 'val%'"));
    }

    public function testCreateTablePreservesDefaultsAndQuotedIdentifiers()
    {
        $this->connection->exec("CREATE TABLE example (`int` VARCHAR(190) DEFAULT 'BIGINT, NOW(); AUTO_INCREMENT', number INT UNSIGNED, note TEXT COMMENT 'a,b')");
        $this->connection->exec('INSERT INTO example DEFAULT VALUES');
        $this->assertSame('BIGINT, NOW(); AUTO_INCREMENT', $this->connection->fetchOne('SELECT `int` FROM example'));
        $this->assertSame('INTEGER', $this->connection->fetchAllAssociative('PRAGMA table_info(example)')[1]['type']);
    }

    public function testNamedIndexesAreUniqueAcrossTablesAndCreationIsIdempotent()
    {
        foreach (['first', 'second', 'first'] as $table) {
            $this->connection->exec("CREATE TABLE IF NOT EXISTS $table (id INT, value VARCHAR(190), KEY shared (value(10)), UNIQUE KEY unique_value (value))");
        }
        $this->connection->exec("INSERT INTO first VALUES (1, 'foo')");
        $this->connection->exec("INSERT INTO second VALUES (2, 'foo')");
        $this->assertCount(2, $this->connection->fetchAllAssociative('PRAGMA index_list(first)'));
        $this->assertCount(2, $this->connection->fetchAllAssociative('PRAGMA index_list(second)'));
    }

    /** @dataProvider unsupportedQueries */
    public function testUnsupportedSqlFailsExplicitly($sql)
    {
        $this->expectException(\RuntimeException::class);
        $this->connection->exec($sql);
    }

    public function unsupportedQueries()
    {
        return [
            ['SET arbitrary_variable=1'],
            ['ALTER TABLE setting ADD CONSTRAINT fk FOREIGN KEY (id) REFERENCES setting(id)'],
            ['CREATE TABLE example (text TEXT, FULLTEXT KEY search (text))'],
        ];
    }

    public function testForeignKeySwitchInTransactionFailsExplicitly()
    {
        $this->connection->exec('PRAGMA foreign_keys = ON');
        $this->connection->beginTransaction();
        try {
            $this->expectException(\RuntimeException::class);
            $this->connection->exec('SET FOREIGN_KEY_CHECKS=0');
        } finally {
            $this->connection->rollBack();
        }
    }

    public function testInlineForeignKeysRemainEnforced()
    {
        $this->connection->exec('PRAGMA foreign_keys = ON');
        $this->connection->exec('CREATE TABLE child (id INT PRIMARY KEY, parent_id VARCHAR(190), CONSTRAINT fk FOREIGN KEY (parent_id) REFERENCES setting(id) ON DELETE CASCADE)');
        $this->connection->executeStatement('INSERT INTO setting VALUES (?, ?)', ['foo', 'bar']);
        $this->connection->exec("INSERT INTO child VALUES (1, 'foo')");
        $this->connection->exec("DELETE FROM setting WHERE id='foo'");
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM child'));
        $this->assertSame([], $this->connection->fetchAllAssociative('PRAGMA foreign_key_check'));
    }

    public function testCoreSchemaInstallsWithForeignKeys()
    {
        $this->connection->exec('DROP TABLE setting');
        $this->connection->exec('PRAGMA foreign_keys = ON');
        $this->connection->exec(file_get_contents(OMEKA_PATH . '/application/data/install/schema.sqlite.sql'));
        $this->assertContains('resource', $this->connection->getSchemaManager()->listTableNames());
        $this->assertNotEmpty($this->connection->fetchAllAssociative('PRAGMA foreign_key_list(media)'));
        $this->assertSame([], $this->connection->fetchAllAssociative('PRAGMA foreign_key_check'));
    }

    public function testTriggerBodiesAreNotSplit()
    {
        $this->connection->exec("CREATE TRIGGER change_setting AFTER INSERT ON setting BEGIN UPDATE setting SET value='changed; value' WHERE id=NEW.id; END;");
        $this->connection->exec("INSERT INTO setting VALUES ('foo', 'bar')");
        $this->assertSame('changed; value', $this->connection->fetchOne('SELECT value FROM setting'));
    }

    public function testAutoIncrementDoesNotReuseDeletedIdsAndTruncateResetsIt()
    {
        $this->connection->exec('CREATE TABLE example (id INT UNSIGNED NOT NULL AUTO_INCREMENT, value TEXT, PRIMARY KEY(id)) ENGINE=InnoDB');
        $this->connection->exec("INSERT INTO example (value) VALUES ('first')");
        $this->connection->exec('DELETE FROM example');
        $this->connection->exec("INSERT INTO example (value) VALUES ('second')");
        $this->assertSame(2, (int) $this->connection->fetchOne('SELECT id FROM example'));
        $this->connection->exec('TRUNCATE TABLE example');
        $this->connection->exec("INSERT INTO example (value) VALUES ('third')");
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT id FROM example'));
    }

    public function testNativeTableOptionsArePreserved()
    {
        $this->connection->exec('CREATE TABLE native (id INTEGER PRIMARY KEY) WITHOUT ROWID');
        $this->assertStringContainsString('WITHOUT ROWID', $this->connection->fetchOne("SELECT sql FROM sqlite_master WHERE name='native'"));
    }

    public function testTableCommentDoesNotChangeBodyBoundaries()
    {
        $this->connection->exec("CREATE TABLE example (id INT PRIMARY KEY, value TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Example (a,b)'");
        $this->assertCount(2, $this->connection->fetchAllAssociative('PRAGMA table_info(example)'));
    }

    public function testMysqlFunctionsPreserveNullsAndLiteralText()
    {
        $this->assertNull($this->connection->fetchOne('SELECT UNIX_TIMESTAMP(NULL)'));
        $this->assertGreaterThan(0, (int) $this->connection->fetchOne('SELECT UNIX_TIMESTAMP()'));
        $this->assertNull($this->connection->fetchOne("SELECT CONCAT('foo', NULL)"));
        $this->assertSame('foo|bar', $this->connection->fetchOne("SELECT CONCAT_WS('|', 'foo', NULL, 'bar')"));
        $this->assertSame('NOW(); CURDATE()', $this->connection->fetchOne("SELECT 'NOW(); CURDATE()'"));
    }

    public function testUniquePrefixIndexesEnforcePrefixUniqueness()
    {
        $this->connection->exec('CREATE TABLE example (value TEXT, UNIQUE KEY prefix (value(3)))');
        $this->connection->exec("INSERT INTO example VALUES ('abcd')");
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->connection->exec("INSERT INTO example VALUES ('abce')");
    }
}
