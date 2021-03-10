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
 * Class DatabaseSchema
 *
 * This class represents database schema
 *
 * @version 1.1.0 Initial version.
 */
class DatabaseSchema
{
    /**
     * @var TableSchema[]
     */
    protected $tables = [];

    /**
     * Register new table
     *
     * @param TableSchema $table
     *
     * @version 1.1.0 Initial version.
     */
    public function addTable(TableSchema $table)
    {
        $this->tables[$table->getName()] = $table;
    }

    /**
     * Returns all registered tables
     *
     * @return TableSchema[]
     *
     * @version 1.1.0 Initial version.
     */
    public function getTables()
    {
        ksort($this->tables);

        return $this->tables;
    }

    /**
     * Returns true, if table with $tableName exists
     *
     * @param string $tableName name of table
     *
     * @return bool
     *
     * @version 1.1.0 Initial version.
     */
    public function hasTable($tableName)
    {
        return isset($this->tables[$tableName]);
    }

    /**
     * Returns table with name $tableName
     *
     * @param string $tableName
     *
     * @return TableSchema | null
     *
     * @version 1.1.0 Initial version.
     */
    public function getTable($tableName)
    {
        if ($this->hasTable($tableName)) {
            return $this->tables[$tableName];
        }

        return null;
    }

    /**
     * Returns DDL statements to create database schema
     *
     * @return string
     *
     * @version 1.1.0 Initial version.
     */
    public function getDDLStatement()
    {
        $stmt = '';
        foreach ($this->getTables() as $table) {
            $stmt .= $table->getDDLStatement() . ";\n\n";
        }

        return $stmt;
    }
}
