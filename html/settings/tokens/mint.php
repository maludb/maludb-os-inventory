<?php
declare(strict_types=1);
/** Action `token_mint` (log `token.mint`: the label and scope, never the value): a personal token, shown once on the next screen; JSON carries it once. People only (an agent never mints). Location /settings/tokens/#token-row-{id}; refresh tokenChanged. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/queries.php';
inv_handler_begin();
require_human();
$pdo = db();
$label = (string) req_val('label');
if ($label === '' || mb_strlen($label) > 80) {
    inv_refuse_fields(['label' => 'A label is required (up to 80 characters) — what will use this token.']);
}
$scope = (string) (req_val('scope') ?: 'mcp');
if (!in_array($scope, ['mcp', 'api'], true)) {
    inv_refuse_fields(['scope' => 'The scope is mcp or api.']);
}
$minted = inv_guard($pdo, static function () use ($pdo, $label, $scope): array {
    $minted = mint_token($pdo, (int) current_member_id(), $label, $scope);
    log_activity($pdo, 'token.mint', 'mcp_access_token', $minted['id'], ['after' => ['label' => $label, 'scope' => $scope], 'token_id' => $minted['id']]);
    return $minted;
});
$land = '/settings/tokens/#token-row-' . $minted['id'];
emit_action_status(true, ['did' => 'Minted the token "' . $label . '" — shown once', 'record_id' => $minted['id'], 'refresh' => 'tokenChanged']);
if (wants_json()) {
    respond_saved(['did' => 'Minted the token "' . $label . '" — shown once', 'record_id' => $minted['id'], 'token' => $minted['raw'], 'location' => $land, 'refresh' => 'tokenChanged']);   // the value, once
}
$_SESSION['minted_token'] = $minted;
saved_go('/settings/tokens/', 'tokenChanged');
