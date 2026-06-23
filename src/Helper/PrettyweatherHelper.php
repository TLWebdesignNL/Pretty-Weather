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
        if (!is_object($module)) {
            throw new \RuntimeException('Invalid module reference', 404);
        }

        $params = json_decode($module->params) ?: new \stdClass();


			// Decode module params

			// Extract required parameters
			$provider = $params->provider ?? null;
			$apiKey = $params->apikey ?? null;

			// Validate parameters. A provider is always required; an API key is
			// only required for providers that need one (e.g. OpenWeatherMap).
			// Open-Meteo is keyless, so it must not be blocked by a missing key.
			if (empty($provider))
			{
				throw new \InvalidArgumentException('No provider set', 400);
			}

			if (in_array(strtolower((string) $provider), ['openweathermap', 'openweathermaponecall'], true) && empty($apiKey))
			{
				throw new \InvalidArgumentException('No API key set', 400);
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
            // fetch weatherData if update is needed.
			$weatherData = $this->getWeatherData($provider, $apiKey, $params);

			if ($weatherData)
			{
				$this->saveJsonFile($weatherData, JPATH_ROOT . '/media/mod_prettyweather/data-' . $module->id . '.json');

				return $weatherData;
			}
		}

		// Save and return outcome (bool)
		return is_array($data) ? $data : false;
	}

	/**
	 * Read and decode a JSON file.
	 *
	 * @param  string $jsonFilePath Absolute file path.
	 * @return array|null           Decoded array, or null if file not found/invalid.
	 *
	 * @since 1.0.0
	 */
	public function getJsonFile(string $jsonFilePath): ?array
	{
		if (!is_file($jsonFilePath)) {
			return null; // file missing
		}

		$contents = json_decode(file_get_contents($jsonFilePath), true);
		return is_array($contents) ? $contents : null;
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
	public function saveJsonFile(array $data, string $jsonFilePath): bool
	{
		$dir = dirname($jsonFilePath);
		if (!is_dir($dir)) {
			Folder::create($dir);
		}

		$jsonContents = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		// Save the JSON data to the file
		return File::write($jsonFilePath, $jsonContents);
	}

	/**
	 * Fetch current weather, routing to the configured provider.
	 *
	 * Both providers normalise their response into the same canonical structure
	 * (the OpenWeatherMap shape: main.temp/feels_like/temp_min/temp_max, name,
	 * coord.lat/lon, dt) so all downstream code remains provider-agnostic.
	 *
	 * @param  string    $provider Provider key ("openweathermap" or "openmeteo").
	 * @param  string    $apiKey   API key (only used by OpenWeatherMap).
	 * @param  \stdClass $params   Module params (expects latitude, longitude, units).
	 * @return array|false         Canonical weather array on success, false on failure.
	 *
	 * @since 1.0.0
	 */
	public function getWeatherData($provider, $apiKey, $params): array|false
	{
		try {
			$provider = strtolower((string) $provider);

			if ($provider === 'openmeteo') {
				return $this->getOpenMeteoData($params);
			}

			if ($provider === 'openweathermaponecall') {
				return $this->getOpenWeatherMapOneCallData($apiKey, $params);
			}

			return $this->getOpenWeatherMapData($apiKey, $params);
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
	 * Fetch current weather from OpenWeatherMap and return it in canonical form.
	 *
	 * @param  string    $apiKey API key.
	 * @param  \stdClass $params Module params (expects latitude, longitude, units).
	 * @return array|false       Canonical weather array on success, false on failure/validation issues.
	 *
	 * @since 1.1.0
	 */
	protected function getOpenWeatherMapData($apiKey, $params): array|false
	{
		// Extract required params
		$lat   = isset($params->latitude) ? (float) $params->latitude : null;
		$lon   = isset($params->longitude) ? (float) $params->longitude : null;
		$units = !empty($params->units) ? (string) $params->units : 'metric';

		if ($lat === null || $lon === null || empty($apiKey)) {
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
		$http = HttpFactory::getHttp(['timeout' => 5, 'userAgent' => 'PrettyWeather/1.0']);
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

        $body = $response->body ?? '';
        if ($body === '') {
            return false;
        }

        $data = json_decode($body, true);

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

		// Optional manual location name overrides the API-provided name.
		if (!empty($params->locationname)) {
			$data['name'] = (string) $params->locationname;
		}

		return $data;
	}

	/**
	 * Fetch current weather from Open-Meteo and normalise it into the canonical
	 * OpenWeatherMap-shaped structure used throughout the module.
	 *
	 * Open-Meteo is keyless and only supports Celsius/Fahrenheit. The module's
	 * "standard" (Kelvin) option is satisfied by fetching Celsius and converting
	 * to Kelvin in code. Unlike OpenWeatherMap's current-weather endpoint, the
	 * daily min/max returned here are genuine daily extremes.
	 *
	 * @param  \stdClass $params Module params (expects latitude, longitude, units).
	 * @return array|false       Canonical weather array on success, false on failure/validation issues.
	 *
	 * @since 1.1.0
	 */
	protected function getOpenMeteoData($params): array|false
	{
		$lat = isset($params->latitude) ? (float) $params->latitude : null;
		$lon = isset($params->longitude) ? (float) $params->longitude : null;

		if ($lat === null || $lon === null) {
			return false;
		}

		// Map the module's units to Open-Meteo's temperature_unit. Open-Meteo has
		// no Kelvin; for "standard" we request Celsius and convert afterwards.
		$units    = !empty($params->units) ? strtolower((string) $params->units) : 'metric';
		$toKelvin = ($units === 'standard');
		$tempUnit = ($units === 'imperial') ? 'fahrenheit' : 'celsius';

		// Build request URL
		$base  = 'https://api.open-meteo.com/v1/forecast';
		$query = [
			'latitude'         => $lat,
			'longitude'        => $lon,
			'current'          => 'temperature_2m,apparent_temperature',
			'daily'            => 'temperature_2m_max,temperature_2m_min',
			'temperature_unit' => $tempUnit,
			'timezone'         => 'auto',
			'forecast_days'    => 1,
		];

		$uri = new Uri($base);
		$uri->setQuery($query);

		// Perform GET using Joomla HTTP client with a strict timeout (max 5s)
		$http = HttpFactory::getHttp(['timeout' => 5, 'userAgent' => 'PrettyWeather/1.0']);
		$response = $http->get((string) $uri);

		$code = (int) ($response->code ?? 0);
		if ($code !== 200) {
		    $debug = !empty($params->debug);
		    if ($debug) {
		        $app = Factory::getApplication();
		        switch ($code) {
		            case 429:
		                $app->enqueueMessage(Text::_('MOD_PRETTYWEATHER_ERROR_OPENMETEO_429'), 'warning');
		                break;
		            default:
		                $app->enqueueMessage(Text::sprintf('MOD_PRETTYWEATHER_ERROR_OPENMETEO_HTTP', $code), 'warning');
		                break;
		        }
		    }

		    return false;
		}

		$body = $response->body ?? '';
		if ($body === '') {
			return false;
		}

		$data = json_decode($body, true);
		if (!is_array($data)) {
			return false;
		}

		// Validate essential fields from the Open-Meteo response.
		$hasRequired = isset($data['current'])
			&& is_array($data['current'])
			&& array_key_exists('temperature_2m', $data['current'])
			&& array_key_exists('apparent_temperature', $data['current'])
			&& isset($data['daily'])
			&& is_array($data['daily'])
			&& isset($data['daily']['temperature_2m_max'][0])
			&& isset($data['daily']['temperature_2m_min'][0]);

		if (!$hasRequired) {
			return false;
		}

		$temp     = (float) $data['current']['temperature_2m'];
		$apparent = (float) $data['current']['apparent_temperature'];
		$tempMax  = (float) $data['daily']['temperature_2m_max'][0];
		$tempMin  = (float) $data['daily']['temperature_2m_min'][0];

		if ($toKelvin) {
			$temp     += 273.15;
			$apparent += 273.15;
			$tempMax  += 273.15;
			$tempMin  += 273.15;
		}

		// Normalise into the canonical OpenWeatherMap-shaped structure.
		$weather = [
			'main'  => [
				'temp'       => $temp,
				'feels_like' => $apparent,
				'temp_min'   => $tempMin,
				'temp_max'   => $tempMax,
			],
			// Open-Meteo's forecast endpoint returns no location name; the optional
			// manual name (below) is the only source for the {name} placeholder.
			'name'  => '',
			// Store the REQUESTED coordinates, not Open-Meteo's grid-snapped values,
			// so getWeather()'s coord-change check (epsilon 1e-6) does not thrash the cache.
			'coord' => ['lat' => $lat, 'lon' => $lon],
			'dt'    => time(),
		];

		if (!empty($params->locationname)) {
			$weather['name'] = (string) $params->locationname;
		}

		return $weather;
	}

	/**
	 * Fetch current weather from the OpenWeatherMap One Call API 4.0 and return
	 * it in canonical form.
	 *
	 * One Call API 4.0 splits data across separate endpoints, so two requests are
	 * needed: one for the current conditions and one for the 1-day timeline that
	 * carries the genuine daily minimum/maximum temperatures. Each module refresh
	 * therefore consumes two of the included "One Call by Call" calls (the first
	 * 1,000 calls/day are free). All three unit systems are supported natively, so
	 * no conversion is required. The endpoint returns no location name; the optional
	 * manual name fills {name}.
	 *
	 * @param  string    $apiKey API key.
	 * @param  \stdClass $params Module params (expects latitude, longitude, units).
	 * @return array|false       Canonical weather array on success, false on failure/validation issues.
	 *
	 * @since 1.1.0
	 */
	protected function getOpenWeatherMapOneCallData($apiKey, $params): array|false
	{
		$lat   = isset($params->latitude) ? (float) $params->latitude : null;
		$lon   = isset($params->longitude) ? (float) $params->longitude : null;
		$units = !empty($params->units) ? (string) $params->units : 'metric';

		if ($lat === null || $lon === null || empty($apiKey)) {
			return false;
		}

		$query = [
			'lat'   => $lat,
			'lon'   => $lon,
			'appid' => $apiKey,
			'units' => $units,
		];

		// Call 1: current conditions.
		$currentUri = new Uri('https://api.openweathermap.org/data/4.0/onecall/current');
		$currentUri->setQuery($query);
		$current = $this->fetchOpenWeatherMapJson((string) $currentUri, $params);

		// Call 2: 1-day timeline, which carries the true daily min/max.
		$dailyUri = new Uri('https://api.openweathermap.org/data/4.0/onecall/timeline/1day');
		$dailyUri->setQuery($query);
		$daily = $this->fetchOpenWeatherMapJson((string) $dailyUri, $params);

		if (!is_array($current) || !is_array($daily)) {
			return false;
		}

		// Validate essential fields from both One Call 4.0 responses.
		$hasCurrent = isset($current['data'][0])
			&& is_array($current['data'][0])
			&& array_key_exists('temp', $current['data'][0])
			&& array_key_exists('feels_like', $current['data'][0]);

		// The daily timeline can return several days; explicitly select the record
		// covering today (not data[0], which is not guaranteed to be the current
		// day) so min/max match the present, consistent with the other providers.
		$dayRecord = $this->selectTodaysDailyRecord($daily['data'] ?? null);

		if (!$hasCurrent || $dayRecord === null) {
			return false;
		}

		// Normalise into the canonical OpenWeatherMap-shaped structure.
		$weather = [
			'main'  => [
				'temp'       => (float) $current['data'][0]['temp'],
				'feels_like' => (float) $current['data'][0]['feels_like'],
				'temp_min'   => (float) $dayRecord['temp']['min'],
				'temp_max'   => (float) $dayRecord['temp']['max'],
			],
			'name'  => '',
			// Store the REQUESTED coordinates so the coord-change cache check is stable.
			'coord' => ['lat' => $lat, 'lon' => $lon],
			'dt'    => isset($current['data'][0]['dt']) ? (int) $current['data'][0]['dt'] : time(),
		];

		if (!empty($params->locationname)) {
			$weather['name'] = (string) $params->locationname;
		}

		return $weather;
	}

	/**
	 * Select the daily record covering the current day from a One Call 4.0 daily
	 * timeline (data[]).
	 *
	 * Prefers the latest record whose timestamp is not in the future (i.e. today).
	 * Falls back to the first record that carries a min/max when none are dated
	 * today or earlier (e.g. a timeline that only starts tomorrow).
	 *
	 * @param  mixed $records The decoded daily "data" array, or null.
	 * @return array|null     The chosen daily record, or null when none are usable.
	 *
	 * @since 1.1.0
	 */
	protected function selectTodaysDailyRecord($records): ?array
	{
		if (!is_array($records)) {
			return null;
		}

		$now    = time();
		$chosen = null;

		foreach ($records as $record) {
			if (!is_array($record) || !isset($record['temp']['min'], $record['temp']['max'])) {
				continue;
			}

			$dt = isset($record['dt']) ? (int) $record['dt'] : null;

			// Prefer the most recent record that is not in the future (today).
			if ($dt !== null && $dt <= $now && ($chosen === null || $dt > (int) ($chosen['dt'] ?? 0))) {
				$chosen = $record;
			}
		}

		if ($chosen !== null) {
			return $chosen;
		}

		// Fallback: first record that carries min/max.
		foreach ($records as $record) {
			if (is_array($record) && isset($record['temp']['min'], $record['temp']['max'])) {
				return $record;
			}
		}

		return null;
	}

	/**
	 * Perform a GET request against an OpenWeatherMap endpoint and decode the JSON.
	 *
	 * Shared by the One Call API 4.0 calls so the timeout, user agent and HTTP
	 * error reporting behave identically for each request.
	 *
	 * @param  string    $url    Fully-qualified request URL (including query).
	 * @param  \stdClass $params Module params (used for the debug flag).
	 * @return array|null        Decoded response array, or null on error/invalid body.
	 *
	 * @since 1.1.0
	 */
	protected function fetchOpenWeatherMapJson(string $url, $params): ?array
	{
		// Perform GET using Joomla HTTP client with a strict timeout (max 5s)
		$http = HttpFactory::getHttp(['timeout' => 5, 'userAgent' => 'PrettyWeather/1.0']);
		$response = $http->get($url);

		$code = (int) ($response->code ?? 0);
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

		    return null;
		}

		$body = $response->body ?? '';
		if ($body === '') {
			return null;
		}

		$data = json_decode($body, true);

		return is_array($data) ? $data : null;
	}

	/**
	 * Build the rendered HTML from default content and conditional content rules.
	 *
	 * @param  \stdClass $module Joomla module instance.
	 * @param  array     $data   Decoded weather data (as stored/returned by getWeatherData()).
	 * @return ?string         Concatenated HTML string on success, or null when nothing matches.
	 *
	 * @since 1.0.0
	 */
	public function displayContent($module, $data): ?string
	{
		if (empty($data) || !is_array($data)) {
			return null;
		}

		if (!is_object($module)) {
			return null;
		}

		$params = json_decode($module->params) ?: new \stdClass();
		$blocks = $params->conditional_content ?? [];
		$provider = isset($params->provider) ? strtolower((string) $params->provider) : '';

		$out = [];

		// check if there is default content and replace placeholders and add to array.
		if (!empty($params->content)) {
			$out[] = $this->replacePlaceholders($params->content, $data, $provider);
		}

		if (is_object($blocks))
		{
			foreach ($blocks as $block)
			{
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

				$out[] = $this->replacePlaceholders($content, $data, $provider);
			}
		}
		if (!$out) {
			return null;
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

			$currentTypeValue = isset($main[$type]) ? (float) $main[$type] : null;
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
	 * For OpenWeatherMap the {temp_min}/{temp_max} placeholders resolve to an empty
	 * string: its current-weather endpoint reports min/max as the current temperature
	 * for a single location, so the values are meaningless. Open-Meteo returns genuine
	 * daily extremes and is left untouched.
	 *
	 * @param  string $html     Content with placeholders.
	 * @param  array  $data     Weather data array.
	 * @param  string $provider Lower-cased provider key (e.g. "openweathermap").
	 * @return string           Content with placeholders replaced.
	 *
	 * @since 1.0.0
	 */
	protected function replacePlaceholders(string $html, array $data, string $provider = ''): string
	{
		$hideMinMax = ($provider === 'openweathermap');

		$map = [
			'{temp}'        => isset($data['main']['temp']) ? (int) $data['main']['temp'] : '',
			'{feels_like}'  => isset($data['main']['feels_like']) ? (int) $data['main']['feels_like'] : '',
			'{temp_min}'    => ($hideMinMax || !isset($data['main']['temp_min'])) ? '' : (int) $data['main']['temp_min'],
			'{temp_max}'    => ($hideMinMax || !isset($data['main']['temp_max'])) ? '' : (int) $data['main']['temp_max'],
			'{name}'        => isset($data['name']) ? (string) $data['name'] : '',
		];

		if ($map['{temp}'] === '' && $map['{name}'] === '') {
			return $html;
		}

		return strtr($html, $map);
	}
}
