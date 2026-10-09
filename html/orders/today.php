<?php
declare(strict_types=1);
/** /orders/today — screen `fulfilment-today`, built by its slice (NAV_SLICES); until then the shell's placeholder: 200 after the right (inventory.read), 403 in its words, 501 to JSON and a POST. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('fulfilment-today', NAV_SLICES['fulfilment-today'], 'inventory.read');
