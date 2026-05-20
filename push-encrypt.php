<?php
/**
 * push-encrypt.php — VAPID JWT + RFC 8291 (aes128gcm) payload encryption
 * Yêu cầu: PHP 7.3+, OpenSSL extension
 */

if (!defined('VAPID_PUBLIC_KEY')) {
    define('VAPID_PUBLIC_KEY',  'BH6-XOsyTWZG-ebLHimL-6-0OqhcEs0nTI9FRVoS4fF_zCw9vi48BtsSOr0Nit-X3d4JkR1DJ_ZVjKcwbSMaWLc');
    define('VAPID_PRIVATE_KEY', 'ZLDpySCOEG9y7MKztlrv4BgPLP4txxy1l2Rp9ZxRrDg');
    define('VAPID_SUBJECT',     'mailto:support@rni.institute');
}

// ── Base64URL helpers ─────────────────────────────────────────────────────────
function b64u_enc(string $s): string {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}
function b64u_dec(string $s): string {
    return base64_decode(str_pad(strtr($s, '-_', '+/'), strlen($s) + (4 - strlen($s) % 4) % 4, '='));
}

// ── HKDF-SHA256 ───────────────────────────────────────────────────────────────
function hkdf_extract(string $salt, string $ikm): string {
    return hash_hmac('sha256', $ikm, $salt, true);
}
function hkdf_expand(string $prk, string $info, int $len): string {
    $t = ''; $okm = '';
    for ($i = 1; strlen($okm) < $len; $i++) {
        $t    = hash_hmac('sha256', $t . $info . chr($i), $prk, true);
        $okm .= $t;
    }
    return substr($okm, 0, $len);
}

// ── DER helpers ───────────────────────────────────────────────────────────────
function _der_len(string $content): string {
    $len = strlen($content);
    if ($len <= 127) return chr($len);
    if ($len <= 255) return "\x81" . chr($len);
    return "\x82" . chr($len >> 8) . chr($len & 0xff);
}

// SubjectPublicKeyInfo DER cho P-256 (65-byte uncompressed point)
function ec_pub_to_pem(string $pub65): string {
    $algid  = "\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
             ."\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";   // 21 bytes
    $bitstr = "\x03\x42\x00" . $pub65;                        // 68 bytes
    $body   = $algid . $bitstr;                                // 89 bytes
    $der    = "\x30\x59" . $body;
    return "-----BEGIN PUBLIC KEY-----\n"
         . chunk_split(base64_encode($der), 64, "\n")
         . "-----END PUBLIC KEY-----";
}

// PKCS#8 PrivateKeyInfo DER cho P-256
function ec_priv_to_pem(string $priv32, string $pub65): string {
    // ECPrivateKey (RFC 5915 §3)
    $ec_body = "\x02\x01\x01"            // version = 1
             . "\x04\x20" . $priv32      // OCTET STRING(32) = private key
             . "\xa1\x44\x03\x42\x00" . $pub65; // [1] BIT STRING(65)
    $eckey   = "\x30" . _der_len($ec_body) . $ec_body;  // SEQUENCE

    // AlgorithmIdentifier
    $algid = "\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
            ."\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";

    // PKCS#8 PrivateKeyInfo
    $pki_body = "\x02\x01\x00"                             // version = 0
              . $algid                                      // AlgorithmIdentifier
              . "\x04" . _der_len($eckey) . $eckey;        // OCTET STRING(ECPrivateKey)
    $der      = "\x30" . _der_len($pki_body) . $pki_body;

    return "-----BEGIN PRIVATE KEY-----\n"
         . chunk_split(base64_encode($der), 64, "\n")
         . "-----END PRIVATE KEY-----";
}

// ── VAPID JWT + headers ───────────────────────────────────────────────────────
function make_vapid_headers(string $endpoint, int $body_len): ?array {
    $parts  = parse_url($endpoint);
    $aud    = $parts['scheme'] . '://' . $parts['host'];
    $exp    = time() + 43200;

    $hdr    = b64u_enc(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = b64u_enc(json_encode(['aud' => $aud, 'exp' => $exp, 'sub' => VAPID_SUBJECT]));
    $signing = $hdr . '.' . $claims;

    $priv_raw = b64u_dec(VAPID_PRIVATE_KEY);
    $pub_raw  = b64u_dec(VAPID_PUBLIC_KEY);

    $pkey = openssl_pkey_get_private(ec_priv_to_pem($priv_raw, $pub_raw));
    if (!$pkey) {
        error_log('[RNI Push] Cannot load VAPID private key');
        return null;
    }

    openssl_sign($signing, $sig_der, $pkey, OPENSSL_ALGO_SHA256);

    // DER ECDSA signature → R||S 64 bytes
    $pos   = 4;
    $r_len = ord($sig_der[3]);
    if (ord($sig_der[4]) === 0) { $r_len--; $pos++; }
    $r     = substr($sig_der, $pos, $r_len);
    $pos  += $r_len + 2;
    $s_len = ord($sig_der[$pos - 1]);
    if (ord($sig_der[$pos]) === 0) { $s_len--; $pos++; }
    $s   = substr($sig_der, $pos, $s_len);
    $r   = str_pad($r, 32, "\x00", STR_PAD_LEFT);
    $s   = str_pad($s, 32, "\x00", STR_PAD_LEFT);
    $jwt = $signing . '.' . b64u_enc($r . $s);

    return [
        'Authorization'    => 'vapid t=' . $jwt . ', k=' . VAPID_PUBLIC_KEY,
        'Content-Type'     => 'application/octet-stream',
        'Content-Encoding' => 'aes128gcm',
        'Content-Length'   => (string) $body_len,
        'TTL'              => '86400',
    ];
}

// ── RFC 8291 — aes128gcm payload encryption ───────────────────────────────────
function encrypt_web_push(string $plaintext, string $p256dh_b64u, string $auth_b64u): string {
    $ua_pub     = b64u_dec($p256dh_b64u); // 65 bytes uncompressed point
    $auth_bytes = b64u_dec($auth_b64u);   // 16 bytes auth secret

    // Ephemeral P-256 key pair
    $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$eph) throw new \RuntimeException('Cannot generate ephemeral EC key');
    $eph_d = openssl_pkey_get_details($eph);
    $eph_pub = "\x04"
             . str_pad($eph_d['ec']['x'], 32, "\x00", STR_PAD_LEFT)
             . str_pad($eph_d['ec']['y'], 32, "\x00", STR_PAD_LEFT);

    // ECDH
    $ua_pub_key    = openssl_pkey_get_public(ec_pub_to_pem($ua_pub));
    $shared_secret = openssl_pkey_derive($ua_pub_key, $eph, 32);
    if (!$shared_secret) throw new \RuntimeException('ECDH failed');

    // RFC 8291 §3.3 key derivation
    $prk_key  = hkdf_extract($auth_bytes, $shared_secret);
    $key_info = "WebPush: info\x00" . $ua_pub . $eph_pub;
    $ikm      = hkdf_expand($prk_key, $key_info, 32);

    $salt  = random_bytes(16);
    $prk   = hkdf_extract($salt, $ikm);
    $cek   = hkdf_expand($prk, "Content-Encoding: aes128gcm\x00", 16);
    $nonce = hkdf_expand($prk, "Content-Encoding: nonce\x00", 12);

    // AES-128-GCM — plaintext + delimiter 0x02
    $tag = '';
    $ct  = openssl_encrypt($plaintext . "\x02", 'aes-128-gcm', $cek,
                           OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ct === false) throw new \RuntimeException('AES-GCM encrypt failed');
    $ciphertext = $ct . $tag;

    // RFC 8188 content-coding header: salt(16) + rs(4BE) + idlen(1) + keyid(65)
    return $salt . pack('N', 4096) . chr(65) . $eph_pub . $ciphertext;
}
