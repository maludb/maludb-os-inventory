<?php
declare(strict_types=1);
/** /reports/ — screen `report-list`, built by its slice (NAV_SLICES); until then the shell's placeholder: 200 after the right (reports.read), 403 in its words, 501 to JSON and a POST. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('report-list', NAV_SLICES['report-list'], 'reports.read');
