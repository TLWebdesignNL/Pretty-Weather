<?php

/**
 * @package     TLWeb.Module
 * @subpackage  mod_prettyweather
 *
 * @copyright   Copyright (C) 2024 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use TLWeb\Module\Prettyweather\Site\Helper\PrettyweatherHelper;

?>

<?php if ($display) : ?>
    <div class="prettyWeatherWrapper">
        <div class="prettyWeather mod-<?php echo $module->id?>">

        </div>
    </div>
<?php endif; ?>
