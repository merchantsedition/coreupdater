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

if (!defined('_TB_VERSION_')) {
    exit;
}

/**
 * Class Retrocompatibility.
 *
 * This class provides upgrades.
 */
class Retrocompatibility
{
    /**
     * Modules known to be incompatible starting at a certain version. If the
     * target version of the update is this or higher, these modules get
     * uninstalled.
     */
    const MODULE_MIN_INCOMPAT = [
        '1.0.4'   => [
            'graphnvd3',
            'gridhtml',
            'pagesnotfound',
            'sekeywords',
            'statsbestcategories',
            'statsbestcustomers',
            'statsbestmanufacturers',
            'statsbestproducts',
            'statsbestsuppliers',
            'statsbestvouchers',
            'statscarrier',
            'statscatalog',
            'statscheckup',
            'statsequipment',
            'statsforecast',
            'statslive',
            'statsnewsletter',
            'statsorigin',
            'statspersonalinfos',
            'statsproduct',
            'statsregistrations',
            'statssales',
            'statssearch',
            'statsstock',
            'statsvisits',
        ],
        '1.0.5'   => [
        ],
        '1.0.6'   => [
        ],
        '1.0.7'   => [
        ],
        '1.0.8'   => [
        ],
    ];
    /**
     * Modules known to be incompatible up to a certain version. If the target
     * version of the update is this or lower, these modules get uninstalled.
     */
    const MODULE_MAX_INCOMPAT = [
        '1.0.4'   => [
        ],
        '1.0.5'   => [
        ],
        '1.0.6'   => [
        ],
        '1.0.7'   => [
        ],
        '1.0.8'   => [
        ],
    ];

    /**
     * Master method to apply all database upgrades.
     *
     * @return array Empty array on success, array with error messages on
     *               failure.
     *
     * @version 1.0.0 Initial version.
     */
    public static function doAllDatabaseUpgrades() {
        $errors = [];
        $me = new Retrocompatibility;

        $errors = array_merge($errors, $me->doSqlUpgrades());
        $errors = array_merge($errors, $me->handleSingleLangConfigs());
        $errors = array_merge($errors, $me->handleMultiLangConfigs());
        $errors = array_merge($errors, $me->deleteObsoleteTabs());
        $errors = array_merge($errors, $me->addMissingTabs());

        return $errors;
    }

    /**
     * Get translation for a given text.
     *
     * @param string $string String to translate.
     *
     * @return string Translation.
     *
     * @version 1.0.0 Initial version.
     */
    protected function l($string)
    {
        return \Translate::getModuleTranslation('coreupdater', $string,
                                                'coreupdater');
    }

    /**
     * Apply database upgrade scripts.
     *
     * @return array Empty array on success, array with error messages on
     *               failure.
     *
     * @version 1.0.0 Initial version.
     */
    protected function doSqlUpgrades() {
        $errors = [];

        $upgrades = file_get_contents(__DIR__.'/retroUpgrades.sql');
        // Strip comments.
        $upgrades = preg_replace('#/\*.*?\*/#s', '', $upgrades);
        $upgrades = explode(';', $upgrades);

        $db = \Db::getInstance(_PS_USE_SQL_SLAVE_);
        $engine = (defined('_MYSQL_ENGINE_') ? _MYSQL_ENGINE_ : 'InnoDB');
        foreach ($upgrades as $upgrade) {
            $upgrade = trim($upgrade);
            if (strlen($upgrade)) {
                $upgrade = str_replace(['PREFIX_', 'ENGINE_TYPE'],
                                       [_DB_PREFIX_, $engine], $upgrade);

                $result = $db->execute($upgrade);
                if ( ! $result) {
                    $errors[] = (trim($db->getMsgError()));
                }
            }
        }

        return $errors;
    }

    /**
     * Handle single language configuration values, like creating them as
     * necessary. With the old method, insertions were done by SQL directly,
     * and were also known to be troublesome (failed insertion, double
     * insertion, whatever).
     *
     * @return array Empty array on success, array with error messages on
     *               failure.
     *
     * @version 1.0.0 Initial version.
     */
    protected function handleSingleLangConfigs() {
        $errors = [];

        foreach ([
            'TB_MAIL_SUBJECT_TEMPLATE'  => '[{shop_name}] {subject}',
        ] as $key => $value) {
            $currentValue = \Configuration::get($key);
            if ( ! $currentValue) {
                $result = \Configuration::updateValue($key, $value);
                if ( ! $result) {
                    $errors[] = sprintf($this->l('Could not set default value for configuration "%s".', $key));
                }
            }
        }

        return $errors;
    }

    /**
     * Handle multiple language configuration values, like creating them as
     * necessary. This never really worked with the old method. Also do single
     * language -> multi language conversions, which were formerly done by PHP
     * scripts.
     *
     * @return array Empty array on success, array with error messages on
     *               failure.
     *
     * @version 1.0.0 Initial version.
     */
    protected function handleMultiLangConfigs() {
        $errors = [];

        foreach ([
            'PS_ROUTE_product_rule'       => '{categories:/}{rewrite}',
            'PS_ROUTE_category_rule'      => '{rewrite}',
            'PS_ROUTE_layered_rule'       => '{categories:/}{rewrite}{/:selected_filters}',
            'PS_ROUTE_supplier_rule'      => '{rewrite}',
            'PS_ROUTE_manufacturer_rule'  => '{rewrite}',
            'PS_ROUTE_cms_rule'           => 'info/{categories:/}{rewrite}',
            'PS_ROUTE_cms_category_rule'  => 'info/{categories:/}{rewrite}',
        ] as $key => $value) {
            $values = [];
            $needsWrite = false;

            // If there is a single language value already, use this.
            $currentValue = \Configuration::get($key);
            if ($currentValue) {
                $needsWrite = true;
                $value = $currentValue;
            }

            foreach (\Language::getIDs(false) as $idLang) {
                $currentValue = \Configuration::get($key, $idLang);
                if ($currentValue) {
                    $values[$idLang] = $currentValue;
                } else {
                    $needsWrite = true;
                    $values[$idLang] = $value;
                }
            }

            if ($needsWrite) {
                // Delete eventual single language value.
                \Configuration::deleteByName($key);

                // Write multi language values.
                $result = \Configuration::updateValue($key, $values);
                if ( ! $result) {
                    $errors[] = sprintf($this->l('Could not set default value for configuration "%s".', $key));
                }
            }
        }

        return $errors;
    }

    /**
     * Delete obsolete back office menu items (tabs), which were forgotten to
     * get removed by earlier migration module versions. This was formerly
     * part of the 1.0.8 update, but applies to all versions.
     *
     * @return array Empty array on success, array with error messages on
     *               failure.
     *
     * @version 1.0.0 Initial version.
     */
    protected function deleteObsoleteTabs() {
        $errors = [];

        foreach ([
            'AdminMarketing',
        ] as $tabClassName) {
            while ($idTab = \Tab::getIdFromClassName($tabClassName)) {
                $result = (new \Tab($idTab))->delete();
                if ( ! $result) {
                    $errors[] = sprintf($this->l('Could delete back office menu item for controller "%s".', $tabClassName));
                }
            }
        }

        return $errors;
    }

    /**
     * Add missing back office menu items (tabs), which were forgotten to get
     * added by earlier migration module versions. This includes adjustment of
     * its position. This step was formerly part of the 1.0.8 update, but
     * applies to all versions.
     *
     * @return array Empty array on success, array with error messages on
     *               failure.
     *
     * @version 1.0.0 Initial version.
     */
    protected function addMissingTabs() {
        $errors = [];

        foreach ([
            [
                'tabClassName'    => 'AdminDuplicateUrls',
                'tabName'         => 'Duplicate URLs',
                'parentClassName' => 'AdminParentPreferences',
                'aboveClassName'  => 'AdminMeta',
            ],
            [
                'tabClassName'    => 'AdminCustomCode',
                'tabName'         => 'Custom Code',
                'parentClassName' => 'AdminParentPreferences',
                'aboveClassName'  => 'AdminGeolocation',
            ],
            [
                'tabClassName'    => 'AdminAddonsCatalog',
                'tabName'         => 'Modules & Themes Catalog',
                'parentClassName' => 'AdminParentModules',
                'aboveClassName'  => 'AdminModules',
            ],
        ] as $tabSet) {
            if (\Tab::getIdFromClassName($tabSet['tabClassName'])) {
                continue;
            }

            try {
                $tab = new \Tab();

                $tab->class_name  = $tabSet['tabClassName'];
                if ($tabSet['parentClassName']
                    && $idParent = \Tab::getIdFromClassName($tabSet['parentClassName'])) {
                    $tab->id_parent = $idParent;
                }

                if ($tabSet['tabName']) {
                    $langs = \Language::getLanguages();
                    foreach ($langs as $lang) {
                        $translation = \Translate::getAdminTranslation(
                            $tabSet['tabName'], 'AdminTab', false, false);
                        $tab->name[$lang['id_lang']] = $translation;
                    }
                }

                $tab->save();
            } catch (Exception $e) {
                $errors[] = sprintf($this->l('Could not create back office menu item for class "%s".'), $tabSet['tabClassName']);
                continue;
            }

            // Move the new tab to just under the tab with class
            // $tabSet['aboveClassName'].
            if ($tabSet['aboveClassName']) {
                $tabList = \Tab::getTabs(0, $tab->id_parent);

                // Find positions of relevant tabs.
                $posMe = false;
                $posAbove = false;
                foreach ($tabList as $item) {
                    if ($item['class_name'] === $tabSet['tabClassName']) {
                        $posMe = $item['position'];
                    } elseif ($item['class_name'] === $tabSet['aboveClassName']) {
                        $posAbove = $item['position'];
                    }
                }

                // Move. Failures not worth to disturb the merchant with.
                if ($posMe !== false && $posAbove !== false) {
                    $tab->updatePosition($posMe < $posAbove, $posAbove + 1);
                }
            }
        }

        return $errors;
    }

    /**
     * Get a list of installed modules incompatible with the target version.
     * No failure expected.
     *
     * Note: these modules should get uninstalled and deleted _before_ the
     *       update.
     *
     * @param string $targetVersion Target version.
     *
     * @return array Array with strings of module names. Empty array if there
     *               are no incompatible ones installed.
     *
     * @version 1.0.0 Initial version.
     */
    public static function getIncompatibleModules($targetVersion) {
        $incompatibles = [];
        foreach (static::MODULE_MIN_INCOMPAT as $version => $list) {
            if (version_compare($targetVersion, $version, '>=')) {
                $incompatibles = array_merge($incompatibles, $list);
            }
        }
        foreach (static::MODULE_MAX_INCOMPAT as $version => $list) {
            if (version_compare($targetVersion, $version, '<=')) {
                $incompatibles = array_merge($incompatibles, $list);
            }
        }

        $installedIncompatibles = [];
        $modules = \Module::getModulesInstalled();
        foreach ($modules as $module) {
            $name = $module['name'];
            if (in_array($name, $incompatibles)) {
                $installedIncompatibles[] = $name;
            }
        }

        return $installedIncompatibles;
    }

    /**
     * Uninstall the named module and delete its directory.
     *
     * @param string $moduleName Module name.
     *
     * @return array Empty array on success, array with error messages on
     *               failure.
     *
     * @version 1.0.0 Initial version.
     */
    public static function removeModule($moduleName) {
        $errors = [];
        $me = new Retrocompatibility;

        // Uninstall the module.
        if (\Module::isInstalled($moduleName)) {
            $module = \Module::getInstanceByName($moduleName);
            if ($module) {
                $success = $module->uninstall();
                if ( ! $success) {
                    $errors[] = sprintf($me->l('Could find, but not uninstall module %s.'), $moduleName);
                }
            } else {
                $errors[] = sprintf($me->l('System considers module %s to be installed, but could not create an instance.'), $moduleName);
            }
        }

        // Delete the module directory. Do this in case of failure to
        // uninstall it as well.
        $moduleDir = _PS_MODULE_DIR_.$moduleName;
        if (is_dir($moduleDir)) {
            $success = \Tools::deleteDirectory($moduleDir);
            if ( ! $success) {
                $errors[] = sprintf($me->l('Could not delete directory %s.'), $moduleDir);
            }
        }

        return $errors;
    }
}
