<?php
/**
 * Copyright (C) 2018-2019 thirty bees
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@thirtybees.com so we can send you a copy immediately.
 *
 * @author    thirty bees <modules@thirtybees.com>
 * @copyright 2018-2019 thirty bees
 * @license   Academic Free License (AFL 3.0)
 */

if (!defined('_TB_VERSION_')) {
    exit;
}

if (version_compare(phpversion(), '5.6', '>=')) {
    require_once __DIR__.'/classes/GitUpdate.php';
}

/**
 * Class CoreUpdater
 */
class CoreUpdater extends Module
{
    const MAIN_CONTROLLER = 'AdminCoreUpdater';

    /**
     * CoreUpdater constructor.
     *
     * @version 1.0.0 Initial version.
     */
    public function __construct()
    {
        $this->name = 'coreupdater';
        $this->tab = 'administration';
        $this->version = '1.3.2';
        $this->author = 'Merchant\'s Edition';
        $this->bootstrap = true;
        $this->need_instance = 0;

        parent::__construct();

        $this->displayName = $this->l('Core Updater');
        $this->description = $this->l('This module brings the tools for keeping your shop installation up to date.');
        $this->tb_versions_compliancy = '>= 1.0.0';
        $this->tb_min_version = '1.0.0';
    }

    /**
     * Install this module.
     *
     * @return bool Whether this module was successfully installed.
     *
     * @version 1.0.0 Initial version.
     */
    public function install()
    {
        if (version_compare(phpversion(), '5.6', '<')) {
            $this->_errors[] = $this->l('This module requires PHP 5.6 or later.');

            return false;
        }

        Shop::setContext(Shop::CONTEXT_ALL);

        $success = parent::install();

        $tabSuccess = false;
        if ($success) {
            try {
                $tab = new Tab();

                $tab->module      = $this->name;
                $tab->class_name  = static::MAIN_CONTROLLER;
                $tab->id_parent   = Tab::getIdFromClassName('AdminPreferences');

                $langs = Language::getLanguages();
                foreach ($langs as $lang) {
                    $tab->name[$lang['id_lang']] = $this->l('Core Updater');
                }

                $tabSuccess = $tab->save();
            } catch (Exception $e) {
            }
        }
        if ( ! $tabSuccess) {
            // Unfortunately, warnings are not supported ...
            $this->_errors[] = sprintf($this->l('Module installation successful, but installation of the menu item failed. Please add an item for class %s manually.'), static::MAIN_CONTROLLER);
            // ... and errors appear only when returning false.
            $success = false;
        }

        return $success;
    }

    /**
     * Uninstall this module.
     *
     * @return bool Whether this module was successfully uninstalled.
     *
     * @version 1.0.0 Initial version.
     */
    public function uninstall()
    {
        Shop::setContext(Shop::CONTEXT_ALL);

        $success = true;
        $success = $success && CoreUpdater\GitUpdate::uninstall();

        $tabs = Tab::getCollectionFromModule($this->name);
        foreach ($tabs as $tab) {
            $success = $success && $tab->delete();
        }

        Configuration::deleteByName('CORE_UPDATER_IGNORE_THEME');

        return $success && parent::uninstall();
    }

    /**
     * Get module configuration page.
     *
     * @return string Configuration page HTML.
     *
     * @version 1.0.0 Initial version.
     */
    public function getContent()
    {
        $enabledModules = [];
        $warn = false;
        foreach ([
            //'tbupdater', // Still needed by core.
            'autoupgrade',
            'psonefivemigrator',
            'psonesixmigrator',
            'psonesevenmigrator',
        ] as $moduleName) {
            if (Module::isInstalled($moduleName)) {
                $enabledModules[] = $moduleName;
                $warn = true;
            }
        }

        $this->context->smarty->assign([
            'enabledModules'  => $enabledModules,
            'warn'            => $warn,
        ]);

        return $this->display(__FILE__, 'views/templates/admin/competition.tpl');
    }
}
