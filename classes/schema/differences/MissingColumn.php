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

use \Translate;
use \Db;

if (!defined('_TB_VERSION_')) {
    exit;
}

/**
 * Class MissingColumn
 *
 * Represents missing database column
 *
 * @version 1.1.0 Initial version.
 */
class MissingColumn implements SchemaDifference
{
    private $table;
    private $column;

    /**
     * MissingColumn constructor.
     *
     * @param TableSchema $table
     * @param ColumnSchema $column
     *
     * @version 1.1.0 Initial version.
     */
    public function __construct(TableSchema $table, ColumnSchema $column)
    {
        $this->table = $table;
        $this->column = $column;
    }

    /**
     * Return description of the difference.
     *
     * @return string
     *
     * @version 1.1.0 Initial version.
     */
    function describe()
    {
        return sprintf(
            Translate::getModuleTranslation('coreupdater', 'Column [1]%1$s[/1] is missing in table [2]%2$s[/2]', 'coreupdater'),
            $this->column->getName(),
            $this->table->getName()
        );
    }

    /**
     * Returns unique identification of this database difference.
     *
     * @return string
     */
    function getUniqueId()
    {
        return get_class($this) . ':' . $this->table->getName() . '.' . $this->column->getName();
    }

    /**
     * This operation is NOT destructive
     *
     * @return bool
     */
    function isDestructive()
    {
        return false;
    }

    /**
     * Returns severity of this difference
     *
     * @return int severity
     */
    function getSeverity()
    {
        return self::SEVERITY_CRITICAL;
    }

    /**
     * Applies fix to correct this database difference - adds column to table
     *
     * @param Db $connection
     * @return bool
     * @throws \PrestaShopException
     */
    function applyFix(Db $connection)
    {
        $stmt = 'ALTER TABLE `' . bqSQL($this->table->getName()) . '` ADD COLUMN ' . $this->column->getDDLStatement($this->table);
        $prev = null;
        foreach ($this->table->getColumnNames() as $columnName) {
            if ($columnName === $this->column->getName()) {
                break;
            }
            $prev = $columnName;
        }
        if (! $prev) {
            $stmt .= " FIRST";
        } else {
            $stmt .= " AFTER `".pSQL($prev)."`";
        }

        return $connection->execute($stmt);
    }
}

