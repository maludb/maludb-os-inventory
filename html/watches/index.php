<?php
declare(strict_types=1);
/** /watches/ — screen `watch-list`, built by its slice (NAV_SLICES); until then the shell's placeholder: 200 after the right (watches.own), 403 in its words, 501 to JSON and a POST. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('watch-list', NAV_SLICES['watch-list'], 'watches.own');
