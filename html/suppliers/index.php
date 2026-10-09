<?php
declare(strict_types=1);
/** /suppliers/ — screen `supplier-list`, built by its slice (NAV_SLICES); until then the shell's placeholder: 200 after the right (inventory.read), 403 in its words, 501 to JSON and a POST. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('supplier-list', NAV_SLICES['supplier-list'], 'inventory.read');
