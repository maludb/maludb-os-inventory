<?php
declare(strict_types=1);

/**
 * A source's credential, sealed and opened — THE ONLY FILE THAT DECRYPTS (CLAUDE.md "Secrets"). libsodium secretbox
 * under INV_SECRETS_KEY (64 hex characters = 32 bytes) from config/.env. Nothing here logs, prints or keeps a copy;
 * an exception's message never carries the secret or the key.
 *
 *   inv_seal(['kind' => 'bearer', 'token' => '…'])  → "v1.<base64url nonce+box>"  (what source_credentials.ciphertext holds)
 *   inv_open($ciphertext)                            → the array, or InvCredentialError when it was tampered with or the key is wrong
 *   inv_credential_summary($cred)                    → ['kind', 'label', 'last4'] — what a screen may show
 */

final class InvCredentialError extends RuntimeException
{
}

/** The credential kinds of §6 source_credentials.kind. */
const INV_CREDENTIAL_KINDS = ['api_key', 'oauth_client', 'basic', 'sftp_password', 'sftp_key', 'rsa_signing', 'bearer'];

/** The 32-byte key from INV_SECRETS_KEY (getenv, the kit's env() when loaded, $_ENV) — never cached in a global. */
function inv_secrets_key(): string
{
    $hex = getenv('INV_SECRETS_KEY');
    if (($hex === false || $hex === '') && function_exists('env')) {
        $hex = env('INV_SECRETS_KEY');
    }
    if (($hex === false || $hex === null || $hex === '') && isset($_ENV['INV_SECRETS_KEY'])) {
        $hex = $_ENV['INV_SECRETS_KEY'];
    }
    if (!is_string($hex) || !preg_match('~^[0-9a-fA-F]{64}$~', trim($hex))) {
        throw new InvCredentialError('secrets_key_missing: INV_SECRETS_KEY must be 64 hex characters in config/.env');
    }
    $key = sodium_hex2bin(trim($hex));
    if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new InvCredentialError('secrets_key_invalid');
    }
    return $key;
}

/** A fresh key for the installer (hex). */
function inv_secrets_key_generate(): string
{
    return sodium_bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
}

/** Seal a credential array; the result is text safe for a bytea or text column. */
function inv_seal(array $cred): string
{
    if (!isset($cred['kind']) || !in_array($cred['kind'], INV_CREDENTIAL_KINDS, true)) {
        throw new InvCredentialError('credential_kind_invalid');
    }
    $key = inv_secrets_key();
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = json_encode($cred, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $box = sodium_crypto_secretbox($plain, $nonce, $key);
    sodium_memzero($plain);
    sodium_memzero($key);
    return 'v1.' . rtrim(strtr(base64_encode($nonce . $box), '+/', '-_'), '=');
}

/** Open a sealed credential; InvCredentialError on a wrong key, a tampered box or an unknown version. */
function inv_open(string $ciphertext): array
{
    $ciphertext = trim($ciphertext);
    if (!str_starts_with($ciphertext, 'v1.')) {
        throw new InvCredentialError('credential_unreadable: unknown version');
    }
    $bin = base64_decode(strtr(substr($ciphertext, 3), '-_', '+/'), true);
    if ($bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
        throw new InvCredentialError('credential_unreadable: malformed');
    }
    $nonce = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $box = substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $key = inv_secrets_key();
    $plain = sodium_crypto_secretbox_open($box, $nonce, $key);
    sodium_memzero($key);
    if ($plain === false) {
        throw new InvCredentialError('credential_unreadable: the seal does not open (tampered, or sealed under another key)');
    }
    $cred = json_decode($plain, true);
    sodium_memzero($plain);
    if (!is_array($cred) || !isset($cred['kind'])) {
        throw new InvCredentialError('credential_unreadable: not a credential');
    }
    return $cred;
}

/** The secret-bearing field of a credential, by kind. */
function inv_credential_secret_field(string $kind): string
{
    return match ($kind) {
        'api_key' => 'api_key',
        'oauth_client' => 'client_secret',
        'basic', 'sftp_password' => 'password',
        'sftp_key' => 'private_key',
        'rsa_signing' => 'private_key',
        'bearer' => 'token',
        default => 'secret',
    };
}

/** What a screen shows: the kind, the label and the last four characters of the secret — never more. */
function inv_credential_summary(array $cred): array
{
    $kind = (string) ($cred['kind'] ?? '');
    $secret = (string) ($cred[inv_credential_secret_field($kind)] ?? '');
    $last4 = $secret === '' ? '' : ($kind === 'sftp_key' || $kind === 'rsa_signing'
        ? substr(hash('sha256', $secret), 0, 4)   // a key's fingerprint prefix, not its tail
        : substr($secret, -4));
    return ['kind' => $kind, 'label' => (string) ($cred['label'] ?? ($cred['username'] ?? '')), 'last4' => $last4];
}
