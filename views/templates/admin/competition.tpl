{**
 * Copyright (C) 2021 Merchant's Edition GbR
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
 * to contact@merchantsedition.com so we can send you a copy immediately.
 *
 * @author    Merchant's Edition <contact@merchantsedition.com>
 * @author    thirty bees <modules@thirtybees.com>
 * @copyright 2021 Merchant's Edition GbR
 * @copyright 2018-2019 thirty bees
 * @license   Academic Free License (AFL 3.0)
 *}

<div id="blockCompetitionWarning" class="panel">
    <div class="panel-heading">
        {l s='Competing modules' mod='coreupdater'}
    </div>

    {if $warn}
        <div class="alert alert-danger">
            <p>
                {l s='Found modules which can disturb operations of this module. Please uninstall them. These modules are:' mod='coreupdater'}
            </p>
            <ul>
                {foreach $enabledModules as $module}
                    <li>{$module}</li>
                {/foreach}
            </ul>
        </div>
    {else}
        <p>
            {l s='No competing modules found, which is fine.' mod='coreupdater'}
        </p>
        <p>
            {l s='For updating thirty bees, see Menu -> Preferences -> Core Updater.' mod='coreupdater'}
        </p>
    {/if}
</div>
