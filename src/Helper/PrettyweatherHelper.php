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
use Joomla\Filesystem\File;
use Joomla\Filesystem\Folder;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;


\defined('_JEXEC') or die;

/**
 * Helper for mod_prettyweather
 *
 * @since  V1.0.0
 */
class PrettyweatherHelper
{
	/**
	 * Retrieve weather data and update the JSON cache when stale or when coordinates changed.
	 *
	 * A refresh is triggered when the cached `dt` is older than the pause time
	 * or when the configured latitude/longitude differ from the cached `coord`.
	 *
	 * @param  \stdClass $module  Joomla module instance.
	 * @return array|false        Decoded weather array on success, or false if unchanged/unavailable.
	 * @throws \InvalidArgumentException When required params are missing.
	 * @throws \RuntimeException         When the module reference is invalid.
	 *
	 * @since 1.0.0
	 */
	public function getWeather($module): array|bool
	{


		$params = json_decode($module->params);

		// Check if the module is 'mod_prettyweather'
		if ($module && $module->name === 'prettyweather')
		{
			// Decode module params

			// Extract required parameters
			$provider = $params->provider ?? null;
			$apiKey = $params->apikey ?? null;

			// Validate parameters
			if (empty($provider) || empty($apiKey))
			{
				throw new \InvalidArgumentException('No provider and/or API key set', 400);
			}
		} else {
			throw new \RuntimeException('Module not found or invalid', 404);
		}

		// Get existing weather data from JSON file
		$data = $this->getJsonFile(JPATH_ROOT . '/media/mod_prettyweather/data-' . $module->id . '.json');
		$pauseTimeSeconds = (!empty($params->pausetime) ? (int) $params->pausetime : 10) * 60;
		// Determine if we need to update: time-based or coordinate change
		$now = time();
		$isStale = empty($data) || !isset($data['dt']) || ($data['dt'] < ($now - $pauseTimeSeconds));

		// Compare current params lat/lon with stored JSON coords
		$latParam = isset($params->latitude) ? (float) $params->latitude : null;
		$lonParam = isset($params->longitude) ? (float) $params->longitude : null;
		$hasCoordsInData = isset($data['coord']['lat']) && isset($data['coord']['lon']);
		$coordsChanged = false;
		if ($latParam !== null && $lonParam !== null && $hasCoordsInData) {
			// Use a tiny epsilon to avoid float jitter
			$coordsChanged = (abs((float)$data['coord']['lat'] - $latParam) > 1e-6)
				|| (abs((float)$data['coord']['lon'] - $lonParam) > 1e-6);
		}

		if ($isStale || $coordsChanged)
		{
			// fetch weaterData if update is needed.
			$weatherData = $this->getWeatherData($provider, $apiKey, $params);

			if ($weatherData)
			{
				$this->saveJsonFile($weatherData, JPATH_ROOT . '/media/mod_prettyweather/data-' . $module->id . '.json');

				return $weatherData;
			}
		}

		// Save and return outcome (bool)
		return $data;
	}

	/**
	 * Read and decode a JSON file.
	 *
	 * @param  string $jsonFilePath Absolute file path.
	 * @return array|bool           Decoded array, or bool if file not found/invalid.
	 *
	 * @since 1.0.0
	 */
	public function getJsonFile($jsonFilePath): array|bool
	{
		if (is_file($jsonFilePath)) {
			$jsonContents = file_get_contents($jsonFilePath);
			return json_decode($jsonContents, true);
		}

		return false;
	}

	/**
	 * Encode and write data to a JSON file (ensures target directory exists).
	 *
	 * @param  array  $data         Data to encode.
	 * @param  string $jsonFilePath Absolute file path.
	 * @return bool                 True on success, false on failure.
	 *
	 * @since 1.0.0
	 */
	public function saveJsonFile(array $data, $jsonFilePath): bool
	{
		$dir = dirname($jsonFilePath);
		if (!is_dir($dir)) {
			Folder::create($dir);
		}

		$jsonContents = json_encode($data);

		// Save the JSON data to the file
		return File::write($jsonFilePath, $jsonContents);
	}

	/**
	 * Fetch current weather from OpenWeatherMap.
	 *
	 * @param  string    $provider Provider key (only "openweathermap" is supported).
	 * @param  string    $apiKey   API key.
	 * @param  \stdClass $params   Module params (expects latitude, longitude, units).
	 * @return array|false         Decoded weather array on success, false on failure/validation issues.
	 *
	 * @since 1.0.0
	 */
	public function getWeatherData($provider, $apiKey, $params)
	{
		try {
			// Extract required params
			$lat   = isset($params->latitude) ? (float) $params->latitude : null;
			$lon   = isset($params->longitude) ? (float) $params->longitude : null;
			$units = !empty($params->units) ? (string) $params->units : 'metric';

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

			// Perform GET using Joomla HTTP client with a strict timeout (max 5s)
			$http = HttpFactory::getHttp(['timeout' => 5]);
			$response = $http->get((string) $uri);

			$code  = (int) ($response->code ?? 0);
			if ($code !== 200) {
			    $debug = !empty($params->debug);
			    if ($debug) {
			        $app = Factory::getApplication();
			        switch ($code) {
			            case 401:
			                $app->enqueueMessage(Text::_('MOD_PRETTYWEATHER_ERROR_401'), 'warning');
			                break;
			            case 404:
			                $app->enqueueMessage(Text::_('MOD_PRETTYWEATHER_ERROR_404'), 'info');
			                break;
			            case 429:
			                $app->enqueueMessage(Text::_('MOD_PRETTYWEATHER_ERROR_429'), 'warning');
			                break;
			            default:
			                $app->enqueueMessage(Text::sprintf('MOD_PRETTYWEATHER_ERROR_HTTP', $code), 'warning');
			                break;
			        }
			    }

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
			$debug = !empty($params->debug ?? null);
			if ($debug) {
			    $app = Factory::getApplication();
			    $app->enqueueMessage(Text::sprintf('MOD_PRETTYWEATHER_ERROR_EXCEPTION', $e->getMessage()), 'error');
			}
			return false;
		}
	}

	/**
	 * Build the rendered HTML from default content and conditional content rules.
	 *
	 * @param  \stdClass $module Joomla module instance.
	 * @param  array     $data   Decoded weather data (as stored/returned by getWeatherData()).
	 * @return string|false      Concatenated HTML string on success, or false when nothing matches.
	 *
	 * @since 1.0.0
	 */
	public function displayContent($module, $data)
	{
		if (empty($data) || !is_array($data)) {
			return false;
		}

		if (!$module || $module->name !== 'prettyweather') {
			return false;
		}

		$params = json_decode($module->params) ?: new \stdClass();
		$blocks = $params->conditional_content ?? [];

		$out = [];

		// check if there is default content and replace placeholders and add to array.
		if (!empty($params->content)) {
			$out[] = $this->replacePlaceholders($params->content, $data);
		}

		if (is_object($blocks))
		{
			foreach ($blocks as $block)
			{
//				var_dump($block);
				$content = isset($block->content) ? (string) $block->content : '';
				$rules   = [];
				if (isset($block->rules))
				{
					$rules = is_array($block->rules) ? $block->rules : (array) $block->rules;
				}

				if ($content === '')
				{
					continue;
				}

				if (!$this->evaluateRules($rules, $data))
				{
					continue;
				}

				$out[] = $this->replacePlaceholders($content, $data);
			}
		}
		if (!$out) {
			return false;
		}

		return implode("\n", $out);
	}

	/**
	 * Evaluate a list of rules using AND logic.
	 *
	 * Supported types: temp, feels_like, temp_min, temp_max.
	 * Supported operators (tokens): gt, gtr (>=), lt, ltr (<=), eq, neq.
	 *
	 * @param  array $rules List of rule objects (type/operator/value).
	 * @param  array $data  Weather data array to read current values from.
	 * @return bool         True if all rules pass, false otherwise.
	 *
	 * @since 1.0.0
	 */
	protected function evaluateRules(array $rules, array $data): bool
	{
		if (!$rules) {
			return true;
		}

		$main = isset($data['main']) && is_array($data['main']) ? $data['main'] : [];

		foreach ($rules as $rule) {
			$type = isset($rule->type) ? (string) $rule->type : '';
			$op   = isset($rule->operator) ? (string) $rule->operator : '';
			$val  = isset($rule->value) ? (float) $rule->value : null;

			if ($type === '' || $op === '' || $val === null) {
				return false;
			}

			$currentTypeValue = isset($main[$type]) ? (int) $main[$type] : null;
			if ($currentTypeValue === null) {
				return false;
			}

			$pass = false;
			switch ($op) {
				case 'gt':
					$pass = ($currentTypeValue > $val); break;
				case 'gtr':
					$pass = ($currentTypeValue >= $val); break;
				case 'lt':
					$pass = ($currentTypeValue < $val); break;
				case 'ltr':
					$pass = ($currentTypeValue <= $val); break;
				case 'eq':
					$pass = ($currentTypeValue == $val); break;
				case 'neq':
					$pass = ($currentTypeValue != $val); break;
				default:
					$pass = false;
			}

			if (!$pass) {
				return false; // AND logic: any failed rule hides the block
			}
		}

		return true;
	}

	/**
	 * Replace placeholders in content with values from the weather data.
	 *
	 * Supported placeholders: {temp}, {feels_like}, {temp_min}, {temp_max}, {name}.
	 *
	 * @param  string $html Content with placeholders.
	 * @param  array  $data Weather data array.
	 * @return string       Content with placeholders replaced.
	 *
	 * @since 1.0.0
	 */
	protected function replacePlaceholders(string $html, array $data): string
	{
		$map = [
			'{temp}'        => isset($data['main']['temp']) ? (int) $data['main']['temp'] : '',
			'{feels_like}'  => isset($data['main']['feels_like']) ? (int) $data['main']['feels_like'] : '',
			'{temp_min}'    => isset($data['main']['temp_min']) ? (int) $data['main']['temp_min'] : '',
			'{temp_max}'    => isset($data['main']['temp_max']) ? (int) $data['main']['temp_max'] : '',
			'{name}'        => isset($data['name']) ? (string) $data['name'] : '',
		];

		if ($map['{temp}'] === '' && $map['{name}'] === '') {
			return $html;
		}

		return strtr($html, $map);
	}
}
