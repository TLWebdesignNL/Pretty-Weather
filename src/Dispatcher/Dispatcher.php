<?php

/**
 * @package     TLWeb.Module
 * @subpackage  mod_prettyweather
 *
 * @copyright   Copyright (C) 2024 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace TLWeb\Module\Prettyweather\Site\Dispatcher;

use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\CMS\Helper\HelperFactoryAwareInterface;
use Joomla\CMS\Helper\HelperFactoryAwareTrait;

\defined('_JEXEC') or die;

/**
 * Dispatcher class for mod_prettyweather
 *
 * @since  1.0.0
 */
class Dispatcher extends AbstractModuleDispatcher implements HelperFactoryAwareInterface
{
    use HelperFactoryAwareTrait;

    /**
     * Returns the layout data.
     *
     * @return  array
     *
     * @since   1.0.0
     */
    protected function getLayoutData(): array
    {
        $data = parent::getLayoutData();

        $data['debug'] = false;

        // A weather module must never take the page down: fall back to an
        // empty module on any failure in the helper.
        try {
            $helper = $this->getHelperFactory()->getHelper('PrettyweatherHelper');

            $data['weatherData'] = $helper->getWeather($data['module']);
            $data['display']     = $helper->displayContent($data['module'], $data['weatherData']);
            $data['debug']       = (bool) $data['params']->get('debug') && $helper->canViewDebug();
        } catch (\Throwable $e) {
            $data['weatherData'] = false;
            $data['display']     = null;

            if (isset($helper)) {
                $helper->logException($e, (string) $data['params']->get('apikey', ''));
            }
        }

        return $data;
    }
}