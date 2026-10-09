<?php
declare(strict_types=1);
/** /admin/reason-codes/ — screen `reason-code-list`, built by its slice (NAV_SLICES); until then the shell's placeholder: 200 after the right (settings.manage), 403 in its words, 501 to JSON and a POST. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
render_nav_stub('reason-code-list', NAV_SLICES['reason-code-list'], 'settings.manage');
