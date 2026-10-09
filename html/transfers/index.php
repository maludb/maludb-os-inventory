<?php
declare(strict_types=1);
/** /transfers/ — screen `transfer-list`, built by its slice (NAV_SLICES); until then the shell's placeholder: 200 after the right (stock.transfer), 403 in its words, 501 to JSON and a POST. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('transfer-list', NAV_SLICES['transfer-list'], 'stock.transfer');
