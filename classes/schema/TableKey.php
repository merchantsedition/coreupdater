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
use \Translate;

if (!defined('_TB_VERSION_')) {
    exit;
}

/**
 * Class TableKey
 *
 * This class holds information about specific database key/constraint/index
 *
 * @version 1.1.0 Initial version.
 */
class TableKey
{

    /**
     * @var int Key type, see type constants above
     */
    protected $type;

    /**
     * @var string key name. Primary key has always name 'PRIMARY'
     */
    protected $name;

    /**
     * @var string[] columns this key consists of, order is significant
     */
    protected $columns;

    /**
     * @var int[] $subParts column sub-parts
     *
     * Use this to include only a prefix / portion of column to index, for example
     *
     * KEY `name` (`name`(10))
     *
     * will create index on first 10 characters of name column
     */
    protected $subParts;


    /**
     * TableKey constructor.
     *
     * @param int $type type of database key, see constants above
     * @param string $name name of this key, ignored for primary key
     *
     * @version 1.1.0 Initial version.
     */
    public function __construct($type, $name)
    {
        $this->type = $type;
        $this->name = $type === ObjectModel::PRIMARY_KEY ? 'PRIMARY' : $name;
        $this->columns = [];
        $this->subParts = [];
    }

    /**
     * Returns name of the key
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
     * Adds column to the key
     *
     * @param string $columnName column name
     * @param int $subPart If not null, then only a portion (prefix) of column
     *                     will be used for index.
     *
     * @version 1.1.0 Initial version.
     */
    public function addColumn($columnName, $subPart = null)
    {
        $this->columns[] = $columnName;
        $this->subParts[] = $subPart ? (int)$subPart : null;
    }


    /**
     * Returns type of the key
     *
     * @return int
     *
     * @version 1.1.0 Initial version.
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Returns column names used by this key
     *
     * @return string[]
     *
     * @version 1.1.0 Initial version.
     */
    public function getColumns()
    {
        return $this->columns;
    }

    /**
     * Returns column subparts (prefixes). If column does not use prefix, then
     * the array will contain null for given position
     *
     * @return int[]
     *
     * @version 1.1.0 Initial version.
     */
    public function getSubParts()
    {
        return $this->subParts;
    }

    /**
     * Helper method to describe key
     *
     * @return string
     *
     * @version 1.1.0 Initial version.
     */
    public function describeKey()
    {
        switch ($this->type) {
            case ObjectModel::PRIMARY_KEY:
                return Translate::getModuleTranslation('coreupdater', 'primary key', 'coreupdater');
            case ObjectModel::UNIQUE_KEY:
                return sprintf(Translate::getModuleTranslation('coreupdater', 'unique key `%1$s`', 'coreupdater'), $this->name);
            case ObjectModel::FOREIGN_KEY;
                return sprintf(Translate::getModuleTranslation('coreupdater', 'foreign key `%1$s`', 'coreupdater'), $this->name);
            default:
                return sprintf(Translate::getModuleTranslation('coreupdater', 'key `%1$s`', 'coreupdater'), $this->name);
        }
    }

    /**
     * Returns DDL statement to create this database key
     *
     * @return string
     *
     * @version 1.1.0 Initial version.
     */
    public function getDDLStatement()
    {
        $stmt = '';
        switch ($this->getType()) {
            case ObjectModel::PRIMARY_KEY:
                $stmt .= 'PRIMARY KEY';
                break;
            case ObjectModel::UNIQUE_KEY:
                $stmt .= 'UNIQUE KEY `' . $this->getName() .'`';
                break;
            case ObjectModel::KEY:
                $stmt .= 'KEY `' . $this->getName() .'`';
                break;
        }
        $columns = $this->getColumns();
        $subParts = $this->getSubParts();
        $colStmts = [];
        for ($i = 0; $i<count($columns); $i++) {
            $colStmt = '`' . $columns[$i] . '`';
            if ($subParts[$i]) {
                $colStmt .= '(' . $subParts[$i] . ')';
            }
            $colStmts[] = $colStmt;
        }
        return $stmt . ' (' . implode(',', $colStmts) . ')';
    }
}
