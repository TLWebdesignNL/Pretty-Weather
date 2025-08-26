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
<?php if ($params->get('debug')) : ?>
    <?php $main = $weatherData['main'] ?? []; ?>
    <code><?php echo Text::_('MOD_PRETTYWEATHER_DEBUG_HEADING'); ?><br>
        <ul>
            <li><?php echo Text::_('MOD_PRETTYWEATHER_DEBUG_TEMP_LABEL'); ?> <?php echo $main['temp'] ?? ''; ?></li>
            <li><?php echo Text::_('MOD_PRETTYWEATHER_DEBUG_FEELS_LIKE_LABEL'); ?> <?php echo $main['feels_like'] ?? ''; ?></li>
            <li><?php echo Text::_('MOD_PRETTYWEATHER_DEBUG_MIN_TEMP_LABEL'); ?> <?php echo $main['temp_min'] ?? ''; ?></li>
            <li><?php echo Text::_('MOD_PRETTYWEATHER_DEBUG_MAX_TEMP_LABEL'); ?> <?php echo $main['temp_max'] ?? ''; ?></li>
            <li><?php echo Text::_('MOD_PRETTYWEATHER_DEBUG_NAME_LABEL'); ?> <?php echo $weatherData['name'] ?? ''; ?></li>
        </ul>
    </code>
<?php endif; ?>

<?php if ($display) : ?>
    <div class="prettyWeatherWrapper">
        <div class="prettyWeather mod-<?php echo $module->id?>">
			<?php echo $display; ?>
        </div>
    </div>
<?php endif; ?>
