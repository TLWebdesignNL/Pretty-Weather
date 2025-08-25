<?php

/**
 * @package     TLWeb.Module
 * @subpackage  mod_prettyweather
 *
 * @copyright   Copyright (C) 2024 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace TLWeb\Module\Prettyweather\Site\Helper;

use Joomla\CMS\Factory;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\Filesystem\File;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Helper\ModuleHelper;
use Exception;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Http\HttpFactory;


\defined('_JEXEC') or die;

/**
 * Helper for mod_prettyweather
 *
 * @since  V1.0.0
 */
class PrettyweatherHelper
{
    /**
     * Retrieve Google Reviews and update JSON file
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function getWeather(): bool
    {

        $input = Factory::getApplication()->input;

	    // Get the module
        $moduleId = $input->getString('moduleId');
	    $module   = ModuleHelper::getModuleById($moduleId);

	    // Check if the module is 'mod_prettyweather'
	    if ($module && $module->module === 'mod_prettyweather')
	    {
		    // Decode module params
		    $params = json_decode($module->params, true);

		    // Extract required parameters
		    $provider = $params['provider'] ?? null;
		    $apiKey = $params['apikey'] ?? null;

		    // Validate parameters
		    if (empty($provider) || empty($apiKey))
		    {
			    throw new \Exception(Text::_('No Provider and/or API Key set'), 404);
		    }
	    } else {
		    throw new \Exception(Text::_('Module not Found'), 404);
	    }

	    // Get existing reviews from JSON file
	    $data = $this->getJsonFile(JPATH_ROOT . '/media/mod_prettyweather/data-' . $moduleId . '.json');
	    $pauseTimeSeconds = (!empty($params['pausetime']) ? (int) $params['pausetime'] : 10) * 60;
		if ($data['dt'] < (time() - $pauseTimeSeconds))
		{
			// fetch weaterData if update is needed.
			$weatherData = $this->getWeatherData($provider, $apiKey, $params);

			if ($weatherData)
			{
				$this->saveJsonFile($weatherData, JPATH_ROOT . '/media/mod_prettyweather/data-' . $moduleId . '.json');

				return $weatherData;
			}
		}

        // Save and return outcome (bool)
        return $data;
    }

	/**
	 * Get JSON file contents
	 *
	 * @return array|null
	 *
	 * @since 1.0.0
	 */
	public function getJsonFile($jsonFilePath): ?array
	{
		if (File::exists($jsonFilePath)) {
			$jsonContents = file_get_contents($jsonFilePath);
			return json_decode($jsonContents, true);
		}

		return null;
	}

	/**
	 * Save data to JSON file
	 *
	 * @param array $data
	 * @return bool
	 *
	 * @since 1.0.0
	 */
	public function saveJsonFile(array $data, $jsonFilePath): bool
	{
		$jsonContents = json_encode($data);

		// Save the JSON data to the file
		return File::write($jsonFilePath, $jsonContents);
	}

	public function getWeatherData($provider, $apiKey, array $params)
	{
	    try {
	        // Extract required params
	        $lat   = isset($params['latitude']) ? (float) $params['latitude'] : null;
	        $lon   = isset($params['longitude']) ? (float) $params['longitude'] : null;
	        $units = !empty($params['units']) ? (string) $params['units'] : 'metric';

	        if ($lat === null || $lon === null || empty($apiKey)) {
	            return false;
	        }

	        // Only OpenWeatherMap supported for now
	        if (!empty($provider) && strtolower((string) $provider) !== 'openweathermap') {
	            return false;
	        }

	        // Build request URL
	        $base = 'https://api.openweathermap.org/data/2.5/weather';
	        $query = [
	            'lat'      => $lat,
	            'lon'      => $lon,
	            'exclude'  => '',
	            'appid'    => $apiKey,
	            'units'    => $units,
	        ];

	        $uri = new Uri($base);
	        $uri->setQuery($query);

	        // Perform GET using Joomla HTTP client
	        $http = HttpFactory::getHttp();
	        $response = $http->get((string) $uri);

	        if ((int) ($response->code ?? 0) !== 200) {
	            return false;
	        }

	        $data = json_decode($response->body ?? '', true);
	        if (!is_array($data)) {
	            return false;
	        }

	        // Validate essential fields
	        $hasRequired = isset($data['name'])
	            && isset($data['main'])
	            && is_array($data['main'])
	            && array_key_exists('temp', $data['main'])
	            && array_key_exists('feels_like', $data['main'])
	            && array_key_exists('temp_min', $data['main'])
	            && array_key_exists('temp_max', $data['main']);

	        if (!$hasRequired) {
	            return false;
	        }

	        return $data;
	    } catch (\Throwable $e) {
	        return false;
	    }
	}
	public function displayContent($data)
    {
        if (empty($data) || !is_array($data)) {
            return false;
        }

        $app = Factory::getApplication();
        $input = $app->input;
        $moduleId = $input->getString('moduleId');
        $module = ModuleHelper::getModuleById($moduleId);
        if (!$module || $module->module !== 'mod_prettyweather') {
            return false;
        }

        $params = json_decode($module->params, true) ?: [];
        $blocks = $params['conditional_content'] ?? [];
        if (!is_array($blocks) || !$blocks) {
            return false;
        }

        $out = [];

        foreach ($blocks as $block) {
            $content = isset($block['content']) ? (string) $block['content'] : '';
            $rules   = isset($block['rules']) && is_array($block['rules']) ? $block['rules'] : [];

            if ($content === '') {
                continue;
            }

            if (!$this->evaluateRules($rules, $data)) {
                continue;
            }

            $out[] = $this->replacePlaceholders($content, $data);
        }

        if (!$out) {
            return false;
        }

        return implode("\n", $out);
    }

    protected function evaluateRules(array $rules, array $data): bool
    {
        if (!$rules) {
            return true;
        }

        $main = isset($data['main']) && is_array($data['main']) ? $data['main'] : [];

        foreach ($rules as $rule) {
            $type = isset($rule['type']) ? (string) $rule['type'] : '';
            $op   = isset($rule['operator']) ? (string) $rule['operator'] : '';
            $val  = isset($rule['value']) ? (float) $rule['value'] : null;

            if ($type === '' || $op === '' || $val === null) {
                return false;
            }

            // Fetch left-hand value from data
            switch ($type) {
                case 'temp':
                case 'feels_like':
                case 'temp_min':
                case 'temp_max':
                    $left = isset($main[$type]) ? (float) $main[$type] : null;
                    break;
                default:
                    $left = null;
            }

            if ($left === null) {
                return false;
            }

            $pass = false;
            switch ($op) {
                case '>':
                    $pass = ($left > $val); break;
                case '>=':
                    $pass = ($left >= $val); break;
                case '<':
                    $pass = ($left < $val); break;
                case '<=':
                    $pass = ($left <= $val); break;
                case '=':
                    $pass = ($left == $val); break;
                case '!=':
                    $pass = ($left != $val); break;
                default:
                    $pass = false;
            }

            if (!$pass) {
                return false; // AND logic: any failed rule hides the block
            }
        }

        return true;
    }

    protected function replacePlaceholders(string $html, array $data): string
    {
        $map = [
            '{temp}'        => isset($data['main']['temp']) ? (string) $data['main']['temp'] : '',
            '{feels_like}'  => isset($data['main']['feels_like']) ? (string) $data['main']['feels_like'] : '',
            '{temp_min}'    => isset($data['main']['temp_min']) ? (string) $data['main']['temp_min'] : '',
            '{temp_max}'    => isset($data['main']['temp_max']) ? (string) $data['main']['temp_max'] : '',
            '{name}'        => isset($data['name']) ? (string) $data['name'] : '',
        ];

        if ($map['{temp}'] === '' && $map['{name}'] === '') {
            return $html;
        }

        return strtr($html, $map);
    }
}
