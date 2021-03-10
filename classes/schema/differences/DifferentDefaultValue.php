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
use \ObjectModel;

if (!defined('_TB_VERSION_')) {
    exit;
}

/**
 * Class ExtraColumn
 *
 * Difference in column's default value
 *
 * @version 1.1.0 Initial version.
 */
class DifferentDefaultValue implements SchemaDifference
{
    private $table;
    private $column;
    private $currentColumn;

    /**
     * DifferentDefaultValue constructor.
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
        $value = $this->column->getDefaultValue();
        $currentValue = $this->currentColumn->getDefaultValue();

        return is_null($value)
            ? sprintf(Translate::getModuleTranslation('coreupdater', 'Column [1]%1$s.%2$s[/1] should NOT have default value [2]\'%3$s\'[/2]', 'coreupdater'), $table, $col, $currentValue)
            : sprintf(Translate::getModuleTranslation('coreupdater', 'Column [1]%1$s.%2$s[/1] should have DEFAULT value [2]\'%3$s\'[2] instead of [3]\'%4$s\'[/3]', 'coreupdater'), $table, $col, $value, $currentValue);
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
        $stmt = 'ALTER TABLE `' . bqSQL($this->table->getName()) . '` ALTER COLUMN `' .bqSQL($this->column->getName()) . '` ';
        if ($this->column->hasDefaultValue()) {
            $default = $this->column->getDefaultValue();
            if (is_null($default)) {
                $default = 'NULL';
            } else if ($default === ObjectModel::DEFAULT_CURRENT_TIMESTAMP) {
                // current timestamp cant be set using alter table
                $builder = new InformationSchemaBuilder($connection);
                $column = $builder->getCurrentColumn($this->table->getName(), $this->column->getName());
                $column->setDefaultValue($default);
                $stmt = 'ALTER TABLE `' . bqSQL($this->table->getName()) . '` MODIFY COLUMN ' .$column->getDDLStatement($this->table);
                return $connection->execute($stmt);
            } else {
                $default = "'".pSQL($default)."'";
            }
            $stmt .= "SET DEFAULT " . $default;
        } else {
            $stmt .= "DROP DEFAULT";
        }
        return $connection->execute($stmt);
    }
}
