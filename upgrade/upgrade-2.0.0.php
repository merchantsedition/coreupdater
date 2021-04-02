<?php
/**
 * Copyright (C) 2021 Merchant's Edition GbR
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
 * @copyright 2021 Merchant's Edition GbR
 * @license   Academic Free License (AFL 3.0)
 */

if ( ! defined('_TB_VERSION_')) {
    exit;
}

function upgrade_module_2_0_0($module) {
    @unlink(_PS_CACHE_DIR_.'GitUpdateStorage.php');
    Tools::deleteDirectory(_PS_CACHE_DIR_.'GitUpdateDownloads');
    @unlink(_PS_CACHE_DIR_.'GitUpdateScript.php');

    return true;
}
