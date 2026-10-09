<?php
declare(strict_types=1);
/** /admin/tax-rates/ — screen `tax-rate-list`, built by its slice (NAV_SLICES); until then the shell's placeholder: 200 after the right (settings.manage), 403 in its words, 501 to JSON and a POST. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
render_nav_stub('tax-rate-list', NAV_SLICES['tax-rate-list'], 'settings.manage');
