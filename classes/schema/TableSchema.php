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

use \Tools;

if (!defined('_TB_VERSION_')) {
    exit;
}

/**
 * Class TableSchema
 *
 * This class holds information specific database table
 *
 * @version 1.1.0 Initial version.
 */
class TableSchema
{
    /**
     * @var string table name
     */
    protected $name;

    /**
     * @var string database engine
     */
    protected $engine;

    /**
     * @var DatabaseCharset default character set
     */
    protected $charset;

    /**
     * @var ColumnSchema[] table columns. Column order is significant
     */
    protected $columns;

    /**
     * @var TableKey[] table primary, unique, foreign, or normal keys
     */
    protected $keys;

    /**
     * TableSchema constructor.
     *
     * @param $name string name of the database table
     *
     * @version 1.1.0 Initial version.
     */
    public function __construct($name)
    {
        $this->name = $name;
        $this->columns = [];
        $this->keys = [];
        $this->charset = new DatabaseCharset();
    }

    /**
     * Returns name of database table, including _DB_PREFIX_
     *
     * @return string
     *
     * @version 1.1.0 Initial version.
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Returns name of database table without _DB_PREFIX_
     *
     * @return string
     *
     * @version 1.1.0 Initial version.
     */
    public function getNameWithoutPrefix()
    {
        return Tools::strReplaceFirst(_DB_PREFIX_, '', $this->name);
    }

    /**
     * Returns database engine used by table, such as InnoDB or MyISAM
     *
     * @return string
     *
     * @version 1.1.0 Initial version.
     */
    public function getEngine()
    {
        return $this->engine;
    }

    /**
     * Set database engine, such as InnoDB or MyISAM
     *
     * @param $engine string
     *
     * @version 1.1.0 Initial version.
     */
    public function setEngine($engine)
    {
        $this->engine = $engine;
    }

    /**
     * Returns default character set used by table
     *
     * @return DatabaseCharset
     *
     * @version 1.1.0 Initial version.
     */
    public function getCharset()
    {
        return $this->charset;
    }

    /**
     * Sets default character set and collation
     *
     * @param DatabaseCharset $charset
     *
     * @version 1.1.0 Initial version.
     */
    public function setCharset(DatabaseCharset $charset)
    {
        $this->charset = $charset;
    }

    /**
     * Method to register database column
     *
     * @param ColumnSchema $column database column definition
     *
     * @version 1.1.0 Initial version.
     */
    public function addColumn(ColumnSchema $column)
    {
        if (! $this->hasColumn($column->getName())) {
            $this->columns[] = $column;
        }
    }

    /**
     * Returns all table columns
     *
     * @return ColumnSchema[]
     *
     * @version 1.1.0 Initial version.
     */
    public function getColumns()
    {
        return $this->columns;
    }

    /**
     * Sets table columns
     *
     * @param $columns ColumnSchema[]
     *
     * @version 1.1.0 Initial version.
     */
    public function setColumns($columns)
    {
        $this->columns = $columns;
    }

    /**
     * Method to reorder table columns
     *
     * @param $columnNames string[] New order of columns. This list does not
     *                              need to be exhaustive - all registered
     *                              columns that are not part of this parameter
     *                              will be last.
     */
    public function reorderColumns($columnNames)
    {
        $missing = array_values(array_diff($this->getColumnNames(), $columnNames));
        $order = array_merge($columnNames, $missing);

        usort($this->columns, function(ColumnSchema $a, ColumnSchema $b) use ($order) {
            $pos1 = (int)array_search($a->getName(), $order);
            $pos2 = (int)array_search($b->getName(), $order);
            return $pos1 - $pos2;
        });
    }

    /**
     * Returns column names
     *
     * @return ColumnSchema[]
     *
     * @version 1.1.0 Initial version.
     */
    public function getColumnNames()
    {
        return array_map(function(ColumnSchema $column) {
            return $column->getName();
        }, $this->columns);
    }

    /**
     * Returns true, if table contains column with given name
     *
     * @param $columnName string
     *
     * @return bool
     *
     * @version 1.1.0 Initial version.
     */
    public function hasColumn($columnName)
    {
        return !!$this->getColumn($columnName);
    }

    /**
     * Returns column definition
     *
     * @param $columnName string name of column
     *
     * @return ColumnSchema | null
     *
     * @version 1.1.0 Initial version.
     */
    public function getColumn($columnName)
    {
        foreach ($this->columns as $column) {
            if ($column->getName() === $columnName) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Returns key definition
     *
     * @param $keyName string name of key
     *
     * @return TableKey | null
     *
     * @version 1.1.0 Initial version.
     */
    public function getKey($keyName)
    {
        if ($this->hasKey($keyName)) {
            return $this->keys[$keyName];
        }

        return null;
    }

    /**
     * Returns true, if table contains key with given name
     *
     * @param $keyName
     *
     * @return bool
     *
     * @version 1.1.0 Initial version.
     */
    public function hasKey($keyName)
    {
        return isset($this->keys[$keyName]);
    }

    /**
     * Registers database key
     *
     * @param TableKey $key
     *
     * @version 1.1.0 Initial version.
     */
    public function addKey(TableKey $key)
    {
        $this->keys[$key->getName()] = $key;
    }

    /**
     * Return all table keys
     *
     * @return TableKey[]
     *
     * @version 1.1.0 Initial version.
     */
    public function getKeys()
    {
        return $this->keys;
    }

    /**
     * Returns DDL statement to create this database table
     *
     * @return string
     *
     * @version 1.1.0 Initial version.
     */
    public function getDDLStatement()
    {
        $charset = $this->getCharset();
        $ddl = 'CREATE TABLE `' . $this->getName() . "` (\n";
        $lines = [];
        foreach ($this->getColumns() as $column) {
            $lines[] = '  ' . $column->getDDLStatement($this);
        }
        foreach ($this->getKeys() as $key) {
            $lines[] = '  ' . $key->getDDLStatement();
        }
        $ddl .= implode(",\n", $lines);
        $ddl .= "\n)";
        $ddl .= " ENGINE=" . $this->getEngine();
        $ddl .= " DEFAULT CHARSET=" . $charset->getCharset();
        if (! $charset->isDefaultCollate()) {
            $ddl .= " COLLATE=" . $charset->getCollate();
        }

        return $ddl;
    }
}
