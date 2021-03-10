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
 * Class Requirements.
 *
 * This class checks whether requirements for a specific thirty bees version
 * are met by the current installation. Requirements of thirty bees v1.0.0
 * are assumed (else the shop wouldn't run).
 */
class Requirements
{
    /**
     * Master method to check all requirements of a given version.
     *
     * @param string $version The version to test for.
     *
     * @return array Empty array on success, array with error messages on
     *               failure.
     *
     * @version 1.0.0 Initial version.
     */
    public static function checkAllRequirements($version) {
        $errors = [];
        $me = new Requirements;

        $errors = array_merge($errors, $me->testPhpVersion($version));
        $errors = array_merge($errors, $me->testOpenSSL($version));

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
     * Test for the minimum required PHP version.
     *
     * @param string $version The thirty bees version to test for.
     *
     * @return array Empty array on success, array with error messages on
     *               failure.
     *
     * @version 1.0.0 Initial version.
     */
    protected function testPhpVersion($version) {
        $errors = [];

        // Minimum PHP version.
        foreach ([
            // Read 'since thirty bees v1.0.0, PHP v5.5 or later is required'.
            '1.0.0'   => '5.5',
            '1.1.0'   => '5.6',
        ] as $testVersion => $phpVersion) {
            if (version_compare($version, $testVersion, '>=')
                && ! version_compare(phpversion(), $phpVersion, '>=')) {
                $errors[] = sprintf($this->l('thirty bees %s requires PHP %s or later.'), $version, $phpVersion);
            }
        }

        return $errors;
    }

    /**
     * Test for presence of the OpenSSL PHP extension.
     *
     * @param string $version The thirty bees version to test for.
     *
     * @return array Empty array on success, array with error messages on
     *               failure.
     *
     * @version 1.1.0 Initial version.
     */
    protected function testOpenSSL($version) {
        $errors = [];

        if (version_compare($version, '1.1.0', '>=')) {
            if ( ! extension_loaded('openssl')
                || ! function_exists('openssl_encrypt')) {
                $errors[] = $this->l('thirty bees 1.1.0 or later requires the PHP OpenSSL extension.');
            }
        }

        return $errors;
    }
}
