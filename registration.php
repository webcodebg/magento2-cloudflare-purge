<?php
/**
 * @author       Kostadin Bashev (bashev@webcode.bg)
 * @copyright Copyright © 2026 Webcode (https://webcode.bg/)
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Webcode_CloudflarePurge', __DIR__);
