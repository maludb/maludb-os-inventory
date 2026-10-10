<?php
/**
 * Router for `php -S 127.0.0.1:8607 -t html tests/dev_router.php` — the vhost's rewrites (deploy/apache-inventory.conf), so the
 * proofs run without Apache: /sso, /sso/logout, /api/v1/health, the two doors /o/{token} and /s/{token} and the feed
 * /api/v1/availability (later slices — a rewrite to a file its slice has not built yet answers 404, as Apache does), the gated
 * attachment door /files/{id}, a page of a record (/orders/12/lines) and the canonical URLs (/x/new, /x/{id}/edit, /x/{id},
 * /x → x.php or x/index.php). Anything that is a real file is served as it is.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = dirname(__DIR__) . '/html';
if ($path !== '/' && is_file($root . $path)) {
    if (str_ends_with($path, '.webmanifest')) { header('Content-Type: application/manifest+json'); readfile($root . $path); return true; }
    return false;                                             // a static file or a real .php file
}
if (str_starts_with($path, '/api/') && !preg_match('#^/api/v1/(health|availability)/?$#', $path)) { http_response_code(404); echo 'Not found'; return true; }
$map = ['/sso' => '/sso.php', '/sso/logout' => '/sso/logout.php', '/api/v1/health' => '/api/v1/health.php', '/api/v1/availability' => '/api/v1/availability.php'];
$rel = rtrim($path, '/') ?: '/';
$set = static function (array $kv): void { foreach ($kv as $k => $v) { $_GET[$k] = $v; $_REQUEST[$k] = $v; } };
if (preg_match('#^/o/([a-f0-9]{48})/?$#', $path, $m)) { $set(['token' => $m[1]]); $target = '/o.php'; }
elseif (preg_match('#^/s/([a-f0-9]{48})/?$#', $path, $m)) { $set(['token' => $m[1]]); $target = '/s.php'; }
elseif (preg_match('#^/s/([a-f0-9]{48})/([a-z_]+)$#', $path, $m)) { $set(['token' => $m[1], 'do' => $m[2]]); $target = '/s.php'; }
elseif (preg_match('#^/files/([0-9]+)$#', $path, $m)) { $set(['id' => $m[1]]); $target = '/files.php'; }
elseif (preg_match('#^/files/([0-9]+)/thumb$#', $path, $m)) { $set(['id' => $m[1], 'thumb' => '1']); $target = '/files.php'; }
elseif (preg_match('#^/reports/([a-z-]+)/?$#', $path, $m)) { $set(['report' => $m[1]]); $target = '/reports/report.php'; }          // a report by name (deploy/apache-inventory.conf)
elseif (preg_match('#^/(.+)/([0-9]+)/([a-z_-]+)$#', $path, $m) && is_file($root . '/' . $m[1] . '/' . $m[3] . '.php')) { $target = '/' . $m[1] . '/' . $m[3] . '.php'; $set(['id' => $m[2]]); }
elseif (isset($map[$rel])) { $target = $map[$rel]; }
elseif ($rel === '/') { $target = '/index.php'; }
elseif (preg_match('#^/(.+)/new$#', $rel, $m)) { $target = '/' . $m[1] . '/form.php'; }
elseif (preg_match('#^/(.+)/([0-9]+)/edit$#', $rel, $m)) { $target = '/' . $m[1] . '/form.php'; $set(['id' => $m[2]]); }
elseif (preg_match('#^/(.+)/([0-9]+)$#', $rel, $m)) { $target = '/' . $m[1] . '/view.php'; $set(['id' => $m[2]]); }
elseif (is_file($root . $rel . '.php')) { $target = $rel . '.php'; }
elseif (is_file($root . $rel . '/index.php')) { $target = $rel . '/index.php'; }
else { http_response_code(404); echo 'Not found'; return true; }
if (!is_file($root . $target)) { http_response_code(404); echo 'Not found'; return true; }     // a rewrite to a file its slice has not built yet: 404, as Apache answers
$_SERVER['SCRIPT_NAME'] = $target;
$_SERVER['SCRIPT_FILENAME'] = $root . $target;
chdir(dirname($root . $target));
require $root . $target;
return true;
