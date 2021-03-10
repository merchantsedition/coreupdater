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
 * Difference in column database type
 *
 * @version 1.1.0 Initial version.
 */
class DifferentDataType implements SchemaDifference
{
    private $table;
    private $column;
    private $currentColumn;

    // type families
    const TYPE_FAMILIES = [
        'integer' => [ 'tinyint', 'smallint', 'mediumint', 'int', 'bigint' ],
        'string' => ['char', 'varchar', 'text', 'mediumtext', 'longtext'],
        'date' => ['date', 'datetime', 'time', 'timestamp'],
        'decimal' => ['decimal', 'double', 'float'],
        'enum' => ['enum'],
        'others' => ['varbinary'],
    ];

    /**
     * DifferentDataType constructor.
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
        return sprintf(
            Translate::getModuleTranslation('coreupdater', 'Column [1]%1$s.%2$s[/1] has data type [2]%3$s[/2] instead of [3]%4$s[/3]', 'coreupdater'),
            $this->table->getName(),
            $this->column->getName(),
            $this->getDataType($this->currentColumn),
            $this->getDataType($this->column)
        );
    }

    /**
     * @param ColumnSchema $column
     *
     * @version 1.1.0 Initial version.
     * @return string
     */
    public function getDataType(ColumnSchema $column)
    {
        $output = $column->getDataType();

        return $output;
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
     * This operation is can be destructive when we migrate between incompatible data type
     *
     * @return bool
     */
    function isDestructive()
    {
        $sourceBaseType = $this->currentColumn->getBaseType();
        $targetBaseType = $this->column->getBaseType();

        $sourceFamilyType = static::getFamilyType($sourceBaseType);
        $targetFamilyType = static::getFamilyType($targetBaseType);

        // destructive, if we are migrating between different type families
        if ($sourceFamilyType !== $targetFamilyType) {
            return true;
        }

        switch ($sourceFamilyType) {
            case 'integer':
                // special case for boolean types. Migrating to boolean from other integer
                // types is never considered destructive, because we care about truthness only
                if ($this->column->getDataType() === 'tinyint(1) unsigned') {
                    return false;
                }

                // operation is potentially destructive if we migrate from bigint > int, from int > tinyint, etc
                $sourceIndex = array_search($sourceBaseType, static::TYPE_FAMILIES['integer']);
                $targetIndex = array_search($targetBaseType, static::TYPE_FAMILIES['integer']);
                return $sourceIndex > $targetIndex;
            case 'string':
                $sourceSize = static::getTextColumnSize($this->currentColumn);
                $targetSize = static::getTextColumnSize($this->column);
                return $targetSize < $sourceSize;
            case 'date':
                // converting between different date columns
                if ($sourceBaseType === 'date' && $targetBaseType === 'datetime') {
                    return false;
                }
                if ($sourceBaseType === 'date' && $targetBaseType === 'timestamp') {
                    return false;
                }
                if ($sourceBaseType === 'datetime' && $targetBaseType === 'timestamp') {
                    return false;
                }
                if ($sourceBaseType === 'timestamp' && $targetBaseType === 'datetime') {
                    return false;
                }
                return true;
            case 'decimal':
                if ($sourceBaseType === 'decimal' && $targetBaseType === 'decimal') {
                    $sourcePrecision = static::getDecimalPrecision($this->currentColumn);
                    $targetPrecision = static::getDecimalPrecision($this->column);
                    if ($targetPrecision < $sourcePrecision) {
                        return true;
                    }
                    $sourceScale = static::getDecimalScale($this->currentColumn);
                    $targetScale = static::getDecimalScale($this->column);
                    return ($targetScale < $sourceScale);
                }
                return true;
            case 'enum':
                // operation is destructive only when we drop some enum value
                $sourceValues = static::getEnumValues($this->currentColumn);
                $targetValues = static::getEnumValues($this->column);
                foreach ($sourceValues as $value) {
                    if (! in_array($value, $targetValues)) {
                        return true;
                    }
                }
                return false;
        }
        // unknown family type, let's consider migrating them destructive
        return true;
    }

    /**
     * Returns severity of this difference
     *
     * @return int severity
     */
    function getSeverity()
    {
        $sourceBaseType = $this->currentColumn->getBaseType();
        $targetBaseType = $this->column->getBaseType();

        // severity is critical if base types are different, ie change from
        // INT -> TINYINT etc
        if ($sourceBaseType !== $targetBaseType) {
            return self::SEVERITY_CRITICAL;
        }

        // if base types are the same, then decide based on destructive flag.

        // When operation is destructive, it means that we are migrating to smaller
        // type, ie VARCHAR(60) --> VARCHAR(30).
        //
        // But this migration is not really critical or necessary, because every string(30)
        // can be stored in VARCHAR(60) data type.
        return $this->isDestructive()
            ? static::SEVERITY_NORMAL
            : static::SEVERITY_CRITICAL;
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
        $column->setDataType($this->column->getDataType());
        if ($column->getDataType() === 'timestamp' && $column->hasDefaultValueNull()) {
            $column->setDefaultValue(ObjectModel::DEFAULT_CURRENT_TIMESTAMP);
        }
        $stmt = 'ALTER TABLE `' . bqSQL($this->table->getName()) . '` MODIFY COLUMN ' . $column->getDDLStatement($this->table);
        return $connection->execute($stmt);
    }

    /**
     * @param string $baseType
     * @return string
     */
    public static function getFamilyType($baseType)
    {
        foreach (static::TYPE_FAMILIES as $family => $types) {
            if (array_search($baseType, $types) !== false) {
                return $family;
            }
        }
        return 'others';
    }

    /**
     * Returns size of text column
     *
     * @param ColumnSchema $column
     * @return int
     */
    private static function getTextColumnSize($column)
    {
        $type = $column->getBaseType();
        if ($type === 'text') {
            return ObjectModel::SIZE_TEXT;
        }
        if ($type === 'mediumtext') {
            return ObjectModel::SIZE_MEDIUM_TEXT;
        }
        if ($type === 'longtext') {
            return ObjectModel::SIZE_LONG_TEXT;
        }
        $size = (int)$column->getExtraInformation();
        return $size ? $size : 1;
    }

    /**
     * Returns precision of decimal column - DECIMAL(20,3) --> 20
     *
     * @param ColumnSchema $column
     * @return int
     */
    private static function getDecimalPrecision(ColumnSchema $column)
    {
        $extra = $column->getExtraInformation();
        if ($extra) {
            $parts = explode(',', $extra);
            return (int)$parts[0];
        }
        return 0;
    }

    /**
     * Returns scale of decimal column - DECIMAL(20,3) --> 3
     *
     * @param ColumnSchema $column
     * @return int
     */
    private static function getDecimalScale(ColumnSchema $column)
    {
        $extra = $column->getExtraInformation();
        if ($extra) {
            $parts = explode(',', $extra);
            if ($parts && count($parts) >= 2) {
                return (int)$parts[1];
            }
        }
        return 0;
    }

    /**
     * Return list of enum values
     *
     * ENUM('a', 'b', 'c') => Array('a', 'b', 'c')
     *
     * @param ColumnSchema $column
     * @return array
     */
    private static function getEnumValues(ColumnSchema $column)
    {
        $extra = $column->getExtraInformation();
        if ($extra) {
            return array_map('trim', explode(',', $extra));
        }
        return [];
    }
}

