<?php
declare(strict_types=1);
/**
 * GET /sources/{id}/credential — the credential form (screen `source-credential`); POST — action `source_credential_set` (log `source.credential_set`:
 * credential_id, kind, label, last4, rotated — nothing else; an agent's pauses — other): sealed, a new row, the old one deleted, the source resumed.
 * The secret is never pre-filled, echoed, logged or returned. sources.credentials.
 */
require_once dirname(__DIR__, 2) . '/app/features/sources/handler.php';
$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    require_right('sources.credentials');
    require_human();
    $s = source_or_404($pdo, request_integer('id') ?? request_integer('source'));
    $full = source_full($pdo, $s['source_id']);
    $kinds = inv_connectors()[$s['connector']]['capabilities']['credential_kinds'] ?? [];
    log_screen_view($pdo, 'source-credential');
    if (wants_json()) { respond_screen(['source' => present_source($s), 'credential' => $full['credential'], 'kinds' => $kinds]); }
    render_screen('Credential of ' . $s['name'], view('sources/credential.php', ['s' => $s, 'credential' => $full['credential'], 'kinds' => $kinds]),
        ['activeNav' => 'source-list', 'screen' => 'source-credential', 'entity' => 'source', 'recordId' => (string) $s['source_id']]);
    return;
}
source_write_begin('sources.credentials');
$s = source_or_404($pdo, request_integer('source') ?? request_integer('source_id') ?? request_integer('id'));
$kinds = inv_connectors()[$s['connector']]['capabilities']['credential_kinds'] ?? [];
$kind = (string) (req_val('kind') ?? '');
if ($kinds === []) { refuse(422, 'The ' . $s['connector'] . ' connector takes no credential.'); }
if (!in_array($kind, $kinds, true)) {
    inv_refuse_fields(['kind' => 'The ' . $s['connector'] . ' connector takes ' . implode(' or ', array_map(static fn ($k) => $k === 'bearer' ? 'a bearer token' : $k, $kinds)) . ', not ' . ($kind === '' ? 'nothing' : $kind) . '.']);
}
$label = trim((string) (req_val('label') ?? ''));
$errors = [];
if ($label === '' || mb_strlen($label) > 120) { $errors['label'] = 'Give the credential a label of up to 120 characters ("Storefront token (dealer)").'; }
$secretField = inv_credential_secret_field($kind);
$fields = [];
$want = match ($kind) { 'basic', 'sftp_password' => ['username', 'password'], 'sftp_key' => ['username', 'private_key', 'public_key', 'passphrase'], 'bearer' => ['token'],
    'oauth_client' => ['client_id', 'client_secret'], 'rsa_signing' => ['private_key', 'key_version'], 'api_key' => ['api_key'], default => [$secretField] };
foreach ($want as $k) {
    $v = (string) ($_POST[$k] ?? '');
    $v = $k === 'private_key' || $k === 'public_key' ? trim($v) : trim($v);
    if ($v !== '') { $fields[$k] = $v; }
}
if (($fields[$secretField] ?? '') === '') { $errors[$secretField] = 'Give the ' . str_replace('_', ' ', $secretField) . '.'; }
if (in_array($kind, ['basic', 'sftp_password', 'sftp_key'], true) && ($fields['username'] ?? '') === '') { $errors['username'] = 'Give the username.'; }
if ($errors !== []) { inv_refuse_fields($errors); }
$res = inv_guard($pdo, static function () use ($pdo, $s, $kind, $label, $fields): array {
    $pdo->beginTransaction();
    $res = set_credential($pdo, $s['source_id'], $kind, $label, $fields, (int) current_member_id());
    source_log($pdo, 'source.credential_set', 'source_credential', $res['credential_id'], $s['source_id'], ['credential_id' => $res['credential_id'], 'kind' => $kind, 'label' => $label, 'last4' => $res['last4'], 'rotated' => $res['rotated']]);
    $pdo->commit();
    return $res;
});
foreach (array_keys($fields) as $k) { unset($_POST[$k]); }
inv_done('Set the credential "' . $label . '" (…' . $res['last4'] . ')', $res['credential_id'], inv_land('/sources/' . $s['source_id'], 'credential', 'source-credential'), 'sourceChanged',
    ['credential_id' => $res['credential_id'], 'kind' => $kind, 'label' => $label, 'last4' => $res['last4'], 'rotated' => $res['rotated']]);
