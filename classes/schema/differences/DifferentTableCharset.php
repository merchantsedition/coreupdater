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
 * Represents difference in table default character set and collation
 *
 * @version 1.1.0 Initial version.
 */
class DifferentTableCharset implements SchemaDifference
{
    private $table;
    private $currentTable;

    /**
     * DifferentTableCharset constructor.
     *
     * @param TableSchema $table
     * @param TableSchema $currentTable
     *
     * @version 1.1.0 Initial version.
     */
    public function __construct(TableSchema $table, TableSchema $currentTable)
    {
        $this->table = $table;
        $this->currentTable = $currentTable;
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
        return sprintf(
            Translate::getModuleTranslation('coreupdater', 'Table [1]%1$s[/1] should use character set [2]%2$s[/2] instead of [3]%3$s[/3]', 'coreupdater'),
            $this->table->getName(),
            $this->table->getCharset()->describe(),
            $this->currentTable->getCharset()->describe()
        );
    }

    /**
     * Returns unique identification of this database difference.
     *
     * @return string
     */
    function getUniqueId()
    {
        return get_class($this) . ':' . $this->table->getName();
    }

    /**
     * This operation is NOT destructive -- this is only table's DEFAULT character set. Every column tracks information
     * about its own character set and collation.
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
        return self::SEVERITY_NORMAL;
    }

    /**
     * Applies fix to correct this database difference
     *
     * @param Db $connection
     * @return bool
     * @throws \PrestaShopException
     */
    function applyFix(Db $connection)
    {
        $charset = $this->table->getCharset();
        $stmt = 'ALTER TABLE `' . bqSQL($this->table->getName()) . '` CHARACTER SET ' . $charset->getCharset() . ' COLLATE ' . $charset->getCollate();
        return $connection->execute($stmt);
    }
}
