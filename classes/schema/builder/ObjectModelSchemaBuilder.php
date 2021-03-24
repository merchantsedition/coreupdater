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

use \CoreModels;
use \ObjectModel;
use \PrestaShopException;
use \ReflectionClass;
use \ReflectionException;
use \RecursiveDirectoryIterator;
use \RecursiveIteratorIterator;
use \Shop;

if (!defined('_TB_VERSION_')) {
    exit;
}

/**
 * Class ObjectModelSchemaBuilder
 *
 * This class is responsible for building DatabaseSchema object based on
 * ObjectModel metadata present in core.
 *
 * Subclasses of ObjectModel class contains static property $definition which
 * describes information about database tables and columns used by this domain
 * object.
 *
 * Some core tables are not used by any ObjectModel, though. These tables are
 * described in Models class.
 *
 * Combining all $definition objects and Models, we can describe complete
 * database schema, and compile DatabaseSchema object that represents this
 * schema.
 *
 * We can use this DatabaseSchema to create the database. Also, by comparing
 * this database schema to current database schema (retrieved by
 * InformationSchemaBuilder), we can find all differences, and rectify them.
 *
 * @version 1.1.0 Initial version.
 */
class ObjectModelSchemaBuilder
{
    /**
     * @var DatabaseSchema database schema
     */
    protected $schema;

    /**
     * Builds DatabaseSchema object for from information stored in
     * ObjectModel::$definition properties, and in models.
     *
     * @return DatabaseSchema
     * @throws PrestaShopException
     * @throws ReflectionException
     *
     * @version 1.1.0 Initial version.
     */
    public function getSchema()
    {
        if (!$this->schema) {
            $this->schema = new DatabaseSchema();
            $this->processModels();
            $this->processObjectModels();
        }

        return $this->schema;
    }

    /**
     * Process all models defined in Models class
     *
     * @throws PrestaShopException
     *
     * @version 1.1.0 Initial version.
     */
    protected function processModels() {
        foreach (CoreModels::getModels() as $identifier => $definition) {
            $this->processModel($identifier, $definition);
        }
    }

    /**
     * Finds all core ObjectModel subclasses in the core codebase and process
     * their $definition.
     *
     * @throws PrestaShopException
     * @throws ReflectionException
     *
     * @version 1.1.0 Initial version.
     */
    protected function processObjectModels()
    {
        $directory = new RecursiveDirectoryIterator(_PS_ROOT_DIR_ . DIRECTORY_SEPARATOR . 'classes');
        $iterator = new RecursiveIteratorIterator($directory);

        $list = [];
        foreach ($iterator as $path) {
            $list[] = "$path";
        }
        sort($list);

        foreach ($list as $path) {
            $file = basename($path);
            if (preg_match("/^.+\.php$/i", $file)) {
                $className = str_replace(".php", "", $file);
                if ($className !== "index") {
                    $namespace = $this->resolveNamespace($path);
                    if ($namespace) {
                        $className = $namespace . $className;
                    }
                    if (! class_exists($className)) {
                        require_once($path);
                    }
                    if (class_exists($className)) {
                        $reflection = new ReflectionClass($className);
                        if ($reflection->isSubclassOf('ObjectModel') && !$reflection->isAbstract()) {
                            $definition = ObjectModel::getDefinition($className);
                            if ($definition && isset($definition['table'])) {
                                $this->processModel($className, $definition);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Extracts namespace from the php file, if exists
     *
     * @param string $path file path
     * @return string namespace or empty string
     */
    protected function resolveNamespace($path)
    {
        $content = @file_get_contents($path);
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            if (preg_match('#^\s*namespace\s+([^\s;]+)\s*;\s*$#', $line, $matches)) {
                $fileNamespace = trim($matches[1], '\\') . '\\';
                return $fileNamespace;
            }
        }
        return "";
    }

    /**
     * Process single ObjectModel::$definition array. This will adds all tables,
     * columns, and keys used by this object model to the DatabaseSchema object.
     *
     * @param $objectModel string object model name
     * @param $definition array object model definition
     *
     * @throws PrestaShopException
     *
     * @version 1.1.0 Initial version.
     */
    protected function processModel($objectModel, $definition)
    {
        // character set used by object model. It is list of two values [ character set, collation ]
        $charsetDefinition = static::getOption($definition, 'charset', ['utf8mb4', 'utf8mb4_unicode_ci']);
        $charset = new DatabaseCharset($charsetDefinition[0], $charsetDefinition[1]);

        // define primary table
        $primaryTable = $this->addPrimaryTable($definition, $charset);

        // register lang table if object model are using this feature
        $langTable = $this->addLangTable($definition, $charset);

        // register shop table if object model are using this feature
        $shopTable = $this->addShopTable($definition, $charset);

        // iterate over object model fields and register related database columns
        if (isset($definition['fields'])) {
            foreach ($definition['fields'] as $field => $columnDefinition) {
                $this->processField($objectModel, $field, $columnDefinition, $primaryTable, $langTable, $shopTable, $charset);
            }
        }

        // iterate over keys and register database keys / indexes
        if (isset($definition['keys'])) {
             foreach ($definition['keys'] as $tableName => $keys) {
                 $this->addKey($tableName, $keys);
             }
        }

        // call static method $objectModel::processTableSchema if exists
        $this->postProcessTables($objectModel, $primaryTable, $shopTable, $langTable);
    }

    /**
     * Registers object model's primary table to DatabaseSchema
     *
     * @param array $definition object model definition
     * @param DatabaseCharset $charset character set
     *
     * @return TableSchema
     *
     * @version 1.1.0 Initial version.
     */
    protected function addPrimaryTable($definition, $charset)
    {
        $primaryTable = $this->getTable($definition['table'], $charset);
        $this->schema->addTable($primaryTable);

        // register primary key
        if (isset($definition['primary'])) {
            $primaryKey = new ColumnSchema($definition['primary']);
            $primaryKey->setDataType(static::getOption($definition, 'primaryKeyDbType', 'int(11) unsigned'));
            $primaryKey->setNullable(false);
            $primaryKey->setAutoIncrement(static::getOption($definition, 'autoIncrement', true));
            $primaryTable->addColumn($primaryKey);

            $key = new TableKey(ObjectModel::PRIMARY_KEY, 'PRIMARY');
            $key->addColumn($definition['primary']);
            $primaryTable->addKey($key);
        }
        return $primaryTable;
    }

    /**
     * Registers object model's lang table if it is used by this object model
     *
     * @param array $definition object model definition
     * @param DatabaseCharset $charset character set
     *
     * @return TableSchema | null
     *
     * @version 1.1.0 Initial version.
     */
    protected function addLangTable($definition, $charset)
    {
        if (static::checkOption($definition, 'multilang')) {
            $langTable = $this->getTable($definition['table'] . '_lang', $charset);
            $langTableKey = new TableKey(ObjectModel::PRIMARY_KEY, 'PRIMARY');

            // lang table must have the foreign key to primary table
            $foreignKey = new ColumnSchema($definition['primary']);
            $foreignKey->setDataType('int(11) unsigned');
            $foreignKey->setNullable(false);
            $langTable->addColumn($foreignKey);
            $langTableKey->addColumn($definition['primary']);

            // lang table must have foreign key to shop table
            $idLangKey = new ColumnSchema('id_lang');
            $idLangKey->setDataType('int(11) unsigned');
            $idLangKey->setNullable(false);
            $langTable->addColumn($idLangKey);
            $langTableKey->addColumn('id_lang');

            if (static::checkOption($definition, 'multilang_shop')) {
                // shop table must have foreign key to shop table
                $idShopKey = new ColumnSchema('id_shop');
                $idShopKey->setDataType('int(11) unsigned');
                $idShopKey->setNullable(false);
                $idShopKey->setDefaultValue('1');
                $langTable->addColumn($idShopKey);
                $langTableKey->addColumn('id_shop');
            }

            $langTable->addKey($langTableKey);
            $this->schema->addTable($langTable);
            return $langTable;
        }
        return null;
    }

    /**
     * Registers object model's shop table if it is used by this object model
     *
     * @param array $definition object model definition
     * @param DatabaseCharset $charset character set
     *
     * @return TableSchema | null
     *
     * @version 1.1.0 Initial version.
     */
    protected function addShopTable($definition, $charset)
    {
        $hasShopTable = Shop::isTableAssociated($definition['table']) || static::checkOption($definition, 'multishop');
        if ($hasShopTable) {
            $shopTable = $this->getTable($definition['table'] . '_shop', $charset);
            $shopTableKey = new TableKey(ObjectModel::PRIMARY_KEY, 'PRIMARY');

            // shop table must have the foreign key to primary table
            $foreignKey = new ColumnSchema($definition['primary']);
            $foreignKey->setDataType('int(11) unsigned');
            $foreignKey->setNullable(false);
            $shopTable->addColumn($foreignKey);
            $shopTableKey->addColumn($definition['primary']);

            // shop table must have foreign key to shop table
            $idShopKey = new ColumnSchema('id_shop');
            $idShopKey->setDataType('int(11) unsigned');
            $idShopKey->setNullable(false);
            $shopTable->addColumn($idShopKey);
            $shopTableKey->addColumn('id_shop');

            $shopTable->addKey($shopTableKey);
            $this->schema->addTable($shopTable);
            return $shopTable;
        }
        return null;
    }

    /**
     * Process single field definition -- registers related database columns
     * inside primary, lang, or shop tables. It is possible that one field
     * definition impacts multiple tables.
     *
     * @param string $objectModel object model name
     * @param string $columnName column name
     * @param array $columnDefinition column definition
     * @param TableSchema $primaryTable
     * @param TableSchema $langTable
     * @param TableSchema $shopTable
     * @param DatabaseCharset $charset
     *
     * @throws PrestaShopException
     *
     * @version 1.1.0 Initial version.
     */
    protected function processField($objectModel, $columnName, $columnDefinition, $primaryTable, $langTable, $shopTable, $charset)
    {
        // create column
        $column = new ColumnSchema($columnName);

        // column can have it's own character set / collation
        $columnCharset = $this->getColumnCharset($columnDefinition, $charset);
        if ($columnCharset) {
            $column->setCharset($columnCharset);
        }

        /// derive column database type based on field properties
        $column->setDataType($this->getColumnDataType($columnDefinition, $objectModel, $columnName));

        // set column nullable option
        if (array_key_exists('dbNullable', $columnDefinition)) {
            $column->setNullable(!!$columnDefinition['dbNullable']);
        } else if (array_key_exists('required', $columnDefinition)) {
            $column->setNullable(!$columnDefinition['required']);
        }

        // set column default value
        $column->setDefaultValue(static::getOption($columnDefinition, 'dbDefault', static::getOption($columnDefinition, 'default', $column->isNullable() ? ObjectModel::DEFAULT_NULL : null)));

        // figure out table(s) that should contain this column
        $tables = [];
        if ($langTable && static::checkOption($columnDefinition, 'lang')) {
            // add lang fields to lang table only
            $tables[] = $langTable;
        } else {
            if ($shopTable && static::checkOption($columnDefinition, 'shop')) {
                // shop fields are usually present in both primary and _shop tables. In some specific cases, shop field
                // is added to _shop table only
                if (! static::checkOption($columnDefinition, 'shopOnly')) {
                    $tables[] = $primaryTable;
                }
                $tables[] = $shopTable;
            } else {
                // add column to primary table
                $tables[] = $primaryTable;
            }
        }

        // add column to tables
        foreach ($tables as $table) {
            $table->addColumn($column);
        }

        // if column field is unique we need to create unique key for it
        if (static::checkOption($columnDefinition, 'unique')) {
            $keyName = static::getOption($columnDefinition, 'unique', false);
            if (!is_string($keyName)) {
                $keyName = $columnName;
            }
            $uniqueKey = new TableKey(ObjectModel::UNIQUE_KEY, $keyName);
            $uniqueKey->addColumn($columnName);
            foreach ($tables as $table) {
                $table->addKey($uniqueKey);
            }
        }
    }

    /**
     * Process single key definition, and registers database primary/unique/
     * foreign key for given table.
     *
     * @param $tableName
     * @param $keys
     *
     * @version 1.1.0 Initial version.
     */
    protected function addKey($tableName, $keys)
    {
        $table = $this->schema->getTable(_DB_PREFIX_ . $tableName);
        foreach ($keys as $keyName => $keyDefinition) {
            $key = new TableKey($keyDefinition['type'], $keyName);
            $subParts = static::getOption($keyDefinition, 'subParts', []);
            for ($i = 0; $i < count($keyDefinition['columns']); $i++) {
                $column = $keyDefinition['columns'][$i];
                $subPart = isset($subParts[$i]) ? $subParts[$i] : null;
                $key->addColumn($column, $subPart);
            }
            $table->addKey($key);
        }
    }

    /**
     * If $objectModel class contains static function 'processTableSchema' we
     * will call it for every table object model is using. This gives the object
     * model option to modify the table in some way. This is needed for
     * backwards compatibility only, this mechanism should not be used for new
     * object models.
     *
     * @param string $objectModel object model class name
     * @param TableSchema $primaryTable
     * @param TableSchema $shopTable
     * @param TableSchema $langTable
     *
     * @version 1.1.0 Initial version.
     */
    protected function postProcessTables($objectModel, $primaryTable, $shopTable, $langTable)
    {
        if (class_exists($objectModel) && is_callable([$objectModel, 'processTableSchema'])) {
            call_user_func([$objectModel, 'processTableSchema'], $primaryTable);
            if ($shopTable) {
                call_user_func([$objectModel, 'processTableSchema'], $shopTable);
            }
            if ($langTable) {
                call_user_func([$objectModel, 'processTableSchema'], $langTable);
            }
        }
    }

    /**
     * Creates new TableSchema object and initialize its basic properties like
     * database engine, character set, and collation.
     *
     * @param string $unprefixedTableName table name without _DB_PREFIX_
     * @param DatabaseCharset $charset
     *
     * @return TableSchema
     *
     * @version 1.1.0 Initial version.
     */
    protected function getTable($unprefixedTableName, $charset)
    {
        $tableName = _DB_PREFIX_ . $unprefixedTableName;
        $table = $this->schema->getTable($tableName);
        if (!$table) {
            $table = new TableSchema($tableName);
            $table->setEngine(_MYSQL_ENGINE_);
            $table->setCharset($charset);
        }

        return $table;
    }

    /**
     * This method derives column database type based on its definition
     *
     * @param $columnDefinition array
     * @param $objectModel string
     * @param $field
     *
     * @return string
     * @throws PrestaShopException
     *
     * @version 1.1.0 Initial version.
     */
    protected function getColumnDataType($columnDefinition, $objectModel, $field)
    {
        // if column contains 'dbType' property, we will always use it
        if (static::checkOption($columnDefinition, 'dbType')) {
            return $columnDefinition['dbType'];
        }

        // otherwise decide based on column definition type
        switch ($columnDefinition['type']) {
            case ObjectModel::TYPE_INT:
                $size = static::getOption($columnDefinition, 'size', 11);
                $signed = static::getOption($columnDefinition, 'signed', false);
                $type = ($size == 1) ? 'tinyint' : 'int';
                return $type . '(' . $size . ')' . ($signed ? '' : ' unsigned');
            case ObjectModel::TYPE_BOOL:
                return 'tinyint(1) unsigned';
            case ObjectModel::TYPE_STRING:
            case ObjectModel::TYPE_HTML:
                if (static::checkOption($columnDefinition, 'values')) {
                    return 'enum(\'' . implode("','", $columnDefinition['values']) . '\')';
                }
                $size = static::getOption($columnDefinition, 'size', ObjectModel::SIZE_MAX_VARCHAR);
                if ($size <= ObjectModel::SIZE_MAX_VARCHAR) {
                    return "varchar($size)";
                }
                if ($size <= ObjectModel::SIZE_TEXT) {
                    return 'text';
                }
                if ($size <= ObjectModel::SIZE_MEDIUM_TEXT) {
                    return 'mediumtext';
                }
                return 'longtext';
            case ObjectModel::TYPE_FLOAT:
                $size = static::getOption($columnDefinition, 'size', 20);
                $decimals = static::getOption($columnDefinition, 'decimals', 6);
                return "decimal($size,$decimals)";
            case ObjectModel::TYPE_PRICE:
                $size = static::getOption($columnDefinition, 'size', 20);
                $decimals = static::getOption($columnDefinition, 'decimals', 6);
                return "decimal($size,$decimals)";
            case ObjectModel::TYPE_DATE:
                return 'datetime';
            case ObjectModel::TYPE_NOTHING:
            case ObjectModel::TYPE_SQL:
                throw new PrestaShopException('Please change type for field `' . $field . '` in object model `' . $objectModel. '`, or set specific `dbType`');
            default:
                throw new PrestaShopException('Field `' . $field . '` in object model `' . $objectModel. '` has unknown type: ' . $columnDefinition['type']);
        }
    }

    /**
     * Derives column character set and collation
     *
     * @param array $columnDefinition field definition
     * @param DatabaseCharset $tableCharset table character set
     *
     * @return DatabaseCharset | null
     *
     * @version 1.1.0 Initial version.
     */
    protected function getColumnCharset($columnDefinition, $tableCharset)
    {
        // use charset from field definition if exists
        if (static::checkOption($columnDefinition, 'charset')) {
            $charsetDefinition = $columnDefinition['charset'];
            return new DatabaseCharset($charsetDefinition[0], $charsetDefinition[1]);
        }
        // otherwise return table charset for text fields
        switch ($columnDefinition['type']) {
            case ObjectModel::TYPE_STRING:
            case ObjectModel::TYPE_HTML:
                return $tableCharset;
            default:
                // non-text tables don't have associated character set / collation
                return null;
        }
    }

    /**
     * Helper method - returns true, if $key exists in $array, and its value is truthy
     *
     * @param array $array
     * @param string $key
     *
     * @return bool
     *
     * @version 1.1.0 Initial version.
     */
    protected static function checkOption($array, $key)
    {
        return isset($array[$key]) && !!$array[$key];
    }

    /**
     * Helper method - return array value with default
     *
     * @param array $array
     * @param string $key
     * @param mixed $default
     *
     * @return mixed
     *
     * @version 1.1.0 Initial version.
     */
    protected static function getOption($array, $key, $default)
    {
        if (array_key_exists($key, $array)) {
            return $array[$key];
        }

        return $default;
    }
}
