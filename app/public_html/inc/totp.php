<?php
// Tweede inlogstap: tijdcodes (TOTP, RFC 6238) zoals Microsoft Authenticator, Google Authenticator of 1Password ze maken. 28-11-2026.
const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function totp_nieuw_geheim(): string
{
    $b = random_bytes(20); $bits = '';
    foreach (str_split($b) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $uit = '';
    foreach (str_split($bits, 5) as $blok) $uit .= B32[bindec(str_pad($blok, 5, '0'))];
    return $uit;
}
function totp_bytes(string $geheim): string
{
    $bits = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $geheim))) as $c) $bits .= str_pad(decbin(strpos(B32, $c)), 5, '0', STR_PAD_LEFT);
    $uit = '';
    foreach (str_split($bits, 8) as $blok) if (strlen($blok) === 8) $uit .= chr(bindec($blok));
    return $uit;
}
function totp_code(string $geheim, int $stap): string
{
    $h = hash_hmac('sha1', pack('N2', 0, $stap), totp_bytes($geheim), true);
    $o = ord($h[19]) & 0x0f;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}
/** Klopt de code? Een halve minuut speling naar voren en achteren (klok van de telefoon). */
function totp_klopt(string $geheim, string $code, ?int $tijd = null): bool
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return false;
    $stap = intdiv($tijd ?? time(), 30);
    foreach ([0, -1, 1] as $d) if (hash_equals(totp_code($geheim, $stap + $d), $code)) return true;
    return false;
}
function totp_link(string $geheim, string $gebruiker): string
{
    return 'otpauth://totp/' . rawurlencode('Watt Watt:' . $gebruiker) . '?secret=' . $geheim . '&issuer=' . rawurlencode('Watt Watt') . '&digits=6&period=30';
}
