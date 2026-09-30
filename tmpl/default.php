<?php

/**
 * @package     TLWeb.Module
 * @subpackage  mod_prettyweather
 *
 * @copyright   Copyright (C) 2024 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

?>
<?php if ($debug) : ?>
    <?php
    $main = $weatherData['main'] ?? [];
    $rows = [
        'MOD_PRETTYWEATHER_DEBUG_TEMP_LABEL'       => $main['temp'] ?? '',
        'MOD_PRETTYWEATHER_DEBUG_FEELS_LIKE_LABEL' => $main['feels_like'] ?? '',
    ];

    // OpenWeatherMap 2.5 has no meaningful daily min/max.
    if (strtolower((string) $params->get('provider')) !== 'openweathermap') {
        $rows['MOD_PRETTYWEATHER_DEBUG_MIN_TEMP_LABEL'] = $main['temp_min'] ?? '';
        $rows['MOD_PRETTYWEATHER_DEBUG_MAX_TEMP_LABEL'] = $main['temp_max'] ?? '';
    }

    $rows['MOD_PRETTYWEATHER_DEBUG_NAME_LABEL'] = $weatherData['name'] ?? '';
    ?>
    <div class="alert alert-info">
        <p class="fw-bold"><?php echo Text::_('MOD_PRETTYWEATHER_DEBUG_HEADING'); ?></p>
        <dl class="row mb-0">
            <?php foreach ($rows as $label => $value) : ?>
                <dt class="col-sm-4"><?php echo Text::_($label); ?></dt>
                <dd class="col-sm-8"><code><?php echo htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); ?></code></dd>
            <?php endforeach; ?>
        </dl>
    </div>
<?php endif; ?>

<?php if ($display) : ?>
    <div class="prettyWeatherWrapper">
        <div class="prettyWeather mod-<?php echo (int) $module->id; ?>">
            <?php echo $display; ?>
        </div>
    </div>
<?php endif; ?>
