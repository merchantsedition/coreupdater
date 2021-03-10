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
use \PrestaShopException;
use \PrestaShopDatabaseException;


if (!defined('_TB_VERSION_')) {
    exit;
}

/**
 * Interface SchemaDifference
 *
 * Interface to represents difference between two database schemas
 *
 * At the moment, this interface only allows for reporting on database
 * difference. In the future there will be another methods to rectify the
 * situation (apply database migration changes).
 *
 * @version 1.1.0 Initial version.
 */
interface SchemaDifference
{
    /**
     * Severity levels
     */
    const SEVERITY_NOTICE = 0;
    const SEVERITY_NORMAL = 1;
    const SEVERITY_CRITICAL = 2;

    /**
     * Returns unique identification of this database difference. This is needed to find and apply fix for
     * specific database difference
     *
     * @return string
     */
    function getUniqueId();

    /**
     * Returns string representation of this database difference
     *
     * @return string
     */
    function describe();

    /**
     * Returns true, if the fix could mean loosing data. For example, dropping database column is always destructive.
     * Changing column database type can be destructive, but it doesn't need to be. As an example, change data type
     * from int(11) to int(4) does not modify data, it only affects display length
     *
     * @return boolean
     */
    function isDestructive();

    /**
     * Returns severity of this database difference
     *
     * @return int severity level
     */
    function getSeverity();

    /**
     * Method to actually fix the schema difference
     *
     * @param Db $connection database connection on which to apply fix
     * @throws PrestaShopException
     * @throws PrestaShopDatabaseException
     * @return boolean
     */
    function applyFix(Db $connection);
}

