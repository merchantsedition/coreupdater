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

use \ObjectModel;

if (!defined('_TB_VERSION_')) {
    exit;
}

/**
 * Class ColumnSchema
 *
 * This class holds information about specific database column
 *
 * @version 1.1.0 Initial version.
 */
class ColumnSchema
{
    /**
     * @var string column name
     */
    protected $columnName;

    /**
     * @var string full data type, such as 'int(11) unsigned'
     */
    protected $dataType;

    /**
     * @var boolean contains true if this column can hold NULL value.
     *
     * If this property is not a boolean value, then nullable property
     * is determined using other heuristics
     */
    protected $nullable = null;

    /**
     * @var boolean auto increment flag
     */
    protected $autoIncrement = false;

    /**
     * @var string column default value.
     *
     * PHP `null` value represents no default value exists, while static::DEFAULT_NULL
     * means that column has default value NULL (sql null)
     */
    protected $defaultValue = null;

    /**
     * @var DatabaseCharset default character set, such as utf8mb4
     */
    protected $charset;


    /**
     * TableSchema constructor.
     *
     * @param string $columnName name of the database column
     *
     * @version 1.1.0 Initial version.
     */
    public function __construct($columnName)
    {
        $this->columnName = $columnName;
        $this->charset = new DatabaseCharset();
    }

    /**
     * Returns column name
     *
     * @return string
     *
     * @version 1.1.0 Initial version.
     */
    public function getName()
    {
        return $this->columnName;
    }

    /**
     * Set full database type, for example 'decimal(20,6)'
     *
     * @param string $dataType database type
     *
     * @version 1.1.0 Initial version.
     */
    public function setDataType($dataType)
    {
        $this->dataType = $dataType;
        if ($this->defaultValue === ObjectModel::DEFAULT_CURRENT_TIMESTAMP) {
            $this->defaultValue = null;
        }
    }

    /**
     * Returns full database type
     *
     * @return string
     *
     * @version 1.1.0 Initial version.
     */
    public function getDataType()
    {
        return $this->dataType;
    }

    /**
     * Returns true, if this column can hold database NULL value.
     *
     * @return bool
     *
     * @version 1.1.0 Initial version.
     */
    public function isNullable()
    {
        // explicitly set nullable
        if (! is_null($this->nullable)) {
            return $this->nullable;
        }
        // auto increments should not be nullable
        if ($this->autoIncrement) {
            return false;
        }
        // if field has default value, it's usually NOT NULL
        if (! is_null($this->defaultValue)) {
            return ($this->defaultValue === ObjectModel::DEFAULT_NULL);
        }

        return true;
    }

    /**
     * Explicitly sets ability to hold NULL value
     *
     * @param bool $nullable
     *
     * @version 1.1.0 Initial version.
     */
    public function setNullable($nullable)
    {
        $this->nullable = $nullable;
    }

    /**
     * Returns true, if this column is AUTO_INCREMENT
     *
     * @return bool
     *
     * @version 1.1.0 Initial version.
     */
    public function isAutoIncrement()
    {
        return $this->autoIncrement;
    }

    /**
     * Sets AUTO_INCREMENT flag
     *
     * @param bool $autoIncrement
     *
     * @version 1.1.0 Initial version.
     */
    public function setAutoIncrement($autoIncrement)
    {
        $this->autoIncrement = $autoIncrement;
    }

    /**
     * Returns default column value. If default value is sql NULL, then return
     * value is php null.
     *
     * Do not use this function to test whether default value exists, because
     * this test would fail for any field with DEFAULT NULL value.
     *
     * @return string
     *
     * @version 1.1.0 Initial version.
     */
    public function getDefaultValue()
    {
        return $this->defaultValue === ObjectModel::DEFAULT_NULL ? null : $this->defaultValue;
    }

    /**
     * returns true, if table has default value (including NULL)
     *
     * @return bool
     *
     * @version 1.1.0 Initial version.
     */
    public function hasDefaultValue()
    {
        return !is_null($this->defaultValue);
    }

    /**
     * Returns true, if this column has DEFAULT NULL
     *
     * @return bool
     */
    public function hasDefaultValueNull()
    {
        return $this->defaultValue === ObjectModel::DEFAULT_NULL;
    }

    /**
     * Sets column default value. PHP null means no default value exists. If column should have
     * DEFAULT NULL, then pass ObjectModel::DEFAULT_NULL
     *
     * @param string $defaultValue
     *
     * @version 1.1.0 Initial version.
     */
    public function setDefaultValue($defaultValue)
    {
        if (is_null($defaultValue)) {
           $this->defaultValue = null;
        } else if (is_string($defaultValue)) {
            $this->defaultValue = $defaultValue;
        } else {
            $this->defaultValue = "$defaultValue";
        }
    }

    /**
     * Sets character set and collation
     *
     * @param DatabaseCharset $charset character set, ie. utf8mb4
     *
     * @version 1.1.0 Initial version.
     */
    public function setCharset(DatabaseCharset $charset)
    {
        $this->charset = $charset;
    }

    /**
     * Returns character set for this column, or null if none is set
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
     * Returns DDL statement to create this column
     *
     * @param TableSchema $table
     *
     * @return string
     *
     * @version 1.1.0 Initial version.
     */
    public function getDDLStatement(TableSchema $table)
    {
        $col =  '`' . $this->getName() . '` ' . $this->getDataType();
        $charset = $this->getCharset()->getCharset();
        $collate = $this->getCharset()->getCollate();
        if ($charset && $collate && $this->getCharset()->isDefaultCollate()) {
            if ($table->getCharset()->getCharset() !== $charset) {
                $col .= ' CHARACTER SET ' . $charset;
            }
        } else if ($collate) {
            $col .= ' COLLATE ' . $collate;
        }
        if (! $this->isNullable()) {
            $col .= ' NOT NULL';
        }
        if ($this->hasDefaultValue()) {
            $default = $this->getDefaultValue();
            if (is_null($default)) {
                if (! in_array($this->getDataType(), ['text', 'mediumtext', 'longtext'])) {
                    $col .= ' DEFAULT NULL';
                }
            } elseif ($default === ObjectModel::DEFAULT_CURRENT_TIMESTAMP) {
                $col .= ' DEFAULT CURRENT_TIMESTAMP';
            } else {
                $col .= ' DEFAULT \'' . $default . '\'';
            }
        }
        if ($this->isAutoIncrement()) {
            $col .= ' AUTO_INCREMENT';
        }

        return $col;
    }

    /**
     * Returns base database type, ie varchar instead of varchar(32)
     */
    public function getBaseType()
    {
        $type = $this->getDataType();
        $type = strtolower($type);
        $type = str_replace('unsigned', '', $type);
        $type = str_replace('signed', '', $type);
        if (strpos($type, '(')) {
            $parts = explode('(', $type);
            $type = $parts[0];
        }
        return $type;
    }

    /**
     * Returns extra information from data type, ie 32 from varchar(32), or 'x', 'y' from enum('x', 'y')
     */
    public function getExtraInformation()
    {
        $type = $this->getDataType();
        $type = strtolower($type);
        $match = null;
        preg_match('/(?<=\()(.+)(?=\))/is', $type, $match);
        if ($match) {
            return $match[1];
        }
        return null;
    }
}
