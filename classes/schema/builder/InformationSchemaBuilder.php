<?php
/**
 * Copyright (C) 2021 Merchant's Edition GbR
 * Copyright (C) 2019 thirty bees
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to contact@merchantsedition.com so we can send you a copy immediately.
 *
 * @author    Merchant's Edition <contact@merchantsedition.com>
 * @author    thirty bees <modules@thirtybees.com>
 * @copyright 2021 Merchant's Edition GbR
 * @copyright 2019 thirty bees
 * @license   Academic Free License (AFL 3.0)
 */

namespace CoreUpdater;

use \Db;
use \ObjectModel;
use \PrestaShopException;
use \PrestaShopDatabaseException;

if (!defined('_TB_VERSION_')) {
    exit;
}
/**
 * Class InformationSchemaBuilder
 *
 * This class is responsible for building DatabaseSchema object based on MySQL
 * information schema.
 *
 * @version 1.1.0 Initial version.
 */
class InformationSchemaBuilder
{
    /**
     * @var Db Database connection name to be used to query information schema
     */
    protected $connection;

    /**
     * @var string database name
     */
    protected $database;

    /**
     * @var DatabaseSchema
     */
    protected $schema;

    /**
     * @var string[]|null optional list of tables to retrieve
     */
    protected $tables;

    /**
     * InformationSchemaBuilder constructor.
     *
     * @param Db $connection
     * @param string $databaseName Optional name of database to load schema for.
     *                             If not provided, information about current
     *                             database will be returned.
     * @param string[] $tables     Optional table names. If present, builder will
     *                             load information for these tables only
     * @version 1.1.0 Initial version.
     */
    public function __construct($connection = null, $databaseName = null, $tables = null)
    {
        if (! $connection) {
            $this->connection = Db::getInstance();
        } else {
            $this->connection = $connection;
        }
        if (! $databaseName) {
            $this->database = 'database()';
        } else {
            $this->database = pSQL($databaseName);
        }
        $this->tables = $tables;
    }

    /**
     * Builds DatabaseSchema object for database
     *
     * @param bool $force If true, then new schema will be build, otherwise
     *                    cached version might be returned.
     *
     * @return DatabaseSchema
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     *
     * @version 1.1.0 Initial version.
     */
    public function getSchema($force = false)
    {
        if ($force || !$this->schema) {
            $this->schema = new DatabaseSchema();
            $this->loadInformationSchema();
        }

        return $this->schema;
    }

    /**
     * Returns current column
     *
     * @param string $tableName
     * @param string $columnName
     * @return ColumnSchema
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function getCurrentColumn($tableName, $columnName)
    {
        $columns = $this->connection->executeS('
            SELECT *
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ' . $this->database . '
              AND TABLE_NAME = \'' . pSql($tableName) . '\'
              AND COLUMN_NAME = \'' . pSql($columnName) . '\''
        );
        if ($columns && count($columns) === 1) {
            return $this->toColumn($columns[0]);
        }
        throw new PrestaShopException(sprintf("Column `%s$1`.`%s$2` not found in database", $tableName, $columnName));
    }

    /**
     * Builds DatabaseSchema object
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function loadInformationSchema()
    {
        $connection = $this->connection;
        $this->loadTables($connection);
        $this->loadColumns($connection);
        $this->loadKeys($connection);
    }

    /**
     * Populates $this->schema with database tables
     *
     * @param Db $connection database connection
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     *
     * @version 1.1.0 Initial version.
     */
    protected function loadTables($connection)
    {
        $tables = $connection->executeS('
            SELECT t.TABLE_NAME, t.ENGINE, c.CHARACTER_SET_NAME, t.TABLE_COLLATION
            FROM information_schema.TABLES t
            LEFT JOIN information_schema.COLLATION_CHARACTER_SET_APPLICABILITY c ON (c.COLLATION_NAME = t.TABLE_COLLATION)
            WHERE t.TABLE_SCHEMA = ' . $this->database .
            $this->addTablesRestriction('t')
        );
        foreach ($tables as $row) {
            $table = new TableSchema($row['TABLE_NAME']);
            $table->setEngine($row['ENGINE']);
            $table->setCharset(new DatabaseCharset($row['CHARACTER_SET_NAME'], $row['TABLE_COLLATION']));
            $this->schema->addTable($table);
        }
    }

    /**
     * Populates $this->schema with table columns
     *
     * @param Db $connection
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     *
     * @version 1.1.0 Initial version.
     */
    protected function loadColumns($connection)
    {
        $columns = $connection->executeS('
            SELECT *
            FROM information_schema.COLUMNS c
            WHERE TABLE_SCHEMA = ' . $this->database .
            $this->addTablesRestriction('c')
        );
        foreach ($columns as $row) {
            $tableName = $row['TABLE_NAME'];
            $column = $this->toColumn($row);
            $this->schema->getTable($tableName)->addColumn($column);
        }
    }

    /**
     * Populates $this->schema with keys/indexes
     *
     * @param Db $connection
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     *
     * @version 1.1.0 Initial version.
     */
    protected function loadKeys($connection)
    {
        $keys = $connection->executeS('
            SELECT s.TABLE_NAME, s.INDEX_NAME, t.CONSTRAINT_TYPE, s.COLUMN_NAME, s.SUB_PART
            FROM information_schema.STATISTICS s
            LEFT JOIN information_schema.TABLE_CONSTRAINTS t ON (t.TABLE_SCHEMA = s.TABLE_SCHEMA AND t.TABLE_NAME = s.TABLE_NAME and t.CONSTRAINT_NAME = s.INDEX_NAME)
            WHERE s.TABLE_SCHEMA = ' . $this->database . $this->addTablesRestriction('s') . '
            ORDER BY s.TABLE_NAME, s.INDEX_NAME, s.SEQ_IN_INDEX'
        );
        foreach ($keys as $row) {
            $tableName = $row['TABLE_NAME'];
            $keyName = $row['INDEX_NAME'];
            $table = $this->schema->getTable($tableName);
            $key = $table->getKey($keyName);
            if (!$key) {
                $key = new TableKey($this->getKeyType($row['CONSTRAINT_TYPE']), $keyName);
                $table->addKey($key);
            }
            $key->addColumn($row['COLUMN_NAME'], $row['SUB_PART']);
        }
    }

    /**
     * Transforms mysql constraint type to TableKey constant
     *
     * @param string $constraintType database constraint type
     *
     * @return int TableKey constant
     *
     * @version 1.1.0 Initial version.
     */
    protected function getKeyType($constraintType)
    {
        switch ($constraintType) {
            case 'PRIMARY KEY':
                return ObjectModel::PRIMARY_KEY;
            case 'UNIQUE':
                return ObjectModel::UNIQUE_KEY;
            case 'FOREIGN KEY':
                return ObjectModel::FOREIGN_KEY;
            default:
                return ObjectModel::KEY;
        }
    }

    /**
     * Helper method that converts resultset row into ColumnSchema
     *
     * @param array $row
     * @return ColumnSchema
     */
    protected function toColumn($row)
    {
        $columnName = $row['COLUMN_NAME'];
        $autoIncrement = strpos($row['EXTRA'], 'auto_increment') !== false;
        $isNullable = strtoupper($row['IS_NULLABLE']) === 'YES';
        $defaultValue = $this->unquote($row['COLUMN_DEFAULT']);
        if ((is_null($defaultValue) || $defaultValue === 'NULL') && $isNullable) {
            $defaultValue = ObjectModel::DEFAULT_NULL;
        }
        if (strtolower($defaultValue) === 'current_timestamp()') {
            $defaultValue = ObjectModel::DEFAULT_CURRENT_TIMESTAMP;
        }
        $column = new ColumnSchema($columnName);;
        $column->setDataType($row['COLUMN_TYPE']);
        $column->setAutoIncrement($autoIncrement);
        $column->setNullable($isNullable);
        $column->setDefaultValue($defaultValue);
        $column->setCharset(new DatabaseCharset($row['CHARACTER_SET_NAME'], $row['COLLATION_NAME']));
        return $column;
    }

    /**
     * Helper method to restrict sql query for specific tables only
     *
     * @param $alias
     * @return string
     */
    protected function addTablesRestriction($alias)
    {
        if ($this->tables) {
            return ' AND ' . $alias . ".TABLE_NAME IN ('" . implode("', '", $this->tables) . "') ";
        }
        return '';
    }

    /**
     * Helper method to remove quotes from around the text
     * @param string $value
     * @return string
     */
    protected function unquote($value)
    {
	    if ($value) {
		    return preg_replace("/^['\"](.*)['\"]$/", "\\1", $value);
	    }
	    return $value;
    }
}
