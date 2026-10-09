<?php
declare(strict_types=1);
/** /stock/movements — screen `movement-list`, built by its slice (NAV_SLICES); until then the shell's placeholder: 200 after the right (inventory.read), 403 in its words, 501 to JSON and a POST. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('movement-list', NAV_SLICES['movement-list'], 'inventory.read');
