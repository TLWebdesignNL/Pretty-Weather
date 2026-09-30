<?php

/**
 * @package     TLWeb.Module
 * @subpackage  mod_prettyweather
 *
 * @copyright   Copyright (C) 2024 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Filesystem\Folder;

/**
 * Installer script for mod_prettyweather.
 *
 * @since  1.2.0
 */
return new class () implements InstallerScriptInterface {
    /**
     * Minimum Joomla version required to install the module.
     *
     * @var    string
     * @since  1.2.0
     */
    private string $minimumJoomla = '5.2';

    /**
     * Site folder holding the runtime weather cache.
     *
     * @var    string
     * @since  1.2.0
     */
    private const CACHE_FOLDER = '/cache/mod_prettyweather';

    /**
     * Runs after a fresh install.
     *
     * @param   InstallerAdapter  $adapter  The adapter calling this method.
     *
     * @return  boolean  True on success.
     *
     * @since   1.2.0
     */
    public function install(InstallerAdapter $adapter): bool
    {
        $this->message('MOD_PRETTYWEATHER_INSTALLERSCRIPT_INSTALL');

        return true;
    }

    /**
     * Runs after an update.
     *
     * @param   InstallerAdapter  $adapter  The adapter calling this method.
     *
     * @return  boolean  True on success.
     *
     * @since   1.2.0
     */
    public function update(InstallerAdapter $adapter): bool
    {
        // Before 1.2.0 weather data was cached in the public media folder.
        $this->removeLegacyCacheFiles();

        $this->message('MOD_PRETTYWEATHER_INSTALLERSCRIPT_UPDATE');

        return true;
    }

    /**
     * Runs on uninstall and removes all runtime data.
     *
     * @param   InstallerAdapter  $adapter  The adapter calling this method.
     *
     * @return  boolean  True on success.
     *
     * @since   1.2.0
     */
    public function uninstall(InstallerAdapter $adapter): bool
    {
        $this->removeLegacyCacheFiles();

        // Cleanup is best effort: a leftover cache folder must not block uninstalling.
        try {
            if (is_dir(JPATH_SITE . self::CACHE_FOLDER)) {
                Folder::delete(JPATH_SITE . self::CACHE_FOLDER);
            }
        } catch (\Throwable) {
        }

        $this->message('MOD_PRETTYWEATHER_INSTALLERSCRIPT_UNINSTALL');

        return true;
    }

    /**
     * Checks the minimum PHP and Joomla versions before installing or updating.
     *
     * @param   string            $type     The type of change (install, update, discover_install or uninstall).
     * @param   InstallerAdapter  $adapter  The adapter calling this method.
     *
     * @return  boolean  True when the requirements are met.
     *
     * @since   1.2.0
     */
    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'uninstall') {
            return true;
        }

        if (version_compare(PHP_VERSION, JOOMLA_MINIMUM_PHP, '<')) {
            Log::add(Text::sprintf('JLIB_INSTALLER_MINIMUM_PHP', JOOMLA_MINIMUM_PHP), Log::WARNING, 'jerror');

            return false;
        }

        if (version_compare(JVERSION, $this->minimumJoomla, '<')) {
            Log::add(Text::sprintf('JLIB_INSTALLER_MINIMUM_JOOMLA', $this->minimumJoomla), Log::WARNING, 'jerror');

            return false;
        }

        return true;
    }

    /**
     * Runs after install, update or discover install.
     *
     * @param   string            $type     The type of change (install, update or discover_install).
     * @param   InstallerAdapter  $adapter  The adapter calling this method.
     *
     * @return  boolean  True on success.
     *
     * @since   1.2.0
     */
    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        return true;
    }

    /**
     * Echo a translated installer message, skipping empty strings.
     *
     * @param   string  $key  Language key.
     *
     * @return  void
     *
     * @since   1.2.0
     */
    private function message(string $key): void
    {
        $text = Text::_($key);

        if ($text !== '' && $text !== $key) {
            echo $text;
        }
    }

    /**
     * Remove weather data cached by versions before 1.2.0 in the media folder.
     *
     * @return  void
     *
     * @since   1.2.0
     */
    private function removeLegacyCacheFiles(): void
    {
        foreach (glob(JPATH_ROOT . '/media/mod_prettyweather/data-*.json') ?: [] as $file) {
            @unlink($file);
        }
    }
};
