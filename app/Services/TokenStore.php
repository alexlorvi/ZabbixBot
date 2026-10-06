<?php

namespace ZabbixBot\Services;

/**
 * Персональні Zabbix API-токени користувачів: <dir>/<chat_id>.key.
 * З ключем шифрування (base64, 32 байти) - sodium secretbox ("enc1:..."), без нього - JSON з правами 0600.
 * Читає також старий формат (голий токен з 64 символів) і при можливості одразу перешифровує.
 */
class TokenStore
{
    private ?string $key = null;

    public function __construct(private readonly string $dir, ?string $keyBase64 = null)
    {
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0700, true);
        }
        if ($keyBase64 !== null && $keyBase64 !== '') {
            if (!function_exists('sodium_crypto_secretbox')) {
                throw new \RuntimeException('token_key заданий, але розширення sodium відсутнє');
            }
            $key = base64_decode($keyBase64, true);
            if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new \RuntimeException('token_key має бути base64 від 32 байтів');
            }
            $this->key = $key;
        }
    }

    /** @return array{token:string,exp:?int}|null */
    public function get(string $chatId): ?array
    {
        $raw = @file_get_contents($this->path($chatId));
        if ($raw === false) {
            return null;
        }
        $raw = trim($raw);
        $legacy = false;
        if (str_starts_with($raw, 'enc1:')) {
            $json = $this->decrypt(substr($raw, 5));
            $rec = $json === null ? null : json_decode($json, true);
        } elseif (str_starts_with($raw, '{')) {
            $rec = json_decode($raw, true);
        } elseif (strlen($raw) === 64) {
            $rec = ['token' => $raw, 'exp' => null];
            $legacy = true;
        } else {
            return null;
        }
        if (!is_array($rec) || !isset($rec['token']) || strlen((string)$rec['token']) !== 64) {
            return null;
        }
        $out = ['token' => (string)$rec['token'], 'exp' => isset($rec['exp']) ? (int)$rec['exp'] : null];
        if (($legacy || str_starts_with($raw, '{')) && $this->key !== null) {
            $this->put($chatId, $out['token'], $out['exp']); // міграція на шифрований формат
        }
        return $out;
    }

    public function put(string $chatId, string $token, ?int $exp): bool
    {
        $json = json_encode(['token' => $token, 'exp' => $exp], JSON_THROW_ON_ERROR);
        $data = $this->key !== null ? 'enc1:'.$this->encrypt($json) : $json;
        $file = $this->path($chatId);
        $tmp = $file.'.'.getmypid().'.tmp';
        if (file_put_contents($tmp, $data, LOCK_EX) === false || !chmod($tmp, 0600) || !rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    private function path(string $chatId): string
    {
        return $this->dir.'/'.preg_replace('/[^0-9-]/', '', $chatId).'.key';
    }

    private function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, (string)$this->key));
    }

    private function decrypt(string $b64): ?string
    {
        if ($this->key === null) {
            return null;
        }
        $bin = base64_decode($b64, true);
        if ($bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
        return $plain === false ? null : $plain;
    }
}
