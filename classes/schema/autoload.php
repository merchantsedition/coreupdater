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

require_once __DIR__.'/DatabaseCharset.php';
require_once __DIR__.'/TableSchema.php';
require_once __DIR__.'/ColumnSchema.php';
require_once __DIR__.'/TableKey.php';
require_once __DIR__.'/DatabaseSchema.php';
require_once __DIR__.'/builder/ObjectModelSchemaBuilder.php';
require_once __DIR__.'/builder/InformationSchemaBuilder.php';
require_once __DIR__.'/SchemaDifference.php';
require_once __DIR__.'/differences/DifferentEngine.php';
require_once __DIR__.'/differences/ExtraTable.php';
require_once __DIR__.'/differences/DifferentDefaultValue.php';
require_once __DIR__.'/differences/ExtraKey.php';
require_once __DIR__.'/differences/DifferentTableCharset.php';
require_once __DIR__.'/differences/DifferentNullable.php';
require_once __DIR__.'/differences/MissingTable.php';
require_once __DIR__.'/differences/DifferentColumnCharset.php';
require_once __DIR__.'/differences/DifferentAutoIncrement.php';
require_once __DIR__.'/differences/DifferentKey.php';
require_once __DIR__.'/differences/ExtraColumn.php';
require_once __DIR__.'/differences/MissingColumn.php';
require_once __DIR__.'/differences/MissingKey.php';
require_once __DIR__.'/differences/DifferentDataType.php';
require_once __DIR__.'/differences/DifferentColumnsOrder.php';
require_once __DIR__.'/DatabaseSchemaComparator.php';
