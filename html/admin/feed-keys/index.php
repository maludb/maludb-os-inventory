<?php
declare(strict_types=1);
/** /admin/feed-keys/ — screen `feed-key-list`, built by its slice (NAV_SLICES); until then the shell's placeholder: 200 after the right (feed.keys), 403 in its words, 501 to JSON and a POST. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
render_nav_stub('feed-key-list', NAV_SLICES['feed-key-list'], 'feed.keys');
