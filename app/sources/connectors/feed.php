<?php
declare(strict_types=1);

/**
 * `feed` — a supplier's inventory file (design §0.2): a CSV or XLSX on an HTTPS URL (optional basic credential) or on an
 * SFTP account (settings.sftp host/port/user/path; credential kind sftp_password or sftp_key), with a COLUMN MAPPING made
 * once in the source's settings. The only connector that gives cost, the supplier's true quantity and a lead time.
 *
 * settings: url | sftp {host, port, user, path}; format csv|xlsx|auto; delimiter (auto); has_header (true);
 *   mapping {supplier_sku, gtin, name, size, cost, price, qty, in_stock, lead_time_days, map_price, mpn, brand, product,
 *   url, currency} → a column HEADER (case-insensitive) or a 0-based column INDEX; currency (default USD); vendor (a brand
 *   when the file names none); lead_time_days (a default); skip_rows (lines before the header).
 * preview(source, rows) gives the first rows for the mapping screen. XLSX is read with ZipArchive + SimpleXML (sheet1 and
 * sharedStrings) — no library. SFTP uses the ssh2 extension when present, else `curl sftp://` with a netrc file
 * (the password never on a command line), else `sftp` in batch mode — checked at runtime, reported in probe().
 */

final class InvConnectorFeed implements InvConnector
{
    public const FIELDS = ['supplier_sku', 'gtin', 'name', 'size', 'cost', 'price', 'qty', 'in_stock', 'lead_time_days', 'map_price', 'mpn', 'brand', 'product', 'url', 'currency'];
    public const MAX_ROWS = 100000;

    public function capabilities(): array
    {
        return inv_capabilities([
            'has_search' => false, 'has_lookup' => false, 'gives_qty' => true, 'gives_cost' => true,
            'needs_credential' => 'for a protected URL or SFTP', 'is_reference' => false, 'lookup_by' => [], 'credential_kinds' => ['basic', 'sftp_password', 'sftp_key'],
        ]);
    }

    public function probe(array $source): array
    {
        $mapping = inv_setting($source, 'mapping', []);
        if (!is_array($mapping) || !isset($mapping['supplier_sku']) && !isset($mapping['gtin'])) {
            $fetched = $this->fetch($source);
            if ($fetched['blocked']) {
                return inv_probe_result('blocked', 'The file answered with a block (' . $fetched['error'] . ').', ['reason' => $fetched['error']]);
            }
            if (!$fetched['ok']) {
                return inv_probe_result('misconfigured', 'The file could not be read: ' . $fetched['error'] . '.', ['via' => $fetched['via']]);
            }
            $table = inv_feed_rows($fetched['bytes'], $this->settings($source));
            return inv_probe_result('misconfigured', 'The file was read (' . count($table['rows']) . ' rows, ' . $table['format'] . ') but the column mapping names neither supplier_sku nor gtin.', ['columns' => $table['columns'], 'rows' => count($table['rows']), 'format' => $table['format'], 'via' => $fetched['via']]);
        }
        $fetched = $this->fetch($source);
        if ($fetched['blocked']) {
            return inv_probe_result('blocked', 'The file answered with a block (' . $fetched['error'] . ').', ['reason' => $fetched['error']]);
        }
        if (!$fetched['ok']) {
            return inv_probe_result('misconfigured', 'The file could not be read: ' . $fetched['error'] . '.', ['via' => $fetched['via'], 'sftp_tool' => inv_feed_sftp_tool()]);
        }
        $table = inv_feed_rows($fetched['bytes'], $this->settings($source));
        $missing = [];
        $cols = inv_feed_column_index($table['columns'], $mapping, $missing);
        if ($missing !== []) {
            return inv_probe_result('misconfigured', 'Mapped columns not in the file: ' . implode(', ', $missing) . '.', ['columns' => $table['columns'], 'missing' => $missing, 'via' => $fetched['via']]);
        }
        return inv_probe_result('ok', count($table['rows']) . ' rows read (' . $table['format'] . ', ' . $fetched['via'] . '); ' . count($cols) . ' columns mapped.', ['rows' => count($table['rows']), 'format' => $table['format'], 'via' => $fetched['via'], 'columns' => $table['columns'], 'bytes' => strlen($fetched['bytes'])]);
    }

    public function pull(array $source, callable $emit): array
    {
        $fetched = $this->fetch($source);
        $http = $fetched['http'];
        if ($fetched['blocked']) {
            return inv_pull_stats($http, 0, ['blocked (' . $fetched['error'] . ')'], 'blocked', ['via' => $fetched['via']]);
        }
        if (!$fetched['ok']) {
            return inv_pull_stats($http, 0, [$fetched['error']], 'failed', ['via' => $fetched['via']]);
        }
        $settings = $this->settings($source);
        $mapping = is_array($settings['mapping'] ?? null) ? $settings['mapping'] : [];
        if (!isset($mapping['supplier_sku']) && !isset($mapping['gtin'])) {
            return inv_pull_stats($http, 0, ['the column mapping names neither supplier_sku nor gtin'], 'failed', ['via' => $fetched['via']]);
        }
        $table = inv_feed_rows($fetched['bytes'], $settings);
        $missing = [];
        $cols = inv_feed_column_index($table['columns'], $mapping, $missing);
        if ($missing !== []) {
            return inv_pull_stats($http, 0, ['mapped columns not in the file: ' . implode(', ', $missing)], 'failed', ['via' => $fetched['via'], 'columns' => $table['columns']]);
        }
        $errors = [];
        $seen = 0;
        $skipped = 0;
        foreach ($this->listingsFromRows($table['rows'], $cols, $settings, $skipped) as $listing) {
            $emit($listing);
            $seen++;
        }
        if ($skipped > 0) {
            $errors[] = "$skipped rows without a supplier SKU or GTIN skipped";
        }
        return inv_pull_stats($http, $seen, $errors, $seen > 0 || $table['rows'] === [] ? 'ok' : 'failed', ['via' => $fetched['via'], 'rows' => count($table['rows']), 'rows_skipped' => $skipped, 'format' => $table['format'], 'file_bytes' => strlen($fetched['bytes'])]);
    }

    public function search(array $source, string $q, int $limit = 20): array
    {
        throw new InvNotSupported('a feed has no search; Find answers from the last pull');
    }

    public function lookup(array $source, array $identifiers): array
    {
        throw new InvNotSupported('a feed has no lookup; the last pull holds every row');
    }

    /** The first rows for the mapping screen: ['columns', 'rows', 'format', 'row_count', 'mapped' (when a mapping exists), 'via']. */
    public function preview(array $source, int $rows = 5): array
    {
        $fetched = $this->fetch($source);
        if (!$fetched['ok']) {
            return ['ok' => false, 'error' => $fetched['error'], 'blocked' => $fetched['blocked'], 'via' => $fetched['via'], 'columns' => [], 'rows' => [], 'format' => null, 'row_count' => 0, 'mapped' => []];
        }
        $settings = $this->settings($source);
        $table = inv_feed_rows($fetched['bytes'], $settings);
        $out = ['ok' => true, 'error' => null, 'blocked' => false, 'via' => $fetched['via'], 'columns' => $table['columns'], 'rows' => array_slice($table['rows'], 0, max(1, $rows)), 'format' => $table['format'], 'row_count' => count($table['rows']), 'mapped' => []];
        $mapping = is_array($settings['mapping'] ?? null) ? $settings['mapping'] : [];
        if ($mapping !== []) {
            $missing = [];
            $cols = inv_feed_column_index($table['columns'], $mapping, $missing);
            foreach ($out['rows'] as $row) {
                $out['mapped'][] = inv_feed_map_row($row, $cols);
            }
            $out['missing'] = $missing;
        }
        return $out;
    }

    private function settings(array $source): array
    {
        $s = $source['settings'] ?? [];
        return is_string($s) ? (json_decode($s, true) ?: []) : (is_array($s) ? $s : []);
    }

    /** The file's bytes: ['ok', 'bytes', 'error', 'blocked', 'via' => https|sftp, 'http' => ?InvHttp]. */
    public function fetch(array $source): array
    {
        $settings = $this->settings($source);
        $cred = is_array($source['credential'] ?? null) ? $source['credential'] : null;
        $sftp = $settings['sftp'] ?? null;
        if (is_array($sftp) && !empty($sftp['host'])) {
            $r = inv_feed_sftp_fetch($sftp, $cred, $source['sftp_runner'] ?? null);
            return $r + ['via' => 'sftp', 'http' => null, 'blocked' => false];
        }
        $url = inv_str($settings['url'] ?? null, 2000);
        if ($url === null) {
            return ['ok' => false, 'bytes' => '', 'error' => 'no url and no sftp host in the settings', 'blocked' => false, 'via' => 'none', 'http' => null];
        }
        if (!preg_match('~^https://~i', $url) && !preg_match('~^http://(127\.0\.0\.1|localhost)~i', $url)) {
            return ['ok' => false, 'bytes' => '', 'error' => 'a feed URL must be https', 'blocked' => false, 'via' => 'https', 'http' => null];
        }
        // DECISION: a supplier's file at a URL they gave us is not a crawl — robots.txt is not consulted for it; the
        // rate limit, the honest UA, the ETag cache and the block detection still apply
        $http = inv_http_client($source, ['robots' => false, 'max_bytes' => 64 * 1024 * 1024, 'timeout' => 120]);
        $headers = ['Accept' => 'text/csv, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, */*'];
        if ($cred !== null && ($cred['kind'] ?? '') === 'basic') {
            $headers['Authorization'] = 'Basic ' . base64_encode((string) ($cred['username'] ?? '') . ':' . (string) ($cred['password'] ?? ''));
        }
        $r = $http->get($url, ['headers' => $headers]);
        if ($r['blocked']) {
            return ['ok' => false, 'bytes' => '', 'error' => $r['reason'], 'blocked' => true, 'via' => 'https', 'http' => $http];
        }
        if (!$r['ok']) {
            return ['ok' => false, 'bytes' => '', 'error' => $r['status'] === 401 ? 'HTTP 401: the file needs a credential' : ('HTTP ' . $r['status'] . ($r['reason'] !== null ? ' ' . $r['reason'] : '')), 'blocked' => false, 'via' => 'https', 'http' => $http];
        }
        return ['ok' => true, 'bytes' => $r['body'], 'error' => null, 'blocked' => false, 'via' => 'https', 'http' => $http];
    }

    /**
     * Rows → listings. DECISION: a feed row is one sellable unit; without a mapped `product` column every row is its
     * own listing with one variant, with it the rows sharing a product value become one listing with a variant each.
     */
    public function listingsFromRows(array $rows, array $cols, array $settings, int &$skipped = 0): array
    {
        $currency = inv_currency($settings['currency'] ?? null) ?? 'USD';
        $vendor = inv_str($settings['vendor'] ?? null);
        $leadDefault = inv_int($settings['lead_time_days'] ?? null);
        $groups = [];
        $order = [];
        foreach ($rows as $n => $row) {
            $m = inv_feed_map_row($row, $cols);
            $sku = inv_str($m['supplier_sku'] ?? null, 100);
            $gtin = inv_gtin_normalize($m['gtin'] ?? null);
            if ($sku === null && $gtin === null) {
                $skipped++;
                continue;
            }
            $name = inv_str($m['name'] ?? null, 300) ?? $sku ?? $gtin;
            $product = inv_str($m['product'] ?? null, 300);
            $key = $product ?? ($sku ?? $gtin);
            $qty = inv_int($m['qty'] ?? null);
            $inStock = $m['in_stock'] ?? null;
            $variant = [
                'external_variant_id' => $sku ?? $gtin,
                'title' => $name,
                'option_values' => array_filter(['Size' => inv_str($m['size'] ?? null, 100)]),
                'sku' => $sku,
                'barcode' => $m['gtin'] ?? null,
                'mpn' => $m['mpn'] ?? null,
                'price' => $m['price'] ?? null,
                'cost_price' => $m['cost'] ?? null,
                'currency' => inv_currency($m['currency'] ?? null) ?? $currency,
                'availability' => $inStock !== null && $inStock !== '' ? inv_availability_state($inStock, null, $qty) : null,
                'qty' => $qty,
                'lead_time_days' => inv_int($m['lead_time_days'] ?? null) ?? $leadDefault,
                'url' => $m['url'] ?? null,
            ];
            if (!isset($groups[$key])) {
                $order[] = $key;
                $groups[$key] = [
                    'external_id' => $key,
                    'handle' => null,
                    'url' => $m['url'] ?? null,
                    'title' => $product ?? $name,
                    'vendor' => inv_str($m['brand'] ?? null) ?? $vendor,
                    'product_type' => null,
                    'tags' => [],
                    'currency' => $currency,
                    'variants' => [],
                    'raw' => ['row' => $n + 1, 'map_price' => [], 'feed' => true],
                ];
            }
            $groups[$key]['variants'][] = $variant;
            $map = inv_money($m['map_price'] ?? null);
            if ($map !== null) {
                // DECISION: the MAP is not a field of the normalized variant (§6.1); it rides in raw.map_price[external_variant_id]
                $groups[$key]['raw']['map_price'][$variant['external_variant_id']] = $map;
            }
        }
        $out = [];
        foreach ($order as $key) {
            $out[] = inv_normalize_listing($groups[$key]);
        }
        return $out;
    }
}

/** The bytes → ['columns' => [header…], 'rows' => [[cell…]…], 'format' => csv|xlsx]. */
function inv_feed_rows(string $bytes, array $settings = []): array
{
    $format = strtolower((string) ($settings['format'] ?? 'auto'));
    if ($format === 'auto') {
        $format = str_starts_with($bytes, "PK\x03\x04") ? 'xlsx' : 'csv';
    }
    $skip = max(0, (int) ($settings['skip_rows'] ?? 0));
    $hasHeader = !array_key_exists('has_header', $settings) || (bool) $settings['has_header'];
    $rows = $format === 'xlsx' ? inv_feed_read_xlsx($bytes) : inv_feed_read_csv($bytes, $settings['delimiter'] ?? null, $settings['encoding'] ?? null);
    $rows = array_slice($rows, $skip);
    $rows = array_values(array_filter($rows, static fn($r) => array_filter($r, static fn($c) => trim((string) $c) !== '') !== []));
    if ($rows === []) {
        return ['columns' => [], 'rows' => [], 'format' => $format];
    }
    if ($hasHeader) {
        $columns = array_map(static fn($c) => trim((string) $c), array_shift($rows));
    } else {
        $columns = array_map(static fn($i) => 'Column ' . ($i + 1), array_keys($rows[0]));
    }
    $width = count($columns);
    $rows = array_map(static fn($r) => array_pad(array_slice(array_values($r), 0, $width), $width, ''), $rows);
    return ['columns' => $columns, 'rows' => array_slice($rows, 0, InvConnectorFeed::MAX_ROWS), 'format' => $format];
}

/** CSV bytes → rows. BOMs stripped, UTF-16 converted, the delimiter guessed from the first line when not given. */
function inv_feed_read_csv(string $bytes, ?string $delimiter = null, ?string $encoding = null): array
{
    if (str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF")) {
        $bytes = (string) mb_convert_encoding($bytes, 'UTF-8', str_starts_with($bytes, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        $bytes = ltrim($bytes, "\xEF\xBB\xBF");
    } elseif (str_starts_with($bytes, "\xEF\xBB\xBF")) {
        $bytes = substr($bytes, 3);
    } elseif ($encoding !== null && $encoding !== '' && strtoupper($encoding) !== 'UTF-8') {
        $bytes = (string) mb_convert_encoding($bytes, 'UTF-8', $encoding);
    } elseif (!mb_check_encoding($bytes, 'UTF-8')) {
        $bytes = (string) mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    }
    if ($delimiter === null || $delimiter === '' || $delimiter === 'auto') {
        $first = strtok($bytes, "\r\n") ?: '';
        $best = ',';
        $bestN = -1;
        foreach ([',', ';', "\t", '|'] as $d) {
            $n = substr_count($first, $d);
            if ($n > $bestN) {
                $best = $d;
                $bestN = $n;
            }
        }
        $delimiter = $best;
    } elseif ($delimiter === '\t' || strtolower($delimiter) === 'tab') {
        $delimiter = "\t";
    }
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $bytes);
    rewind($fh);
    $rows = [];
    while (($row = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
        if ($row === [null]) {
            continue;
        }
        $rows[] = array_map(static fn($c) => trim((string) $c), $row);
        if (count($rows) > InvConnectorFeed::MAX_ROWS + 1) {
            break;
        }
    }
    fclose($fh);
    return $rows;
}

/** XLSX bytes → rows of the first sheet (shared strings and inline strings resolved; dates left as serials). */
function inv_feed_read_xlsx(string $bytes): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('the zip extension is needed to read an XLSX feed');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'invfeed');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    try {
        if ($zip->open($tmp) !== true) {
            throw new RuntimeException('not a readable XLSX (zip)');
        }
        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false) {
            $x = inv_feed_xml($ss);
            foreach ($x->si as $si) {
                $text = '';
                if (isset($si->t)) {
                    $text = (string) $si->t;
                } else {
                    foreach ($si->r as $run) {
                        $text .= (string) $run->t;
                    }
                }
                $shared[] = $text;
            }
        }
        $sheetPath = 'xl/worksheets/sheet1.xml';
        if ($zip->locateName($sheetPath) === false) {
            // the first sheet of the workbook through its relationships
            $wb = $zip->getFromName('xl/workbook.xml');
            $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
            if ($wb !== false && $rels !== false) {
                $wbx = inv_feed_xml($wb);
                $wbx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $first = $wbx->xpath('//m:sheets/m:sheet')[0] ?? null;
                $rid = $first !== null ? (string) $first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id : '';
                $rx = inv_feed_xml($rels);
                foreach ($rx->Relationship as $rel) {
                    if ((string) $rel['Id'] === $rid) {
                        $sheetPath = 'xl/' . ltrim((string) $rel['Target'], '/');
                        $sheetPath = str_replace('xl/xl/', 'xl/', $sheetPath);
                    }
                }
            }
        }
        $sheet = $zip->getFromName($sheetPath);
        if ($sheet === false) {
            throw new RuntimeException('the XLSX has no worksheet');
        }
        $sx = inv_feed_xml($sheet);
        $rows = [];
        foreach ($sx->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $col = inv_feed_col_index(preg_replace('~\d+~', '', $ref) ?? '');
                $t = (string) $c['t'];
                $value = '';
                if ($t === 's') {
                    $value = $shared[(int) $c->v] ?? '';
                } elseif ($t === 'inlineStr') {
                    $value = (string) ($c->is->t ?? '');
                } elseif ($t === 'b') {
                    $value = ((string) $c->v) === '1' ? 'TRUE' : 'FALSE';
                } else {
                    $value = (string) $c->v;
                }
                $cells[$col] = $value;
            }
            if ($cells === []) {
                $rows[] = [];
                continue;
            }
            $max = max(array_keys($cells));
            $line = [];
            for ($i = 0; $i <= $max; $i++) {
                $line[] = trim((string) ($cells[$i] ?? ''));
            }
            $rows[] = $line;
            if (count($rows) > InvConnectorFeed::MAX_ROWS + 1) {
                break;
            }
        }
        return $rows;
    } finally {
        $zip->close();
        @unlink($tmp);
    }
}

/** SimpleXML with entities and network access off (an XLSX is somebody else's file). */
function inv_feed_xml(string $xml): SimpleXMLElement
{
    $prev = libxml_use_internal_errors(true);
    $x = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
    libxml_use_internal_errors($prev);
    if ($x === false) {
        throw new RuntimeException('the XLSX holds unreadable XML');
    }
    return $x;
}

/** "A" → 0, "AB" → 27. */
function inv_feed_col_index(string $letters): int
{
    $n = 0;
    foreach (str_split(strtoupper($letters)) as $ch) {
        if ($ch < 'A' || $ch > 'Z') {
            continue;
        }
        $n = $n * 26 + (ord($ch) - 64);
    }
    return max(0, $n - 1);
}

/** The mapping (field → header name or 0-based index) resolved to field → index; unknown headers land in $missing. */
function inv_feed_column_index(array $columns, array $mapping, array &$missing = []): array
{
    $lower = array_map(static fn($c) => strtolower(trim((string) $c)), $columns);
    $out = [];
    foreach ($mapping as $field => $col) {
        if (!in_array($field, InvConnectorFeed::FIELDS, true) || $col === null || $col === '') {
            continue;
        }
        if (is_int($col) || (is_string($col) && preg_match('~^\d+$~', $col))) {
            $i = (int) $col;
            if ($i < count($columns)) {
                $out[$field] = $i;
            } else {
                $missing[] = "$field → column $i";
            }
            continue;
        }
        $i = array_search(strtolower(trim((string) $col)), $lower, true);
        if ($i === false && preg_match('~^[A-Z]{1,2}$~i', (string) $col) && inv_feed_col_index((string) $col) < count($columns)) {
            $i = inv_feed_col_index((string) $col);  // a spreadsheet column letter
        }
        if ($i === false) {
            $missing[] = "$field → \"$col\"";
        } else {
            $out[$field] = (int) $i;
        }
    }
    return $out;
}

/** One row → field → cell value (strings). */
function inv_feed_map_row(array $row, array $cols): array
{
    $out = [];
    foreach ($cols as $field => $i) {
        $out[$field] = isset($row[$i]) ? trim((string) $row[$i]) : '';
    }
    return $out;
}

/** Which SFTP tool this server has: ssh2, curl (with sftp), sftp, or none. */
function inv_feed_sftp_tool(): string
{
    static $tool = null;
    if ($tool !== null) {
        return $tool;
    }
    if (function_exists('ssh2_connect')) {
        return $tool = 'ssh2';
    }
    $curl = trim((string) shell_exec('command -v curl 2>/dev/null'));
    if ($curl !== '' && preg_match('~\bsftp\b~', (string) shell_exec(escapeshellarg($curl) . ' -V 2>/dev/null'))) {
        return $tool = 'curl';
    }
    if (trim((string) shell_exec('command -v sftp 2>/dev/null')) !== '') {
        return $tool = 'sftp';
    }
    return $tool = 'none';
}

/**
 * Fetch a file over SFTP: ['ok', 'bytes', 'error']. $runner (tests) receives the command plan
 * ['tool' => …, 'argv' => […], 'files' => [path => mode]] and answers ['status' => int, 'bytes' => string, 'error' => ?string]
 * without a network; without a runner the plan is executed. The password goes in a 0600 netrc file, never on argv.
 */
function inv_feed_sftp_fetch(array $sftp, ?array $cred, ?callable $runner = null): array
{
    $host = inv_str($sftp['host'] ?? null, 253);
    $user = inv_str($sftp['user'] ?? ($cred['username'] ?? null), 100);
    $path = inv_str($sftp['path'] ?? null, 1000);
    $port = (int) ($sftp['port'] ?? 22) ?: 22;
    if ($host === null || $user === null || $path === null) {
        return ['ok' => false, 'bytes' => '', 'error' => 'sftp needs host, user and path'];
    }
    if (!preg_match('~^[a-z0-9.\-]+$~i', $host) || !preg_match('~^[a-z0-9._\-@]+$~i', $user)) {
        return ['ok' => false, 'bytes' => '', 'error' => 'sftp host or user holds characters a shell would read'];
    }
    $kind = $cred['kind'] ?? null;
    if ($kind !== 'sftp_password' && $kind !== 'sftp_key') {
        return ['ok' => false, 'bytes' => '', 'error' => 'sftp needs a credential of kind sftp_password or sftp_key'];
    }
    $tool = $runner !== null ? 'curl' : inv_feed_sftp_tool();
    if ($tool === 'ssh2') {
        return inv_feed_sftp_ssh2($host, $port, $user, $path, $cred);
    }
    if ($tool === 'none') {
        return ['ok' => false, 'bytes' => '', 'error' => 'no SFTP tool on this server (ssh2 extension, curl with sftp, or sftp)'];
    }
    $dir = sys_get_temp_dir() . '/inv-sftp-' . bin2hex(random_bytes(6));
    $files = [];
    $plan = ['tool' => $tool, 'argv' => [], 'files' => []];
    $url = 'sftp://' . $host . ':' . $port . '/' . ltrim($path, '/');
    if ($tool === 'curl') {
        $argv = ['curl', '--silent', '--show-error', '--fail', '--max-time', '300', '--insecure'];
        if ($kind === 'sftp_password') {
            $files[$dir . '/netrc'] = "machine $host login $user password " . str_replace(["\n", "\r"], '', (string) ($cred['password'] ?? '')) . "\n";
            $argv[] = '--netrc-file';
            $argv[] = $dir . '/netrc';
        } else {
            $files[$dir . '/key'] = rtrim((string) ($cred['private_key'] ?? '')) . "\n";
            array_push($argv, '--user', $user . ':', '--key', $dir . '/key', '--pubkey', '');
            if (!empty($cred['passphrase'])) {
                array_push($argv, '--pass', (string) $cred['passphrase']);
            }
        }
        $argv[] = $url;
        $plan['argv'] = $argv;
    } else { // sftp in batch mode: a password cannot be given non-interactively — keys only
        if ($kind !== 'sftp_key') {
            return ['ok' => false, 'bytes' => '', 'error' => 'the sftp command takes a key, not a password; give an sftp_key credential or install curl'];
        }
        $files[$dir . '/key'] = rtrim((string) ($cred['private_key'] ?? '')) . "\n";
        $files[$dir . '/batch'] = 'get ' . str_replace(["\n", "\r", ' '], '', $path) . ' ' . $dir . "/out\nquit\n";
        $plan['argv'] = ['sftp', '-q', '-P', (string) $port, '-i', $dir . '/key', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=accept-new', '-b', $dir . '/batch', $user . '@' . $host];
    }
    $plan['files'] = array_map(static fn($f) => 0600, $files);
    if ($runner !== null) {
        $r = $runner($plan);
        return ['ok' => (int) ($r['status'] ?? 1) === 0, 'bytes' => (string) ($r['bytes'] ?? ''), 'error' => $r['error'] ?? ((int) ($r['status'] ?? 1) === 0 ? null : 'sftp exit ' . ($r['status'] ?? '?'))];
    }
    if (!@mkdir($dir, 0700, true)) {
        return ['ok' => false, 'bytes' => '', 'error' => 'cannot make a private temp dir'];
    }
    try {
        foreach ($files as $f => $content) {
            file_put_contents($f, $content);
            chmod($f, 0600);
        }
        $cmd = implode(' ', array_map('escapeshellarg', $plan['argv']));
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
        if (!is_resource($proc)) {
            return ['ok' => false, 'bytes' => '', 'error' => 'cannot start ' . $tool];
        }
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($proc);
        if ($tool === 'sftp' && $status === 0) {
            $out = (string) @file_get_contents($dir . '/out');
        }
        if ($status !== 0) {
            // a transfer error never carries the password: curl prints the URL (no secret) and the error
            return ['ok' => false, 'bytes' => '', 'error' => $tool . ' exit ' . $status . ': ' . mb_substr(preg_replace('~\s+~', ' ', trim($err)) ?? '', 0, 160)];
        }
        return ['ok' => true, 'bytes' => $out, 'error' => null];
    } finally {
        foreach (array_merge(array_keys($files), [$dir . '/out']) as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}

function inv_feed_sftp_ssh2(string $host, int $port, string $user, string $path, array $cred): array
{
    $conn = @ssh2_connect($host, $port);
    if ($conn === false) {
        return ['ok' => false, 'bytes' => '', 'error' => "cannot connect to $host:$port"];
    }
    if (($cred['kind'] ?? '') === 'sftp_password') {
        $okAuth = @ssh2_auth_password($conn, $user, (string) ($cred['password'] ?? ''));
    } else {
        $dir = sys_get_temp_dir() . '/inv-sftp-' . bin2hex(random_bytes(6));
        @mkdir($dir, 0700, true);
        file_put_contents($dir . '/key', rtrim((string) ($cred['private_key'] ?? '')) . "\n");
        chmod($dir . '/key', 0600);
        $pub = (string) ($cred['public_key'] ?? '');
        file_put_contents($dir . '/key.pub', $pub);
        $okAuth = @ssh2_auth_pubkey_file($conn, $user, $dir . '/key.pub', $dir . '/key', (string) ($cred['passphrase'] ?? ''));
        @unlink($dir . '/key');
        @unlink($dir . '/key.pub');
        @rmdir($dir);
    }
    if (!$okAuth) {
        return ['ok' => false, 'bytes' => '', 'error' => 'sftp authentication refused'];
    }
    $sftp = @ssh2_sftp($conn);
    $bytes = $sftp !== false ? @file_get_contents('ssh2.sftp://' . intval($sftp) . '/' . ltrim($path, '/')) : false;
    if ($bytes === false) {
        return ['ok' => false, 'bytes' => '', 'error' => 'cannot read ' . $path];
    }
    return ['ok' => true, 'bytes' => $bytes, 'error' => null];
}
