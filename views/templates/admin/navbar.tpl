{**
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
 *}

<nav class="navbar navbar-default" role="navigation">
	<ul class="nav navbar-nav">
		{if isset($menu_tabs)}
			{foreach from=$menu_tabs item=tab}
				<li class="{if $tab.active}active{/if}">
					<a id="{$tab.short|escape:'htmlall'}" href="{$tab.href|escape:'htmlall'}">
						<span class="icon {$tab.icon|escape:'htmlall'}"></span>
						{$tab.desc|escape:'htmlall'}
					</a>
				</li>
			{/foreach}
		{/if}
	</ul>
</nav>

