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

use \AdminCoreUpdaterController as MyController;
use Db;
use DbPDO;
use PrestaShopDatabaseException;
use PrestaShopException;
use ReflectionException;

if (!defined('_TB_VERSION_')) {
    exit;
}

require_once __DIR__.'/Requirements.php';
require_once __DIR__.'/Retrocompatibility.php';

/**
 * Class GitUpdate.
 *
 * This class handles collected knowledge about a given update or version
 * comparison. It's designed to collect this knowledge in small steps, to give
 * merchants a good GUI feedback and avoid running into maximum execution time
 * limits.
 */
class GitUpdate
{
    /**
     * File to store collected knowledge about the update between invocations.
     * It should be a valid PHP file and gets written and included as needed.
     */
    const STORAGE_PATH = _PS_CACHE_DIR_.'coreupdater/storage.php';
    /**
     * Directory where update files get downloaded to, with their full
     * directory hierarchy.
     */
    const DOWNLOADS_PATH = _PS_CACHE_DIR_.'coreupdater/downloads';
    /**
     * Path of the update script.
     */
    const SCRIPT_PATH = _PS_CACHE_DIR_.'coreupdater/script.php';

    /**
     * Set of regular expressions for removing file paths from the list of
     * files of a full release package. Matching paths get ignored by
     * comparions and by updates.
     */
    const RELEASE_FILTER = [
        '#^install/#',
        '#^modules/#',
        '#^mails/en/.*\.txt$#',
        '#^mails/en/.*\.tpl$#',
        '#^mails/en/.*\.html$#',
    ];
    // This gets added with option 'Ignore the community theme' OFF.
    const RELEASE_FILTER_THEME_ON = [
    ];
    // This gets added with option 'Ignore the community theme' ON.
    const RELEASE_FILTER_THEME_OFF = [
        '#^themes/community-theme-default/#',
        '#^themes/niara/#',
    ];
    /**
     * Set of regular expressions for removing file paths from the list of
     * local files. Files in either the original or the target release and not
     * filtered by RELEASE_FILTER get always onto the list.
     */
    const INSTALLATION_FILTER = [
        '#^cache/#',
        '#^config/#',
        '#^img/#',
        '#^upload/#',
        '#^download/#',
        '#^translations/#',
        '#^mails/#',
        '#^override/#',
        '#^.htaccess$#',
        '#^robots.txt$#',
    ];
    // This gets added with option 'Ignore the community theme' OFF.
    const INSTALLATION_FILTER_THEME_ON = [
        '#^themes/(?!community-theme-default/|niara/).+/#',
        '#^themes/community-theme-default/cache/#',
        '#^themes/community-theme-default/lang/#',
        '#^themes/community-theme-default/mails/#',
        '#^themes/niara/cache/#',
        '#^themes/niara/lang/#',
        '#^themes/niara/mails/#',
    ];
    // This gets added with option 'Ignore the community theme' ON.
    const INSTALLATION_FILTER_THEME_OFF = [
        '#^themes/#',
    ];
    /**
     * These files are left untouched even if they come with one of the
     * releases. All these files shouldn't be distributed in this location, to
     * begin with, but copied there from install/ at installation time.
     */
    const KEEP_FILTER = [
        '#^img/favicon.ico$#',
        '#^img/favicon_[0-9]+$#',
        '#^img/logo.jpg$#',
        '#^img/logo_stores.png$#',
        '#^img/logo_invoice.jpg$#',
        '#^img/c/[0-9-]+_thumb.jpg$#',
        '#^img/s/[0-9]+.jpg$#',
        '#^img/t/[0-9]+.jpg$#',
        '#^img/cms/cms-img.jpg$#',
    ];

    /**
     * @var GitUpdate
     */
    private static $instance = null;

    /**
     * Here all the collected data about an update gets stored. It gets saved
     * on disk between invocations.
     *
     * @var array
     */
    protected $storage = [];
    /**
     * @var GuzzleHttp
     */
    protected $guzzle = null;

    /**
     * The signature prohibits instantiating a non-singleton class.
     *
     * @version 1.0.0 Initial version.
     */
    protected function __construct()
    {
        $cacheDir = dirname(static::STORAGE_PATH);
        if ( ! is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }

        if (is_readable(static::STORAGE_PATH)) {
            require static::STORAGE_PATH;
        }
    }

    /**
     * @version 1.0.0 Initial version.
     */
    public function __destruct()
    {
        /**
         * Save storage. File format allows to include/require the resulting
         * file.
         */
        file_put_contents(static::STORAGE_PATH, '<?php
            $this->storage = '.var_export($this->storage, true).';
        ?>');
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate(static::STORAGE_PATH);
        }
    }

    /**
     * @return bool True on success, false on failure.
     *
     * @version 1.0.0 Initial version.
     */
    public static function uninstall()
    {
        \Tools::deleteDirectory(dirname(static::STORAGE_PATH));

        return true;
    }

    /**
     * Returns object instance. A singleton instance is maintained to allow
     * re-use of network connections and similar stuff.
     *
     * @return GitUpdate Singleton instance of class GitUpdate.
     *
     * @version 1.0.0 Initial version.
     */
    public static function getInstance()
    {
        if ( ! static::$instance) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    /**
     * Get Guzzle instance. Same basic parameters for all usages.
     *
     * @param int $channel Channel to connect with.
     *
     * @return \GuzzleHttp\Client Singleton instance of class GuzzleHttp\Client.
     *
     * @version 1.0.0 Initial version.
     * @version 2.0.0 New parameter $channel.
     */
    protected function getGuzzle($channel)
    {
        static $openChannel = false;

        $channel = (int) $channel;
        $uri = dirname(MyController::CHANNELS[$channel]['apiUrl']).'/';
        if ( ! $this->guzzle || $channel !== $openChannel) {
            $this->guzzle = new \GuzzleHttp\Client([
                'base_uri'    => $uri,
                'verify'      => _PS_TOOL_DIR_.'cacert.pem',
                'timeout'     => 20,
            ]);
            $openChannel = $channel;
        }

        return $this->guzzle;
    }

    /**
     * Define versions to compare. Mandatory before starting a comparison.
     * The same definition is then used for a following update.
     *
     * Also take care of dumping old or outdated storage.
     *
     * @param string $channelTarget Channel for the version to compare against.
     * @param string $versionTarget Version to compare the installation against.
     *
     * @version 2.0.0 Initial version.
     */
    public static function setCompareVersions($channelTarget, $versionTarget)
    {
        $me = static::getInstance();
        $installedVersion = static::getInstalledVersion();
        $installedChannel = static::getInstallChannel($installedVersion);

        // Dump very old storage.
        if (file_exists(static::STORAGE_PATH)
            && time() - filemtime(static::STORAGE_PATH) > 86400
        ) {
            static::deleteStorage();
        }

        // Reset an invalid storage set.
        $compareChange = false;
        $ignoreTheme = \Configuration::getGlobalValue(
            'CORE_UPDATER_IGNORE_THEME'
        );
        if ( ! array_key_exists('channelOrigin', $me->storage)
            || $me->storage['channelOrigin'] !== $installedChannel
            || ! array_key_exists('versionOrigin', $me->storage)
            || $me->storage['versionOrigin'] !== $installedVersion
        ) {
            unset($me->storage['fileList-'.$installedVersion]);
            $compareChange = true;
        }
        if ( ! array_key_exists('channelTarget', $me->storage)
            || $me->storage['channelTarget'] !== $channelTarget
            || ! array_key_exists('versionTarget', $me->storage)
            || $me->storage['versionTarget'] !== $versionTarget
        ) {
            unset($me->storage['fileList-'.$versionTarget]);
            $compareChange = true;
        }
        if ( ! array_key_exists('ignoreTheme', $me->storage)
            || $me->storage['ignoreTheme'] !== $ignoreTheme
        ) {
            $compareChange = true;
        }

        if ($compareChange) {
            static::deleteStorage('newCompare');

            $me->storage['channelOrigin']  = $installedChannel;
            $me->storage['versionOrigin'] = $installedVersion;
            $me->storage['channelTarget']  = $channelTarget;
            $me->storage['versionTarget'] = $versionTarget;
            $me->storage['ignoreTheme']   = $ignoreTheme;
        }
    }

    /**
     * Do one step to build a comparison, starting with the first step.
     * Subsequent calls see results of the previous step and proceed with the
     * next one accordingly.
     *
     * @param array $messages Prepared array to append messages to. Format see
     *                        AdminCoreUpdaterController->ajaxProcess().
     *
     * @version 1.0.0 Initial version.
     * @version 2.0.0 Parameter $version removed, use setCompareVersions().
     * @throws PrestaShopException
     */
    public static function compareStep(&$messages)
    {
        $me = static::getInstance();

        if ( ! array_key_exists('channelOrigin', $me->storage)
            || ! array_key_exists('versionOrigin', $me->storage)
            || ! array_key_exists('channelTarget', $me->storage)
            || ! array_key_exists('versionTarget', $me->storage)
            || ! array_key_exists('ignoreTheme', $me->storage)) {
            $messages['informations'][] = $me->l('Crucial storage set missing, please report this on Github.');
            $messages['error'] = true;

            return;
        }

        $installedVersion = $me->storage['versionOrigin'];
        $version = $me->storage['versionTarget'];

        if ( ! array_key_exists('requirements', $me->storage)) {
            if (preg_match('/^[0-9\.]*$/', $version)) {
                // It's a stable version, requirement checks possible.
                $errors = Requirements::checkAllRequirements($version);
                if ( ! count($errors)) {
                    $me->storage['requirements'] = true;

                    $messages['informations'][] = sprintf($me->l('Requirements for core software version %s checked.'), $version);
                    $messages['done'] = false;
                } else {
                    $errorLines = sprintf($me->l('Requirements for core software version %s are not met:'), $version);
                    foreach ($errors as $message) {
                        $errorLines .= "\n - ".$message;
                    }
                    $messages['informations'][] = $errorLines;
                    $messages['error'] = true;
                }
            } else {
                // Not a stable version. Finding out the base version of this
                // would require to download install_version.php of that
                // unstable version, which is a bit too complex for the time
                // being. Bleeding edge is typically close to the currently
                // installed version.
                $me->storage['requirements'] = true;

                $messages['informations'][] = $me->l('Target version is not a stable version, requirement checks skipped.');
                $messages['done'] = false;
            }
        } elseif ( ! array_key_exists('fileList-'.$version, $me->storage)) {
            $downloadSuccess = $me->downloadFileList(
                (int) $me->storage['channelTarget'],
                $version
            );
            if ($downloadSuccess === true) {
                $messages['informations'][] =
                    sprintf($me->l('File list for version %s downloaded.'), $version)
                    .' '.sprintf($me->l('Found %d paths to consider.'), count($me->storage['fileList-'.$version]));
                $messages['done'] = false;
            } else {
                $messages['informations'][] = sprintf($me->l('Failed to download file list for version %s with error: %s'), $version, $downloadSuccess);
                $messages['error'] = true;
            }
        } elseif ( ! array_key_exists('fileList-'.$installedVersion, $me->storage)) {
            $downloadSuccess = $me->downloadFileList(
                (int) $me->storage['channelOrigin'],
                $installedVersion
            );
            if ($downloadSuccess === true) {
                $messages['informations'][] =
                    sprintf($me->l('File list for version %s downloaded.'), $installedVersion);
            } else {
                // The currently installed version may have disappeared from
                // the API server, e.g. if it was an issue-specific Bleeding
                // Edge version. That's disappointing, but no showstopper.
                $messages['informations'][] = sprintf($me->l('File list for version %s not available.'), $installedVersion);
            }
            $messages['done'] = false;
        } elseif ( ! array_key_exists('topLevel-'.$version, $me->storage)) {
            $me->extractTopLevelDirs($version);
            $me->storage['installationList'] = [];

            $messages['informations'][] = $me->l('Extracted top level directories.');
            $messages['done'] = false;
        } elseif (count($me->storage['topLevel-'.$version])) {
            $dir = array_pop($me->storage['topLevel-'.$version]);
            $me->searchInstallation($dir);

            $messages['informations'][] = sprintf($me->l('Searched installed files in %s/'), $dir);
            $messages['done'] = false;
        } elseif ( ! array_key_exists('distributionFileset', $me->storage)) {
            $me->addDistributionFileset();
            $me->storage['distributionFileset'] = true;

            $messages['informations'][] = $me->l('Added distribution fileset.');
            $messages['done'] = false;
        } elseif ( ! array_key_exists('changeset', $me->storage)) {
            $me->calculateChanges();
            $messages['changeset'] = $me->storage['changeset'];

            $messages['informations'][] = $me->l('Changeset calculated.');
            $messages['done'] = false;
        } elseif ( ! array_key_exists('incompatibleModules', $me->storage)) {
            $incompatibleModules
                = Retrocompatibility::getIncompatibleModules($version);
            $me->storage['incompatibleModules'] = $incompatibleModules;
            $messages['changeset']['incompatible'] = [];
            foreach ($incompatibleModules as $module) {
                // Store it the same way path lists get stored, to allow
                // re-use of JavaScript procedures.
                $messages['changeset']['incompatible'][$module] = false;
            }

            $messages['informations'][] = sprintf($me->l('Found %s installed modules incompatible with core software %s.'), count($incompatibleModules), $version);
            $messages['done'] = false;
        } else {
            $messages['informations'][] = $me->l('Done.');
            $messages['done'] = true;
        }
    }

    /**
     * Delete storage. Which means, the next compareStep() call starts over.
     *
     * @param bool|string $deleteType - 'newSession' for a new session.
     *                                - 'newCompare' for a new comparison.
     *                                - Any other value deletes all storage.
     *
     * @version 1.0.0 Initial version.
     * @version 2.0.0 $deleteType string rather than $fullDelete flag.
     */
    public static function deleteStorage($deleteType = false)
    {
        $me = static::getInstance();

        if ($deleteType === 'newSession' || $deleteType === 'newCompare') {
            if ($deleteType === 'newSession') {
                foreach (array_keys($me->storage) as $key) {
                    if (substr($key, 0, 9) === 'fileList-') {
                        unset($me->storage[$key]);
                    }
                }
            }

            foreach (array_keys($me->storage) as $key) {
                if (substr($key, 0, 9) === 'topLevel-') {
                    unset($me->storage[$key]);
                }
            }
            unset($me->storage['requirements']);
            unset($me->storage['installationList']);
            unset($me->storage['distributionFileset']);
            unset($me->storage['changeset']);
            unset($me->storage['incompatibleModules']);
        } else {
            $me->storage = [];
        }
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
     * Download a list of files for a given version from the API server and
     * store it in $this->storage['fileList-'.$version] as a proper PHP array.
     *
     * For efficiency (a response can easily contain 10,000 lines), the
     * returned array contains just one key-value pair for each entry, path
     * and Git (SHA1) hash: ['<path>' => '<hash>']. Permissions get ignored,
     * because all files should have 644 permissions.
     *
     * @param int    $channel Channel for this version.
     * @param string $version List for this version.
     *
     * @return bool|string True on success, or error message on failure.
     *
     * @version 1.0.0 Initial version.
     * @version 2.0.0 New parameter $channel.
     */
    protected function downloadFileList($channel, $version)
    {
        if ( ! is_integer($channel) || ! $version) {
            return $this->l('Channel and/or version not defined.');
        }

        $guzzle = $this->getGuzzle($channel);
        $response = false;
        $uri = basename(MyController::CHANNELS[$channel]['apiUrl']);
        try {
            $response = $guzzle->post($uri, [
                'form_params' => [
                    'listrev' => $version,
                ],
            ])->getBody();
        } catch (\Exception $e) {
            $this->storage['fileList-'.$version] = [];
            return trim($e->getMessage());
        }
        $response = json_decode($response);

        if ($this->storage['ignoreTheme']) {
            $releaseFilter = array_merge(static::RELEASE_FILTER,
                                         static::RELEASE_FILTER_THEME_OFF);
        } else {
            $releaseFilter = array_merge(static::RELEASE_FILTER,
                                         static::RELEASE_FILTER_THEME_ON);
        }

        $fileList = false;
        if (is_array($response)) {
            $fileList = [];

            $adminDir = false;
            if (defined('_PS_ADMIN_DIR_')) {
                $adminDir = str_replace(_PS_ROOT_DIR_, '', _PS_ADMIN_DIR_);
                $adminDir = str_replace('\\', '/', $adminDir); // Windows
                $adminDir = trim($adminDir, '/').'/';
            }

            foreach ($response as $line) {
                // An incoming line is like '<permissions> blob <sha1>\t<path>'.
                // Use explode limits, to allow spaces in the last field.
                $fields = explode(' ', $line, 3);
                $line = $fields[2];
                $fields = explode("\t", $line, 2);
                $path = $fields[1];
                $hash = $fields[0];

                $keep = true;
                foreach ($releaseFilter as $filter) {
                    if (preg_match($filter, $path)) {
                        $keep = false;
                        break;
                    }
                }
                if ($keep) {
                    foreach (static::KEEP_FILTER as $filter) {
                        if (preg_match($filter, $path)) {
                            $keep = false;
                            break;
                        }
                    }
                }

                if ($keep) {
                    if ($adminDir) {
                        $path = preg_replace('#^admin/#', $adminDir, $path);
                    }

                    $fileList[$path] = $hash;
                }
            }
        } else {
            return preg_replace('/^Error: /', '', $response);
        }
        if ($fileList === false) {
            return $this->l('Downloaded file list did not contain any file.');
        }

        $this->storage['fileList-'.$version] = $fileList;

        return true;
    }

    /**
     * Extract top level directories from one of the file path lists. Purpose
     * is to allow splitting searches through the entire installation into
     * smaller chunks. Always present is the root directory, '.'.
     *
     * On return, $this->storage['topLevel-'.$version] is set to the list of
     * paths. No failure expected.
     *
     * @param string $version Version of the file path list.
     *
     * @version 1.0.0 Initial version.
     */
    protected function extractTopLevelDirs($version)
    {
        $fileList = $this->storage['fileList-'.$version];

        $topLevelDirs = ['.'];
        foreach ($fileList as $path => $hash) {
            $slashPos = strpos($path, '/');

            // Ignore files at root level.
            if ($slashPos === false) {
                continue;
            }

            $topLevelDir = substr($path, 0, $slashPos);

            // vendor/ is huge, so take a second level.
            if ($topLevelDir === 'vendor') {
                $slashPos = strpos($path, '/', $slashPos + 1);
                if ($slashPos) {
                    $topLevelDir = substr($path, 0, $slashPos);
                }
            }

            if ( ! in_array($topLevelDir, $topLevelDirs)) {
                $topLevelDirs[] = $topLevelDir;
            }
        }

        $this->storage['topLevel-'.$version] = $topLevelDirs;
    }

    /**
     * Search installed files in a directory recursively and add them to
     * $this->storage['installationList'] together with their Git hashes.
     *
     * Directories '.' and 'vendor' get searched not recursively. Note that
     * subdirectories of 'vendor' get searched as well, recursively.
     *
     * No failure expected, a not existing directory doesn't add anything.
     *
     * @param string $dir Directory to search.
     *
     * @version 1.0.0 Initial version.
     * @version 1.1.0 Use filtered directory scanning.
     */
    protected function searchInstallation($dir)
    {
        $targetList = $this->storage['fileList-'.$this->storage['versionTarget']];
        $originList = $this->storage['fileList-'.$this->storage['versionOrigin']];

        $oldCwd = getcwd();
        chdir(_PS_ROOT_DIR_);

        if ($this->storage['ignoreTheme']) {
            $scanFilter = array_merge(static::INSTALLATION_FILTER,
                                      static::INSTALLATION_FILTER_THEME_OFF);
        } else {
            $scanFilter = array_merge(static::INSTALLATION_FILTER,
                                      static::INSTALLATION_FILTER_THEME_ON);
        }

        if (is_dir($dir)) {
            $recursive = true;
            if (in_array($dir, ['.', 'vendor'])) {
                $recursive = false;
            }

            // Scan files on disk.
            foreach ($this->scandir($dir, $recursive, $scanFilter) as $path) {
                if (array_key_exists($path, $targetList)
                    || array_key_exists($path, $originList)) {
                    $this->storage['installationList'][$path]
                        = static::getGitHash($path);
                } else {
                    // Pointless to calculate a hash.
                    $this->storage['installationList'][$path] = true;
                }
            }
        } // else ignore files at the root level.

        chdir($oldCwd);
    }

    /**
     * Add distribution files to the fileset of installed files. Only files
     * actually existing, of course.
     *
     * This is for those files which get filtered away by broad filters,
     * still should get considered. E.g. img/p/index.php, while files in
     * img/p/ get filtered.
     *
     * No failure expected, operations happen on existing data, only.
     *
     * @version 1.1.0 Initial version.
     */
    protected function addDistributionFileset()
    {
        $oldCwd = getcwd();
        chdir(_PS_ROOT_DIR_);

        foreach (array_merge(
            $this->storage['fileList-'.$this->storage['versionTarget']],
            $this->storage['fileList-'.$this->storage['versionOrigin']]
        ) as $path => $unused) {
            if (is_file($path)
                && ! array_key_exists($path, $this->storage['installationList'])
            ) {
                $this->storage['installationList'][$path]
                    = static::getGitHash($path);
            }
        }

        chdir($oldCwd);
    }

    /**
     * Calculate all the changes between the selected version and the current
     * installation.
     *
     * On return, $this->storage['changeset'] exists and contains an array of
     * the following format:
     *
     *               [
     *                   'change' => [
     *                       '<path>' => <manual>,
     *                       [...],
     *                   ],
     *                   'add' => [
     *                       '<path>' => <manual>,
     *                       [...],
     *                   ],
     *                   'remove' => [
     *                       '<path>' => <manual>,
     *                       [...],
     *                   ],
     *                   'obsolete' => [
     *                       '<path>' => <manual>,
     *                       [...],
     *                   ],
     *               ]
     *
     *               Where <path> is the path of the file and <manual> is a
     *               boolean indicating whether a change/add/remove overwrites
     *               manual edits.
     *
     * @version 1.0.0 Initial version.
     */
    protected function calculateChanges()
    {
        $targetList = $this->storage['fileList-'.$this->storage['versionTarget']];
        $installedList = $this->storage['installationList'];
        $originList = $this->storage['fileList-'.$this->storage['versionOrigin']];

        $changeList   = [];
        $addList      = [];
        $removeList   = [];
        $obsoleteList = [];

        foreach ($targetList as $path => $hash) {
            if (array_key_exists($path, $installedList)) {
                // Files to change are all files in the target version not
                // matching the installed file.
                if ($installedList[$path] !== $hash) {
                    $manual = false;
                    if (array_key_exists($path, $originList)
                        && $installedList[$path] !== $originList[$path]) {
                        $manual = true;
                    }
                    $changeList[$path] = $manual;
                } // else the file matches already.
            } else {
                // Files to add are all files in the target version not
                // existing locally.
                $addList[$path] = false;
            }
        }

        foreach ($installedList as $path => $hash) {
            if ( ! array_key_exists($path, $targetList)) {
                if (array_key_exists($path, $originList)) {
                    // Files to remove are all files not in the target version,
                    // but in the original version.
                    $manual = false;
                    if ($originList[$path] !== $hash) {
                        $manual = true;
                    }
                    $removeList[$path] = $manual;
                } elseif (
                    // Top level vendor files.
                    preg_match('#^vendor/#', $path)
                    // Core code files. No non-code files, e.g. 'Cart.php.org'.
                    || preg_match('#^Adapter/.*\.php$#', $path)
                    || preg_match('#^Core/.*\.php$#', $path)
                    || preg_match('#^classes/.*\.php$#', $path)
                    || preg_match('#^controllers/.*\.php$#', $path)
                ) {
                    // Always remove these files, they can be harmful. Neither
                    // modules nor hackers should put files there.
                    $removeList[$path] = true;
                } else {
                    // Obsolete files are all files existing locally, but
                    // neither in the target nor in the original version.
                    $obsoleteList[$path] = true;
                }
            } // else handled above already.
        }

        $this->storage['changeset'] = [
            'change'    => $changeList,
            'add'       => $addList,
            'remove'    => $removeList,
            'obsolete'  => $obsoleteList,
        ];
    }

    /**
     * Receive a list of obsolete files which should get removed during the
     * update. It's a subset of $me->storage['changeset']['obsolete'].
     *
     * @param array $list List of file paths.
     *
     * @version 1.0.0 Initial version.
     */
    public static function setSelectedObsolete($list)
    {
        $me = static::getInstance();

        $changeSet = &$me->storage['changeset'];
        $changeSet['selectedObsolete'] = [];
        foreach ($list as $path) {
            if (array_key_exists($path, $changeSet['obsolete'])) {
                $changeSet['selectedObsolete'][$path] = true;
            }
        }
    }

    /**
     * Do one update step, starting with the first step. Subsequent calls see
     * results of the previous step and proceed with the next one accordingly.
     *
     * @param array $messages Prepared array to append messages to. Format see
     *                        AdminCoreUpdaterController->ajaxProcess().
     *
     * @version 1.0.0 Initial version.
     * @version 2.0.0 Unused parameter $version removed.
     * @throws PrestaShopException
     */
    public static function updateStep(&$messages)
    {
        $me = static::getInstance();

        if ( ! array_key_exists('channelTarget', $me->storage)
            || ! array_key_exists('versionTarget', $me->storage)
            || ! array_key_exists('incompatibleModules', $me->storage)
            || ! array_key_exists('changeset', $me->storage)
            || ! array_key_exists('change', $me->storage['changeset'])
            || ! array_key_exists('add', $me->storage['changeset'])
            || ! array_key_exists('remove', $me->storage['changeset'])
            || ! array_key_exists('obsolete', $me->storage['changeset'])) {
            $messages['informations'][] = $me->l('Crucial storage set missing, please report this on Github.');
            $messages['error'] = true;

            return;
        }

        if (count($me->storage['incompatibleModules'])) {
            $module = array_pop($me->storage['incompatibleModules']);
            $errors = Retrocompatibility::removeModule($module);

            if ( ! count($errors)) {
                $messages['informations'][] = sprintf($me->l('Uninstalled and deleted module %s successfully.'), $module);
            } else {
                $errorLines = sprintf($me->l('Module removal failed, please fix this manually:'));
                foreach ($errors as $message) {
                    $errorLines .= "\n - ".$message;
                }
                $messages['informations'][] = $errorLines;
            }
            $messages['done'] = false;
        } elseif ( ! array_key_exists('downloads', $me->storage)) {
            $me->storage['downloads']
                = array_merge($me->storage['changeset']['change'],
                              $me->storage['changeset']['add']);
            $me->storage['downloads']['install/install_version.php'] = true;
            \Tools::deleteDirectory(static::DOWNLOADS_PATH);
            mkdir(static::DOWNLOADS_PATH, 0777, true);

            $messages['informations'][] = sprintf($me->l('Downloads calculated, %d files to download.'), count($me->storage['downloads']));
            $messages['done'] = false;
        } elseif (count($me->storage['downloads'])) {
            $downloadSuccess = $me->downloadFiles();
            if ($downloadSuccess === true) {
                $messages['informations'][] = sprintf($me->l('Downloaded a couple of files, %d files remaining.'), count($me->storage['downloads']));
                $messages['done'] = false;
            } else {
                $messages['informations'][] = sprintf($me->l('Failed to download files with error: %s'), $downloadSuccess);
                $messages['error'] = true;
            }
        } elseif ( ! array_key_exists('updateScript', $me->storage)) {
            $scriptSuccess = $me->createUpdateScript();
            if ($scriptSuccess === true) {
                // Indicate we have an update script, so the controller sends
                // messages before returning.
                $messages['updateScript'] = true;

                $messages['informations'][] = $me->l('Created update script.');
                if (array_key_exists('backupDir', $me->storage)) {
                    $messages['informations'][] = sprintf($me->l('Manually modified files, if any, get backed up to %s.'), $me->storage['backupDir']);
                }
                $messages['informations'][] = $me->l('Now running the update script...');
                $messages['done'] = false;
            } else {
                $messages['informations'][] = sprintf($me->l('Could not create update script, error: %s'), $scriptSuccess);
                $messages['error'] = true;
            }
        } elseif ( ! array_key_exists('updateScriptDone', $me->storage)) {
            if (file_exists(static::SCRIPT_PATH)) {
                // Usually one tries hard to not burn the house (set of code
                // files) a program is living in, but this is an exception.
                // The script overwrites a lot of code files, then dies.
                //
                // This does not return! It also self-removes the script file.
                require static::SCRIPT_PATH;
            }

            // From here on we run in the updated shop installation! Too bad,
            // we have no actual indication how successful the update script
            // executed. But the next version comparison will show the results.
            $me->storage['updateScriptDone'] = true;

            $messages['informations'][] = sprintf($me->l('Update script was executed. Welcome to core software version %s!'), $me->storage['versionTarget']);
            $messages['done'] = false;
        } elseif ( ! array_key_exists('aftermathDone', $me->storage)) {
            $aftermathSuccess = $me->doAftermath();
            if ($aftermathSuccess === true) {
                $me->storage['aftermathDone'] = true;

                $messages['informations'][] = $me->l('Aftermath done.');
                $messages['done'] = false;
            } else {
                $errorLines = $me->l('Aftermath failed, please fix this manually:');
                foreach ($aftermathSuccess as $message) {
                    $errorLines .= "\n - ".$message;
                }
                $messages['informations'][] = $errorLines;
                $messages['error'] = true;
            }
        } elseif ( ! array_key_exists('cachesCleared', $me->storage)) {
            $me->clearCaches();
            $me->storage['cachesCleared'] = true;

            $messages['informations'][] = $me->l('All caches cleared.');
            $messages['done'] = false;
        } elseif ( ! array_key_exists('databaseMigration', $me->storage)) {
            $me->storage['databaseMigration'] = true;
            if (class_exists('CoreModels')) {
                try {
                    $messages['informations'][] = static::migrateDatabase();
                } catch (\Exception $e) {
                    $messages['informations'][] = $me->l('Failed to migrate database');
                    $messages['informations'][] = sprintf($me->l('Error: %s'), $e->getMessage());
                    $messages['error'] = true;
                }
            } else {
                $messages['informations'][] = $me->l('Skipping database migration: this core software version does not support it');
            }
            $messages['done'] = false;
        } else {
            $messages['informations'][] = '...completed.';
            $messages['done'] = true;
        }
    }

    /**
     * Download a couple of files from the Git repository on the api server and
     * save them in the cache directory.
     *
     * $this->storage['versionTarget'] and $this->storage['downloads'] are
     * expected to be valid.
     *
     * On return, successfully downloaded files are removed from
     * $this->storage['downloads'].
     *
     * @return bool|string Boolean true on success, error message on failure.
     *
     * @version 1.0.0 Initial version.
     */
    protected function downloadFiles()
    {
        $adminDir = false;
        if (defined('_PS_ADMIN_DIR_')) {
            $adminDir = str_replace(_PS_ROOT_DIR_, '', _PS_ADMIN_DIR_);
            $adminDir = str_replace('\\', '/', $adminDir); // Windows
            $adminDir = trim($adminDir, '/').'/';
            $pregAdminDir = preg_quote($adminDir);
        }

        $pathList = array_slice(array_keys($this->storage['downloads']), 0, 100);
        foreach ($pathList as &$path) {
            if ($adminDir) {
                $path = preg_replace('#^'.$pregAdminDir.'#', 'admin/', $path);
            }
        }

        $downloadCountBefore = count($this->storage['downloads']);
        $archiveFile = tempnam(_PS_CACHE_DIR_, 'GitUpdate');
        if ( ! $archiveFile) {
            return $this->l('Could not create temporary file for download.');
        }

        $channel = (int) $this->storage['channelTarget'];
        $guzzle = $this->getGuzzle($channel);
        $uri = basename(MyController::CHANNELS[$channel]['apiUrl']);
        try {
            $response = $guzzle->post($uri, [
                'form_params' => [
                    'revision'  => $this->storage['versionTarget'],
                    'archive'   => $pathList,
                ],
                'sink'        => $archiveFile,
            ]);
        } catch (\Exception $e) {
            unlink($archiveFile);

            return trim($e->getMessage());
        }
        $magicNumber = file_get_contents($archiveFile, false, null, 0, 2);
        if (filesize($archiveFile) < 100 || strcmp($magicNumber, "\x1f\x8b")) {
            // It's an error message response.
            $message = file_get_contents($archiveFile);
            unlink($archiveFile);

            return $message;
        }

        $archive = new \Archive_Tar($archiveFile, 'gz');
        $archivePaths = $archive->listContent();
        if ($archive->error_object) {
            unlink($archiveFile);

            return 'Archive_Tar: '.$archive->error_object->message;
        }

        $archive->extract(static::DOWNLOADS_PATH);
        if ($archive->error_object) {
            unlink($archiveFile);

            return 'Archive_Tar: '.$archive->error_object->message;
        }

        // Verify whether each downloaded file matches the expected Git hash
        // and if so, remove if from the list of files to download. Delete
        // files on disk not matching (there should be none).
        $fileListName = 'fileList-'.$this->storage['versionTarget'];
        foreach ($archivePaths as $path) {
            if ($path['typeflag'] == 0) {
                $path = $path['filename'];

                $finalPath = $path;
                if ($adminDir) {
                    $finalPath = preg_replace('#^admin/#', $adminDir, $path);
                }

                if ($path === 'install/install_version.php'
                    || static::getGitHash(static::DOWNLOADS_PATH.'/'.$path)
                       === $this->storage[$fileListName][$finalPath]) {
                    unset($this->storage['downloads'][$finalPath]);
                } else {
                    unlink(static::DOWNLOADS_PATH.'/'.$path);
                }
            }
        }

        // With all files downloaded, also rename admin/ on disk.
        $success = true;
        $from = static::DOWNLOADS_PATH.'/admin';
        if ( ! count($this->storage['downloads'])
            && $adminDir && is_dir($from)) {
            $to = static::DOWNLOADS_PATH.'/'.trim($adminDir, '/');

            $success = rename($from, $to);
            if ( ! $success) {
                $success = sprintf($this->l('Could not rename %s to %s.'), $from, $to);
            }
        }

        if ($success === true
            && $downloadCountBefore === count($this->storage['downloads'])) {
            $success = $this->l('Downloaded files successfully, but found no valid files in there.');
        }

        unlink($archiveFile);

        return $success;
    }

    /**
     * Create an update script which updates the shop installation
     * independently. During runtime of this script, the shop installation
     * has to be assumed to be broken, so it may call only bare PHP functions.
     * After creation, this script will be called from JavaScript directly.
     *
     * $this->storage['versionTarget'] and $this->storage['changeset'] are
     * expected to be valid.
     *
     * On return, $this->storage['updateScript'] is set to true.
     *
     * @return bool|string Boolean true on success, error message on failure.
     *
     * @version 1.0.0 Initial version.
     */
    protected function createUpdateScript()
    {
        $script = "<?php\n\n";
        $renameFormat = '@rename(\'%s\', \'%s\');'."\n";
        $removeFormat = '@unlink(\'%s\');'."\n";
        $createDirFormat = '@mkdir(\'%s\', 0777, true);'."\n";
        $removeDirFormat = '@rmdir(\'%s\');'."\n";

        $backupPaths = array_merge($this->storage['changeset']['change'],
                                   $this->storage['changeset']['remove']);
        $backupDir = MyController::BACKUP_PATH
                     .date(MyController::BACKUP_DATE_SUFFIX);
        foreach ($backupPaths as $path => $manual) {
            if ($manual) {
                $script .= sprintf($createDirFormat,
                                   dirname($backupDir.'/'.$path));
                $script .= sprintf($renameFormat,
                                   _PS_ROOT_DIR_.'/'.$path,
                                   $backupDir.'/'.$path);
            }
        }
        // Reformat for display.
        $backupDir = preg_replace('#^'._PS_ROOT_DIR_.'#', '', $backupDir);
        $backupDir = trim($backupDir, '/').'/';
        $this->storage['backupDir'] = $backupDir;

        $movePaths = array_merge($this->storage['changeset']['change'],
                                 $this->storage['changeset']['add']);
        foreach ($movePaths as $path => $manual) {
            $script .= sprintf($createDirFormat,
                               dirname(_PS_ROOT_DIR_.'/'.$path));
            $script .= sprintf($renameFormat,
                               static::DOWNLOADS_PATH.'/'.$path,
                               _PS_ROOT_DIR_.'/'.$path);
        }

        $removePaths = $this->storage['changeset']['remove'];
        if (array_key_exists('selectedObsolete', $this->storage['changeset'])) {
            $removePaths = array_merge($removePaths,
                              $this->storage['changeset']['selectedObsolete']);
        }
        foreach ($removePaths as $path => $manual) {
            $script .= sprintf($removeFormat, _PS_ROOT_DIR_.'/'.$path);

            // Remove containing folder. Fails silently if not empty.
            foreach ([1, 2, 3, 4, 5] as $dummy) {
                $path = dirname($path);
                if ($path === '.') {
                    break;
                }

                $script .= sprintf($removeDirFormat, _PS_ROOT_DIR_.'/'.$path);
            }
        }

        $script .= sprintf(
            $removeFormat,
            _PS_ROOT_DIR_.'/'.\PrestaShopAutoload::INDEX_FILE
        );
        $script .= 'if (function_exists(\'opcache_reset\')) {'."\n";
        $script .= '    opcache_reset();'."\n";
        $script .= '}'."\n";
        // Let the script file remove its self to indicate the execution and
        // avoid multiple execution.
        $script .= sprintf($removeFormat, static::SCRIPT_PATH);
        // Die with a minimum response.
        $script .= 'die(\'{"informations":[],"error":false,"done":false}\');'."\n";

        $success = (bool) file_put_contents(static::SCRIPT_PATH, $script);
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate(static::SCRIPT_PATH);
        }
        $this->storage['updateScript'] = true;

        return $success;
    }

    /**
     * Do things needed to be done after all files were updated.
     *
     * $this->storage['versionTarget'] is expected to be valid.
     *
     * @return bool|array Boolean true on success, array with error messages
     *                    on failure. Array, because aftermath continues to
     *                    operate after partial failures, so multiple error
     *                    messages can appear.
     *
     * @version 1.0.0 Initial version.
     */
    protected function doAftermath()
    {
        $errors = [];

        /**
         * Remember the used channel. Details see compareStep().
         */
        $channel = \Tools::getValue('CORE_UPDATER_CHANNEL');
        if ($channel !== false) {
            \Configuration::updateGlobalValue(
                'CORE_UPDATER_INSTALLCHANNEL',
                $channel
            );
        }

        /**
         * Update shop installation version.
         *
         * For updates to branches or Git hashes, join install version and
         * target version, e.g. '1.0.8-1.0.x' or '1.0.8-testbranch'. This
         * should be right for code comparing the version, e.g.
         * version_compare(_TB_VERSION_, '1.0.8', '>=');
         */
        $versionPath = static::DOWNLOADS_PATH.'/install/install_version.php';
        if (is_readable($versionPath)) {
            include $versionPath;
        }
        if ( ! defined('_TB_INSTALL_VERSION_')) {
            $errors[] = $this->l('No definition for _TB_INSTALL_VERSION_ found.');
        }
        $newVersion = _TB_INSTALL_VERSION_;
        if ($this->storage['versionTarget'] !== _TB_INSTALL_VERSION_) {
            $newVersion = _TB_INSTALL_VERSION_
                          .'-'.$this->storage['versionTarget'];
        }

        // Should exist, else the shop wouldn't run.
        $settingsPath = _PS_CONFIG_DIR_.'settings.inc.php';
        $settings = file_get_contents($settingsPath);
        $settings = preg_replace('/define\s*\(\s*\'_TB_VERSION_\'\s*,\s*\'[\w.-]+\'\s*\)/',
                                 'define(\'_TB_VERSION_\', \''.$newVersion.'\')',
                                 $settings);
        copy($settingsPath, _PS_ROOT_DIR_.'/config/settings.old.php');
        $result = file_put_contents($settingsPath, $settings);
        if ( ! $result) {
            $errors[] = sprintf($this->l('Could not write new version "%s" into %s.'), $newVersion, $settingsPath);
        }

        /**
         * Delete residuals. Failures don't matter much.
         */
        \Tools::deleteDirectory(static::DOWNLOADS_PATH);

        /**
         * Apply retrocompatibility database upgrades.
         */
        $errors = array_merge($errors,
                              Retrocompatibility::doAllDatabaseUpgrades());

        /**
         * When upgrading from tb or an early version of Merchant's Edition to
         * Merchant's Edition with the new back office style, give all
         * employees this brilliant new style.
         *
         * This is no problem for rollbacks, as back office falls back to the
         * blank bootstrap style in case the database points to a not existing
         * style.
         */
        $origin = MyController::CHANNELS[$this->storage['channelOrigin']]['name'];
        $target = MyController::CHANNELS[$this->storage['channelTarget']]['name'];
        if ((
                preg_match('/^thirty/', $origin)
                || $this->storage['versionOrigin'] === '1.9.0'
                || $this->storage['versionOrigin'] === '1.9.1'
            )
            && preg_match('/^Merchant/', $target)
            && version_compare($this->storage['versionTarget'], '1.9.2', '>=')
        ) {
            $db = \Db::getInstance();
            $db->execute('UPDATE `'._DB_PREFIX_.'employee`
                SET `bo_color` = NULL,
                    `bo_theme` = "default",
                    `bo_css` = "schemes/admin-theme-merchantsedition.css"
                WHERE `bo_css` NOT LIKE "%_rtl.css"', false);
            $db->execute('UPDATE `'._DB_PREFIX_.'employee`
                SET `bo_color` = NULL,
                    `bo_theme` = "default",
                    `bo_css` = "schemes_rtl/admin-theme-merchantsedition_rtl.css"
                WHERE `bo_css` LIKE "%_rtl.css"', false);
        }

        return count($errors) ? $errors : true;
    }

    /**
     * Clear all the caches. Luckily, this doesn't delete our own storage.
     * No failure expected.
     *
     * @version 1.0.0 Initial version.
     * @throws PrestaShopException
     */
    protected function clearCaches()
    {
        \Tools::clearSmartyCache();
        \Tools::clearXMLCache();
        \Media::clearCache();
        \Tools::generateIndex();
        \PageCache::flush();
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    /**
     * Resolve currently installed version. To make functions like
     * version_compare() work, bleeding edge versions set _TB_VERSION_ to
     * something like 1.0.8-1.0.x, where the part before the dash is the
     * version found in install-dev/install_version.php and the part behind
     * the dash is the name of the Git branch (or the Git hash, when this
     * becomes supported). See also doAftermath().
     *
     * @return string Version.
     *
     * @version 1.0.0 Initial version.
     */
    public static function getInstalledVersion()
    {
        $version = _TB_VERSION_;

        // A dash indicates a non-stable version.
        $dashPosition = strpos($version, '-');
        if ($dashPosition !== false) {
            $version = substr($version, $dashPosition + 1);
            // A Git-hash-version has exactly 40 characters of [0-9][a-f],
            // everything else should be a branch name.
        }

        return $version;
    }

    /**
     * Resolve the channel matching the installed version.
     *
     * @return int Channel.
     *
     * @version 2.0.0 Initial version.
     */
    public static function getInstallChannel($installedVersion)
    {
        /**
         * This is the equivalent to _TB_VERSION_, stored in
         * config/settings.inc.php, but for the channel from where the update
         * was fetched from. Storing the channel is required, because a given
         * version has no fixed relationship to a certain channel.
         *
         * Knowing the channel is important, as it gives e.g. the API URL
         * needed for fetching original hashes of installed files. It's not
         * fatal to not have it, though.
         */
        $installedChannel = \Configuration::getGlobalValue(
            'CORE_UPDATER_INSTALLCHANNEL'
        );
        if ($installedChannel === false
            || ! array_key_exists($installedChannel, MyController::CHANNELS)
        ) {
            // Guess it and hope for the best!
            if (version_compare($installedVersion, '1.9.0', '>=')) {
                $installedChannel = 0; // Merchant's Edition Stable
            } else {
                $installedChannel = 2; // thirty bees Stable
            }
        }

        return $installedChannel;
    }

    /**
     * Return the directory used for backing up manually edited files.
     *
     * @return bool|string Directory path if it's determined (yet), else false.
     *
     * @version 1.0.0 Initial version.
     */
    public static function getBackupDir()
    {
        $me = static::getInstance();

        if (array_key_exists('backupDir', $me->storage)) {
            return $me->storage['backupDir'];
        }

        return false;
    }

    /**
     * Calculate Git hash of a file on disk.
     *
     * Works for files with a size of up to half of available memory, only.
     * Which should be plenty for distribution files. Biggest file distributed
     * with v1.0.8 was 1835770 bytes (1.75 MiB).
     *
     * @param string $path Path of the file.
     *
     * @return bool|string Hash, or boolean false on expected memory exhaustion.
     *
     * @version 1.0.0 Initial version.
     * @version 1.0.1 Predict memory exhaustion.
     */
    public static function getGitHash($path)
    {
        static $memoryLimit = false;
        if ( ! $memoryLimit) {
            $memoryLimit = \Tools::getMemoryLimit();
        }
        // Retrocompatibility with thirty bees <= 1.1.0.
        if ((int) $memoryLimit <= 0) {
            $memoryLimit = PHP_INT_MAX;
        }

        // Predict memory exhaution.
        // 2x file size + already used memory + 1 MiB was tested to be safe.
        if (filesize($path) * 2 + memory_get_usage() + 1048576 > $memoryLimit) {
            return false;
        }

        $content = file_get_contents($path);

        return sha1('blob '.strlen($content)."\0".$content);
    }

    /**
     * Scan a directory filtered. Applying the filter immediately avoids
     * diving into directories which get filtered away anyways. This enhances
     * performance a lot, e.g. on large product image directories.
     *
     * @param string $dir       Full path of the directory to scan.
     * @param bool   $recursive Wether to scan the directory recursively.
     * @param array  $filter    List of regular expressions to filter against.
     *                          Paths matching one of these won't get listed.
     *
     * @return array List of paths of files found, without directories.
     *
     * @version 1.1.0 Initial version.
     */
    protected function scandir($dir, $recursive = false, $filter = [])
    {
        $pathList = [];

        foreach (scandir($dir) as $file) {
            if (in_array($file, ['.', '..'])) {
                continue;
            }

            $path = rtrim($dir, '/').'/'.$file;
            // Strip leading './'.
            $path = preg_replace('#^\./#', '', $path);

            $keep = true;
            foreach ($filter as $regexp) {
                if (preg_match($regexp, $path, $matches)) {
                    $keep = false;
                    break;
                }
            }
            if ( ! $keep) {
                continue;
            }

            if (is_dir($path)) {
                if ($recursive) {
                    $pathList = array_merge(
                        $pathList,
                        $this->scandir($path, $recursive, $filter)
                    );
                }
            } else {
                $pathList[] = $path;
            }
        }

        return $pathList;
    }

    /**
     * @return string
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * @throws ReflectionException
     */
    protected static function migrateDatabase()
    {
        $me = static::getInstance();
        require_once(__DIR__ . '/schema/autoload.php');
        $objectModelBuilder = new ObjectModelSchemaBuilder();
        $informationSchemaBuilder = new InformationSchemaBuilder();
        $comparator = new DatabaseSchemaComparator();
        $differences = $comparator->getDifferences($informationSchemaBuilder->getSchema(), $objectModelBuilder->getSchema());
        $differences = array_filter($differences, function(SchemaDifference $difference) {
            // At the moment we automatically fix only MissingColumn and MissingTable differences. These are the most
            // important ones - system won't work correctly without it. Also, adding these database objects to database
            // does not pose any threat or issues.
            // In the future, we will probably allow all safe differences to be automatically fixed
            return (
                ($difference instanceof MissingColumn) ||
                ($difference instanceof MissingTable)
            );
        });
        if ($differences) {
            foreach ($differences as $difference) {
                static::applyDatabaseFix($difference);
            }
            return sprintf($me->l('Database successfully migrated, %s fixes applied'), count($differences));
        }
        return $me->l('No database difference found');
    }

    /**
     * @param SchemaDifference $difference
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected static function applyDatabaseFix(SchemaDifference $difference)
    {
        foreach (static::getDBServers() as $server) {
            // we need to create connection from scratch, because DB::getInstance() doesn't provide mechanism to
            // retrieve connection to specific slave server
            $connection = new DbPDO($server['server'], $server['user'], $server['password'], $server['database']);
            $difference->applyFix($connection);
        }
    }

    /**
     * Returns list of all database servers (both master and slaves)
     *
     * @return array
     */
    protected static function getDBServers()
    {
        // ensure slave server settings are loaded
        Db::getInstance(_PS_USE_SQL_SLAVE_);
        return Db::$_servers;
    }
}
