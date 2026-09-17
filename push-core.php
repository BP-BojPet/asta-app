<?php
/**
 * Web-Push-Kern: VAPID (ES256) + Payload-Verschlüsselung nach RFC 8291 (aes128gcm)
 * in purem PHP – OpenSSL, hash_hkdf und curl genügen, keine Composer-Abhängigkeiten.
 *
 * EIGENSTÄNDIG wie der dav_*-Kern: keine lib.php, kein Datenbank-Zugriff, keine Session.
 * Hier steht nur die Mathematik und der Transport; wo Schlüssel und Abos GESPEICHERT
 * werden, entscheidet der Aufrufer. Zwei Welten teilen sich diesen Kern:
 *  • die App (lib.php: push_vapid()/push_send() mit den App-Tabellen), und
 *  • was.läuft (wl-db.php: eigene Schlüssel + Abos in der EIGENEN Datenbank –
 *    die öffentlichen Seiten binden lib.php absichtlich nie ein).
 *
 * Zustellung übernehmen die Push-Dienste der Browser (FCM/Apple/Mozilla);
 * der Server POSTet nur eine verschlüsselte Nachricht an deren Endpunkte.
 */

declare(strict_types=1);

/** base64url ohne Padding (Web-Push-Standardkodierung). */
function b64u_encode(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function b64u_decode(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/')); }

/** Bringt der Server alles mit? (wird u. a. im Selbsttest geprüft) */
function push_available(): bool
{
    return extension_loaded('openssl') && extension_loaded('curl')
        && function_exists('hash_hkdf') && function_exists('openssl_pkey_derive');
}

/** Öffentlicher EC-P-256-Schlüssel als rohe 65 Bytes (0x04 || X || Y). */
function push_ec_pub_raw($key): string
{
    $d = openssl_pkey_get_details($key);
    if (!$d || ($d['type'] ?? -1) !== OPENSSL_KEYTYPE_EC || empty($d['ec']['x'])) return '';
    return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT)
                  . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}

/** Frisches VAPID-Schlüsselpaar ['pem' => …, 'pub' => …] – der Aufrufer legt es ab. */
function push_core_keypair(): ?array
{
    if (!push_available()) return null;
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $pem = '';
    if (!$key || !openssl_pkey_export($key, $pem)) return null;
    return ['pem' => $pem, 'pub' => b64u_encode(push_ec_pub_raw($key))];
}

/** DER-kodierte ECDSA-Signatur (SEQUENCE{r,s}) → rohes r||s (2×32 Bytes), wie JWT es braucht. */
function push_der_sig_to_raw(string $der): string
{
    if ($der === '' || ord($der[0]) !== 0x30) return '';
    $off = (ord($der[1]) & 0x80) ? 2 + (ord($der[1]) & 0x7f) : 2;
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        if (!isset($der[$off]) || ord($der[$off]) !== 0x02) return '';
        $len = ord($der[$off + 1]);
        $int = ltrim(substr($der, $off + 2, $len), "\0");
        if (strlen($int) > 32) return '';
        $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
        $off += 2 + $len;
    }
    return $out;
}

/** ES256-JWT für den VAPID-Header (Authorization: vapid t=…, k=…).
 *  Das „sub" ist die Kontaktadresse des Absenders – daran meldet sich ein Push-Dienst, wenn
 *  mit den Zustellungen etwas nicht stimmt. Sie kommt aus $vapid['sub'] vom Aufrufer; dieser
 *  Kern kennt keine Einstellungen. Fehlt sie, wird der eigene Host genommen: besser eine
 *  stumpfe, aber zutreffende Angabe als die Adresse einer fremden Installation. */
function push_vapid_jwt(string $endpoint, array $vapid): string
{
    $aud = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
    $sub = trim((string)($vapid['sub'] ?? ''));
    if ($sub === '') $sub = 'https://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $seg = fn(array $a) => b64u_encode((string)json_encode($a, JSON_UNESCAPED_SLASHES));
    $data = $seg(['typ' => 'JWT', 'alg' => 'ES256']) . '.'
          . $seg(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $sub]);
    $der = '';
    openssl_sign($data, $der, $vapid['pem'], OPENSSL_ALGO_SHA256);
    return $data . '.' . b64u_encode(push_der_sig_to_raw($der));
}

/**
 * Verschlüsselt eine Payload nach RFC 8291 (aes128gcm) für ein Abo.
 * Rückgabe: fertiger Binär-Body inkl. Header-Block – oder null bei kaputten Schlüsseln.
 */
function push_encrypt(string $payload, string $p256dh, string $auth): ?string
{
    $uaPub = b64u_decode($p256dh);      // öffentlicher Schlüssel des Browsers (65 Bytes)
    $authSecret = b64u_decode($auth);   // gemeinsames Auth-Secret (16 Bytes)
    if (strlen($uaPub) !== 65 || $uaPub[0] !== "\x04" || strlen($authSecret) !== 16) return null;

    $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$eph) return null;
    $asPub = push_ec_pub_raw($eph);

    // Client-Schlüssel als SPKI/PEM verpacken (DER-Prefix für P-256-Publickeys)
    $peerPem = "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode((string)hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $uaPub), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
    $peer = openssl_pkey_get_public($peerPem);
    if (!$peer) return null;
    $shared = openssl_pkey_derive($peer, $eph);   // ECDH (P-256 -> 32 Bytes)
    if (!is_string($shared) || strlen($shared) !== 32) return null;

    $ikm   = hash_hkdf('sha256', $shared, 32, "WebPush: info\x00" . $uaPub . $asPub, $authSecret);
    $salt  = random_bytes(16);
    $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

    $tag = '';
    $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ct === false) return null;

    // aes128gcm-Header: salt(16) | record size(4) | idlen(1) | as_public(65), danach Ciphertext+Tag
    return $salt . pack('N', 4096) . chr(65) . $asPub . $ct . $tag;
}

/**
 * POSTet eine fertige JSON-Payload verschlüsselt an EIN Abo ['endpoint','p256dh','auth'].
 * $vapid ist das Schlüsselpaar des Absenders (App ODER was.läuft – deshalb Parameter).
 * $ttl: wie lange der Push-Dienst die Nachricht für ein offline-Gerät aufhebt – eine
 * Erinnerung „in 2 Stunden geht's los" ist nach der Veranstaltung nichts mehr wert.
 * Rückgabe: HTTP-Code (0 = Transportfehler); 404/410 heißt „Abo tot, wegräumen".
 */
function push_core_deliver(array $sub, string $json, array $vapid, int $ttl = 86400): int
{
    $body = push_encrypt($json, (string)$sub['p256dh'], (string)$sub['auth']);
    if ($body === null) return 0;
    $ch = curl_init((string)$sub['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_HTTPHEADER => [
            'TTL: ' . max(60, $ttl),
            'Urgency: normal',
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'Content-Length: ' . strlen($body),
            'Authorization: vapid t=' . push_vapid_jwt((string)$sub['endpoint'], $vapid) . ', k=' . $vapid['pub'],
        ],
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $code;
}
