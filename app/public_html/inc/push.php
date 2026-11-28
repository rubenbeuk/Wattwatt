<?php
// Pushmeldingen (ronde 3, 28-11-2026): Web Push met VAPID, zonder inhoud in het bericht zelf.
// De telefoon krijgt alleen een "tik"; de service worker haalt daarna zelf het nieuwste seintje op (api_push.php?laatste).
// Zo hoeft er geen versleuteling van de inhoud in pure PHP, en staat er geen klant- of projecttekst bij Google of Apple.
// De sleutels (P-256) maakt de app zelf de eerste keer en bewaart ze in brein_stand 'vapid'.

function b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

/** ['pem' => private key PEM, 'pub' => publieke sleutel (65 bytes, base64url)] */
function vapid_sleutels(): ?array
{
    static $k = null;
    if ($k) return $k;
    $r = q("SELECT waarde FROM brein_stand WHERE sleutel = 'vapid'")->fetchColumn();
    if ($r && ($k = json_decode($r, true))) return $k;
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    if (!$key || !openssl_pkey_export($key, $pem)) return null;
    $det = openssl_pkey_get_details($key);
    $pub = "\x04" . str_pad($det['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($det['ec']['y'], 32, "\0", STR_PAD_LEFT);
    $k = ['pem' => $pem, 'pub' => b64url($pub)];
    q("INSERT IGNORE INTO brein_stand (sleutel, waarde) VALUES ('vapid', ?)", [json_encode($k)]);
    // Twee verzoeken tegelijk: neem wat er nu staat
    $k = json_decode((string)q("SELECT waarde FROM brein_stand WHERE sleutel = 'vapid'")->fetchColumn(), true);
    return $k;
}

/** DER-handtekening (ECDSA) omzetten naar de 64 bytes r||s die JWT ES256 wil. */
function der_naar_rs(string $der): string
{
    $pos = 2; if (ord($der[1]) & 0x80) $pos += ord($der[1]) & 0x7f;
    $uit = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$pos + 1]);
        $int = substr($der, $pos + 2, $len);
        $uit .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $len;
    }
    return $uit;
}

function vapid_jwt(string $endpoint, array $k): ?string
{
    $p = parse_url($endpoint);
    if (empty($p['scheme']) || empty($p['host'])) return null;
    $kop = b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = b64url(json_encode(['aud' => $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''), 'exp' => time() + 12 * 3600, 'sub' => 'https://wattwatt.nl']));
    if (!openssl_sign("$kop.$claims", $sig, $k['pem'], OPENSSL_ALGO_SHA256)) return null;
    return "$kop.$claims." . b64url(der_naar_rs($sig));
}

/** Tik naar alle telefoons van een gebruiker. Best effort, kort time-out; mislukt het, dan blijft het seintje gewoon in de app staan. */
function push_tik(int $gid): int
{
    if (!function_exists('curl_multi_init')) return 0;
    $subs = q('SELECT id, endpoint FROM push_abonnementen WHERE gebruiker_id = ? AND fouten < 5', [$gid])->fetchAll();
    if (!$subs || !($k = vapid_sleutels())) return 0;
    $mh = curl_multi_init(); $hs = [];
    foreach ($subs as $s) {
        if (!str_starts_with($s['endpoint'], 'https://') || !($jwt = vapid_jwt($s['endpoint'], $k))) continue;
        $h = curl_init($s['endpoint']);
        curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['TTL: 86400', 'Urgency: high', 'Content-Length: 0', 'Authorization: vapid t=' . $jwt . ', k=' . $k['pub']]]);
        curl_multi_add_handle($mh, $h); $hs[(int)$s['id']] = $h;
    }
    do { $st = curl_multi_exec($mh, $actief); if ($actief) curl_multi_select($mh, 1); } while ($actief && $st === CURLM_OK);
    $ok = 0;
    foreach ($hs as $id => $h) {
        $code = (int)curl_getinfo($h, CURLINFO_HTTP_CODE);
        if ($code >= 200 && $code < 300) { $ok++; q('UPDATE push_abonnementen SET laatst_ok = UTC_TIMESTAMP(), fouten = 0 WHERE id = ?', [$id]); }
        elseif ($code === 404 || $code === 410) q('DELETE FROM push_abonnementen WHERE id = ?', [$id]);   // abonnement bestaat niet meer
        else q('UPDATE push_abonnementen SET fouten = fouten + 1 WHERE id = ?', [$id]);
        curl_multi_remove_handle($mh, $h); curl_close($h);
    }
    curl_multi_close($mh);
    return $ok;
}

/** Aan het eind van het verzoek tikken, zodat de gebruiker er niet op wacht (na fastcgi_finish_request). */
function push_later(int $gid): void
{
    static $wachtrij = null;
    if ($wachtrij === null) {
        $wachtrij = [];
        register_shutdown_function(function () use (&$wachtrij) {
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            foreach (array_keys($wachtrij) as $g) { try { push_tik($g); } catch (Throwable $e) { /* best effort */ } }
        });
    }
    $wachtrij[$gid] = true;
}
