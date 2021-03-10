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
 * Class ExtraColumn
 *
 * Difference in column AUTO_INCREMENT settings
 *
 * @version 1.1.0 Initial version.
 */
class DifferentAutoIncrement implements SchemaDifference
{
    private $table;
    private $column;
    private $currentColumn;

    /**
     * DifferentAutoIncrement constructor.
     *
     * @param TableSchema $table
     * @param ColumnSchema $column
     * @param ColumnSchema $currentColumn
     *
     * @version 1.1.0 Initial version.
     */
    public function __construct(TableSchema $table, ColumnSchema $column, ColumnSchema $currentColumn)
    {
        $this->table = $table;
        $this->column = $column;
        $this->currentColumn = $currentColumn;
    }

    /**
     * Return description of the difference.
     *
     * @return string
     *
     * @version 1.1.0 Initial version.
     */
    public function describe()
    {
        $table = $this->table->getName();
        $col = $this->column->getName();

        return $this->column->isAutoIncrement()
            ? sprintf(Translate::getModuleTranslation('coreupdater', 'Column [1]%1$s.%2$s[/1] should be marked as [2]AUTO_INCREMENT[/2]', 'coreupdater'), $table, $col)
            : sprintf(Translate::getModuleTranslation('coreupdater', 'Column [1]%1$s.%2$s[1] should [2]not[/2] be marked as [3]AUTO_INCREMENT[/3]', 'coreupdater'), $table, $col);
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
     * Applies fix to correct this database difference
     *
     * @param Db $connection
     * @return bool
     * @throws \PrestaShopDatabaseException
     * @throws \PrestaShopException
     */
    function applyFix(Db $connection)
    {
        $builder = new InformationSchemaBuilder($connection);
        $column = $builder->getCurrentColumn($this->table->getName(), $this->column->getName());
        $column->setAutoIncrement($this->column->isAutoIncrement());
        $stmt = 'ALTER TABLE `' . bqSQL($this->table->getName()) . '` MODIFY COLUMN ' .$column->getDDLStatement($this->table);
        return $connection->execute($stmt);
    }

}
