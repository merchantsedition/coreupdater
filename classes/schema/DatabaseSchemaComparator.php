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

if (!defined('_TB_VERSION_')) {
    exit;
}

/**
 * Class DatabaseSchemaComparator
 *
 * This class can compare two DatabaseSchema objects and return array of
 * SchemaDifference[] describing differences between these two databases.
 *
 * Result can be used to report on changes, and also to apply fixes and migrate
 * database to expected state.
 *
 * @version 1.1.0 Initial version.
 */
class DatabaseSchemaComparator
{
    /**
     * @var string[] array of table names that should be ignored during database comparison
     */
    protected $ignoreTables = [];

    /**
     * DatabaseSchemaComparator constructor.
     *
     * Currently supported parameters:
     *
     *  - ignoreTables: string[] list of database tables to be ignored during
     *    comparison.
     *
     * @param array $parameters comparison parameters. Use this to adjust comparison
     *
     * @version 1.1.0 Initial version.
     */
    public function __construct($parameters = [])
    {
        if (isset($parameters['ignoreTables'])) {
            $this->ignoreTables = array_map(function($table) {
                return _DB_PREFIX_ . $table;
            }, $parameters['ignoreTables']);
        }
    }

    /**
     * Returns differences between two database schemas
     *
     * @param DatabaseSchema $currentSchema
     * @param DatabaseSchema $targetSchema
     *
     * @return SchemaDifference[]
     *
     * @version 1.1.0 Initial version.
     */
    public function getDifferences(DatabaseSchema $currentSchema, DatabaseSchema $targetSchema)
    {
        $differences = [];
        $tables = $this->getTables($targetSchema);

        foreach ($tables as $table) {
            if (!$currentSchema->hasTable($table->getName())) {
                $differences[] = new MissingTable($table);
            } else {
                $currentTable = $currentSchema->getTable($table->getName());
                $differences = array_merge($differences, $this->getTableDifferences($currentTable, $table));
            }
        }

        foreach ($this->getTables($currentSchema) as $table) {
            if (! $targetSchema->hasTable($table->getName())) {
                $differences[] = new ExtraTable($table);
            }
        }

        return $differences;
    }

    /**
     * Compares two database tables and returns differences between them
     *
     * @param TableSchema $currentTable current table
     * @param TableSchema $targetTable  target table
     *
     * @return SchemaDifference[]
     *
     * @version 1.1.0 Initial version.
     */
    public function getTableDifferences(TableSchema $currentTable, TableSchema $targetTable)
    {
        $differences = [];
        $haveSameColumns = true;

        // 1) detect extra key
        foreach ($this->getMissingKeys($targetTable, $currentTable) as $key) {
            $differences[] = new ExtraKey($currentTable, $key);
        }

        // 2) detect missing columns
        foreach ($this->getMissingColumns($currentTable, $targetTable) as $column) {
            $differences[] = new MissingColumn($targetTable, $column);
            $haveSameColumns = false;
        }

        // 3) find column differences
        foreach ($targetTable->getColumns() as $targetColumn) {
            $currentColumn = $currentTable->getColumn($targetColumn->getName());
            if ($currentColumn) {
                $differences = array_merge($differences, $this->getColumnDifferences($targetTable, $currentColumn, $targetColumn));
            }
        }

        // 4) detect extra columns
        foreach ($this->getMissingColumns($targetTable, $currentTable) as $column) {
            $differences[] = new ExtraColumn($currentTable, $column);
            $haveSameColumns = false;
        }

        // test columns order only when both tables contains the same columns
        if ($haveSameColumns) {
            if ($targetTable->getColumnNames() !== $currentTable->getColumnNames()) {
                $differences[] = new DifferentColumnsOrder($targetTable, $currentTable);
            }
        }

        // 5) detect missing key
        foreach ($this->getMissingKeys($currentTable, $targetTable) as $key) {
            $differences[] = new MissingKey($targetTable, $key);
        }

        // 6) find key differences
        foreach ($targetTable->getKeys() as $targetKey) {
            $currentKey = $currentTable->getKey($targetKey->getName());
            if ($currentKey) {
                if (
                    ($currentKey->getType() !== $targetKey->getType()) ||
                    ($currentKey->getColumns() !== $targetKey->getColumns()) ||
                    ($currentKey->getSubParts() !== $targetKey->getSubParts())
                ){
                    $differences[] = new DifferentKey($targetTable, $targetKey, $currentKey);
                }
            }
        }

        // 7) detect charsets
        if (! $currentTable->getCharset()->equals($targetTable->getCharset())) {
            $differences[] = new DifferentTableCharset($targetTable, $currentTable);
        }

        // 8) detect engine
        if ($currentTable->getEngine() !== $targetTable->getEngine()) {
            $differences[] = new DifferentEngine($targetTable, $currentTable->getEngine());
        }

        return $differences;
    }

    /**
     * Compares two database columns and return list of differences
     *
     * @param TableSchema $table
     * @param ColumnSchema $current
     * @param ColumnSchema $target
     *
     * @return SchemaDifference[]
     *
     * @version 1.1.0 Initial version.
     */
    public function getColumnDifferences(TableSchema $table, ColumnSchema $current, ColumnSchema $target)
    {
        $differences = [];

        if ($current->getDataType() !== $target->getDataType()) {
            $differences[] = new DifferentDataType($table, $target, $current);
        }

        if ($current->isNullable() !== $target->isNullable()) {
            $differences[] = new DifferentNullable($table, $target, $current);
        }

        if ($current->isAutoIncrement() !== $target->isAutoIncrement()) {
            $differences[] = new DifferentAutoIncrement($table, $target, $current);
        }

        if (($current->hasDefaultValue() !== $target->hasDefaultValue()) || ($current->getDefaultValue() !== $target->getDefaultValue())) {
            $differences[] = new DifferentDefaultValue($table, $target, $current);
        }

        if (! $current->getCharset()->equals($target->getCharset())) {
            $differences[] = new DifferentColumnCharset($table, $target, $current);
        }

        return $differences;
    }

    /**
     * Returns list of keys that exists in $currentTable, but a
     *
     * @param TableSchema $currentTable
     * @param TableSchema $targetTable
     *
     * @return TableKey[]
     *
     * @version 1.1.0 Initial version.
     */
    public function getMissingKeys(TableSchema $currentTable, TableSchema $targetTable)
    {
        $missingKeys = [];
        foreach ($targetTable->getKeys() as $key) {
            if (! $currentTable->hasKey($key->getName())) {
                $missingKeys[] = $key;
            }
        }

        return $missingKeys;
    }

    /**
     * Returns sorted list of database tables without tables listed in $ignoreTables property
     *
     * @param DatabaseSchema $schema database schema
     *
     * @return TableSchema[] list of tables
     *
     * @version 1.1.0 Initial version.
     */
    protected function getTables(DatabaseSchema $schema)
    {
        $tables = array_filter($schema->getTables(), function(TableSchema $table) {
            if ($this->ignoreTables) {
                return ! in_array($table->getName(), $this->ignoreTables);
            }

            return true;
        });
        usort($tables, function(TableSchema $a, TableSchema $b) {
            return strcmp($a->getName(), $b->getName());
        });

        return $tables;
    }

    /**
     * Returns list of columns that are present int $currentTable but are missing in $targetTable
     *
     * @param TableSchema $currentTable
     * @param TableSchema $targetTable
     *
     * @return ColumnSchema[]
     *
     * @version 1.1.0 Initial version.
     */
    protected function getMissingColumns(TableSchema $currentTable, TableSchema $targetTable)
    {
        $missingColumns = [];
        foreach ($targetTable->getColumns() as $column) {
            if (! $currentTable->hasColumn($column->getName())) {
                $missingColumns[] = $column;
            }
        }

        return $missingColumns;
    }
}
