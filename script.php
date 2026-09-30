<?php

/**
 * @package     TLWeb.Module
 * @subpackage  mod_prettyweather
 *
 * @copyright   Copyright (C) 2024 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

// No direct access to this file
\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Filesystem\Folder;

/**
 * Script file of Prettyweather module
 */
class mod_prettyweatherInstallerScript
{
    /**
     * Extension script constructor.
     *
     * @return  void
     */
    public function __construct()
    {
        $this->minimumJoomla = '5.2';
        $this->minimumPhp    = JOOMLA_MINIMUM_PHP;
    }

    /**
     * Method to install the extension
     *
     * @param   InstallerAdapter  $parent  The class calling this method
     *
     * @return  boolean  True on success
     */
    function install($parent)
    {
        echo Text::_('MOD_PRETTYWEATHER_INSTALLERSCRIPT_INSTALL');

        return true;
    }

    /**
     * Method to uninstall the extension
     *
     * @param   InstallerAdapter  $parent  The class calling this method
     *
     * @return  boolean  True on success
     */
    function uninstall($parent)
    {
        $this->removeLegacyCacheFiles();

        // Cleanup is best effort: a leftover cache folder must not block uninstalling.
        try {
            if (is_dir(JPATH_SITE . '/cache/mod_prettyweather')) {
                Folder::delete(JPATH_SITE . '/cache/mod_prettyweather');
            }
        } catch (\Throwable) {
        }

        echo Text::_('MOD_PRETTYWEATHER_INSTALLERSCRIPT_UNINSTALL');

        return true;
    }

    /**
     * Method to update the extension
     *
     * @param   InstallerAdapter  $parent  The class calling this method
     *
     * @return  boolean  True on success
     */
    function update($parent)
    {
        // Before 1.2.0 weather data was cached in the public media folder.
        $this->removeLegacyCacheFiles();

        echo Text::_('MOD_PRETTYWEATHER_INSTALLERSCRIPT_UPDATE');

        return true;
    }

    /**
     * Function called before extension installation/update/removal procedure commences
     *
     * @param   string            $type    The type of change (install, update or discover_install, not uninstall)
     * @param   InstallerAdapter  $parent  The class calling this method
     *
     * @return  boolean  True on success
     */
    function preflight($type, $parent)
    {
        // Check for the minimum PHP version before continuing
        if (!empty($this->minimumPhp) && version_compare(PHP_VERSION, $this->minimumPhp, '<')) {
            Log::add(Text::sprintf('JLIB_INSTALLER_MINIMUM_PHP', $this->minimumPhp), Log::WARNING, 'jerror');

            return false;
        }

        // Check for the minimum Joomla version before continuing
        if (!empty($this->minimumJoomla) && version_compare(JVERSION, $this->minimumJoomla, '<')) {
            Log::add(Text::sprintf('JLIB_INSTALLER_MINIMUM_JOOMLA', $this->minimumJoomla), Log::WARNING, 'jerror');

            return false;
        }

        echo Text::_('MOD_PRETTYWEATHER_INSTALLERSCRIPT_PREFLIGHT');

        return true;
    }

    /**
     * Function called after extension installation/update/removal procedure commences
     *
     * @param   string            $type    The type of change (install, update or discover_install, not uninstall)
     * @param   InstallerAdapter  $parent  The class calling this method
     *
     * @return  boolean  True on success
     */
    function postflight($type, $parent)
    {
        echo Text::_('MOD_PRETTYWEATHER_INSTALLERSCRIPT_POSTFLIGHT');

        return true;
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
}
