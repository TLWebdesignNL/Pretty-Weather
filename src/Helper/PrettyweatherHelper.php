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
use Joomla\CMS\Log\Log;


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
	 * A refresh is triggered when the cache's `meta.fetched_at` is older than the
	 * pause time or when the configured latitude/longitude differ from `meta.lat/lon`.
	 *
	 * Configuration errors (invalid module, missing provider or API key) never
	 * throw: they return false and are reported through the debug channel.
	 *
	 * @param  \stdClass $module  Joomla module instance.
	 * @return array|false        Decoded weather array on success, or false if unavailable/misconfigured.
	 *
	 * @since 1.0.0
	 */
	public function getWeather($module): array|bool
	{
		if (!is_object($module)) {
			return false;
		}

		$params = json_decode($module->params ?? '') ?: new \stdClass();

		$provider = $params->provider ?? null;
		$apiKey   = $params->apikey ?? null;

		// A provider is always required; an API key is only required for
		// OpenWeatherMap. Open-Meteo is keyless.
		if (empty($provider)) {
			$this->debugMessage($params, 'MOD_PRETTYWEATHER_ERROR_NO_PROVIDER');

			return false;
		}

		if (in_array(strtolower((string) $provider), ['openweathermap', 'openweathermaponecall'], true) && empty($apiKey)) {
			$this->debugMessage($params, 'MOD_PRETTYWEATHER_ERROR_NO_APIKEY');

			return false;
		}

		$cacheFile = JPATH_ROOT . '/media/mod_prettyweather/data-' . $module->id . '.json';

		$pauseTimeSeconds = (!empty($params->pausetime) ? (int) $params->pausetime : 10) * 60;
		$lat = isset($params->latitude) && $params->latitude !== '' ? (float) $params->latitude : null;
		$lon = isset($params->longitude) && $params->longitude !== '' ? (float) $params->longitude : null;

		[$meta, $weather] = $this->readCache($cacheFile);

		if (!$this->needsRefresh($meta, $weather, $lat, $lon, $pauseTimeSeconds)) {
			return $weather ?? false;
		}

		// Only one request refreshes at a time; the others serve the cached data.
		$lock = $this->acquireLock($cacheFile . '.lock');

		if ($lock === null) {
			return $weather ?? false;
		}

		try {
			// Another request may have refreshed the cache while we waited for the lock.
			[$meta, $weather] = $this->readCache($cacheFile);

			if (!$this->needsRefresh($meta, $weather, $lat, $lon, $pauseTimeSeconds)) {
				return $weather ?? false;
			}

			$weatherData = $this->getWeatherData($provider, $apiKey, $params);

			if ($weatherData) {
				$this->saveJsonFile(
					[
						'meta'    => ['fetched_at' => time(), 'lat' => $lat, 'lon' => $lon],
						'weather' => $weatherData,
					],
					$cacheFile
				);

				return $weatherData;
			}

			// Record the failure for back-off, keeping the last good data.
			$meta                 = $meta ?? [];
			$meta['last_attempt'] = time();
			$meta['failures']     = (int) ($meta['failures'] ?? 0) + 1;

			$this->saveJsonFile(['meta' => $meta, 'weather' => $weather], $cacheFile);
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}

		// Fall back to the last stored data (possibly stale) when the fetch failed.
		return $weather ?? false;
	}

	/**
	 * Read the cache file and split it into metadata and weather payload.
	 *
	 * Old-format files (the bare weather array, no `meta`) return null metadata
	 * so they count as stale and migrate on the next successful fetch.
	 *
	 * @param  string $cacheFile Absolute path of the cache file.
	 * @return array             [?array $meta, ?array $weather]
	 *
	 * @since 1.2.0
	 */
	protected function readCache(string $cacheFile): array
	{
		$cache   = $this->getJsonFile($cacheFile);
		$meta    = (isset($cache['meta']) && is_array($cache['meta'])) ? $cache['meta'] : null;
		$weather = $meta !== null ? ($cache['weather'] ?? null) : $cache;

		return [$meta, is_array($weather) ? $weather : null];
	}

	/**
	 * Decide whether the cache must be refreshed.
	 *
	 * Freshness and coordinate checks use our own metadata, never provider
	 * fields such as `dt`. After a failed fetch no new attempt is made until the
	 * back-off window has passed: max(pause time, 5 min), doubling per
	 * consecutive failure, capped at one hour.
	 *
	 * @param  ?array     $meta             Cache metadata.
	 * @param  ?array     $weather          Cached weather payload.
	 * @param  float|null $lat              Configured latitude.
	 * @param  float|null $lon              Configured longitude.
	 * @param  int        $pauseTimeSeconds Pause time between API calls.
	 * @return bool
	 *
	 * @since 1.2.0
	 */
	protected function needsRefresh(?array $meta, ?array $weather, ?float $lat, ?float $lon, int $pauseTimeSeconds): bool
	{
		$failures = (int) ($meta['failures'] ?? 0);

		if ($failures > 0) {
			$backOff = min(max($pauseTimeSeconds, 300) * 2 ** min($failures - 1, 6), 3600);

			if (time() - (int) ($meta['last_attempt'] ?? 0) < $backOff) {
				return false;
			}
		}

		if ($meta === null || $weather === null || !isset($meta['fetched_at'])) {
			return true;
		}

		if ((int) $meta['fetched_at'] < (time() - $pauseTimeSeconds)) {
			return true;
		}

		return $this->coordChanged($meta['lat'] ?? null, $lat) || $this->coordChanged($meta['lon'] ?? null, $lon);
	}

	/**
	 * Take a non-blocking exclusive lock.
	 *
	 * @param  string $lockFile Absolute path of the lock file.
	 * @return resource|null    Lock handle, or null when the lock is held elsewhere or unavailable.
	 *
	 * @since 1.2.0
	 */
	protected function acquireLock(string $lockFile)
	{
		$dir = dirname($lockFile);

		if (!is_dir($dir)) {
			Folder::create($dir);
		}

		$handle = @fopen($lockFile, 'c');

		if ($handle === false) {
			return null;
		}

		if (!flock($handle, LOCK_EX | LOCK_NB)) {
			fclose($handle);

			return null;
		}

		return $handle;
	}

	/**
	 * Compare a cached coordinate with the configured one.
	 *
	 * @param  mixed      $cached  Coordinate stored in the cache metadata.
	 * @param  float|null $current Coordinate from the module params.
	 * @return bool                True when they differ.
	 *
	 * @since 1.2.0
	 */
	protected function coordChanged($cached, ?float $current): bool
	{
		if ($cached === null || $current === null) {
			return $cached !== $current;
		}

		// Use a tiny epsilon to avoid float jitter.
		return abs((float) $cached - $current) > 1e-6;
	}

	/**
	 * Whether the current visitor may see debug output.
	 *
	 * Debug output can reveal configuration details, so it is limited to users
	 * who can manage modules, or to sites running with JDEBUG enabled.
	 *
	 * @return bool
	 *
	 * @since 1.2.0
	 */
	public function canViewDebug(): bool
	{
		if (\defined('JDEBUG') && JDEBUG) {
			return true;
		}

		$user = Factory::getApplication()->getIdentity();

		return $user !== null && $user->authorise('core.manage', 'com_modules');
	}

	/**
	 * Enqueue a translated message when debug mode is enabled for the module
	 * and the current visitor may see debug output.
	 *
	 * @param  \stdClass $params Module params (used for the debug flag).
	 * @param  string    $key    Language key of the message.
	 * @param  string    $type   Message type (message, notice, warning, error).
	 * @param  mixed     ...$args Optional sprintf arguments for the message.
	 * @return void
	 *
	 * @since 1.2.0
	 */
	protected function debugMessage($params, string $key, string $type = 'warning', ...$args): void
	{
		if (empty($params->debug ?? null) || !$this->canViewDebug()) {
			return;
		}

		$message = $args ? Text::sprintf($key, ...$args) : Text::_($key);

		Factory::getApplication()->enqueueMessage($message, $type);
	}

	/**
	 * Write an exception to the module's log file with the API key redacted.
	 *
	 * Transport exceptions can contain the full request URL, including
	 * `appid=<API key>`, so the message is never shown on the frontend.
	 *
	 * @param  \Throwable  $e      The exception.
	 * @param  string|null $apiKey The configured API key, if any.
	 * @return void
	 *
	 * @since 1.2.0
	 */
	public function logException(\Throwable $e, ?string $apiKey = null): void
	{
		static $loggerAdded = false;

		$message = $e->getMessage();

		if (!empty($apiKey)) {
			$message = str_replace($apiKey, '***', $message);
		}

		$message = preg_replace('/(appid=)[^&\s"\']+/i', '$1***', $message);

		// Logging is best effort: an unwritable log folder must not break the page.
		try {
			if (!$loggerAdded) {
				Log::addLogger(['text_file' => 'mod_prettyweather.php'], Log::ALL, ['mod_prettyweather']);
				$loggerAdded = true;
			}

			Log::add(\get_class($e) . ': ' . $message, Log::WARNING, 'mod_prettyweather');
		} catch (\Throwable) {
		}
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
			$this->logException($e, $apiKey !== null ? (string) $apiKey : null);
			$this->debugMessage($params, 'MOD_PRETTYWEATHER_ERROR_EXCEPTION', 'error');

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

		// Perform GET using Joomla HTTP client with a strict timeout (max 3s)
		$http = HttpFactory::getHttp(['timeout' => 3, 'userAgent' => 'PrettyWeather/1.0']);
		$response = $http->get((string) $uri);

		$code  = (int) ($response->code ?? 0);
		if ($code !== 200) {
			$this->reportOpenWeatherMapHttpError($params, $code);

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

		// Perform GET using Joomla HTTP client with a strict timeout (max 3s)
		$http = HttpFactory::getHttp(['timeout' => 3, 'userAgent' => 'PrettyWeather/1.0']);
		$response = $http->get((string) $uri);

		$code = (int) ($response->code ?? 0);
		if ($code !== 200) {
			if ($code === 429) {
				$this->debugMessage($params, 'MOD_PRETTYWEATHER_ERROR_OPENMETEO_429');
			} else {
				$this->debugMessage($params, 'MOD_PRETTYWEATHER_ERROR_OPENMETEO_HTTP', 'warning', $code);
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

		// Don't spend a second (billed) call when the first one failed.
		if (!is_array($current)) {
			return false;
		}

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
	 * Report a non-200 OpenWeatherMap response through the debug channel.
	 *
	 * @param  \stdClass $params Module params (used for the debug flag).
	 * @param  int       $code   HTTP status code.
	 * @return void
	 *
	 * @since 1.2.0
	 */
	protected function reportOpenWeatherMapHttpError($params, int $code): void
	{
		match ($code) {
			401     => $this->debugMessage($params, 'MOD_PRETTYWEATHER_ERROR_401'),
			404     => $this->debugMessage($params, 'MOD_PRETTYWEATHER_ERROR_404', 'info'),
			429     => $this->debugMessage($params, 'MOD_PRETTYWEATHER_ERROR_429'),
			default => $this->debugMessage($params, 'MOD_PRETTYWEATHER_ERROR_HTTP', 'warning', $code),
		};
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
		// Perform GET using Joomla HTTP client with a strict timeout (max 3s)
		$http = HttpFactory::getHttp(['timeout' => 3, 'userAgent' => 'PrettyWeather/1.0']);
		$response = $http->get($url);

		$code = (int) ($response->code ?? 0);
		if ($code !== 200) {
			$this->reportOpenWeatherMapHttpError($params, $code);

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

		// Values come from module params or third-party APIs and are inserted
		// into HTML, so they must be escaped.
		$map = array_map(
			static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'),
			$map
		);

		return strtr($html, $map);
	}
}
