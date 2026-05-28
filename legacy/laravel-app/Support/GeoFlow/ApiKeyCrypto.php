<?php

namespace App\Support\GeoFlow;

use RuntimeException;

/**
 * API Key 加解密工具（仅支持 enc:v1 存储格式）。
 *
 * 设计目标：
 * 1. 仅从 config('geoflow.api_key_crypto_roots') 派生加解密密钥（配置层绑定 APP_KEY），禁止应用代码直接 env()；
 * 2. 对外提供稳定的 encrypt/decrypt/mask 三个方法；
 * 3. 禁止明文落库：加密失败时直接抛错，不回退明文。
 */
class ApiKeyCrypto
{
    /**
     * 写入 ai_models 前对 API Key 加密（enc:v1）。
     */
    public function encrypt(string $apiKey): string
    {
        $plainText = trim($apiKey);
        if ($plainText === '') {
            return '';
        }

        $keys = $this->resolveEncryptionKeys();
        $primaryKey = $keys[0] ?? null;
        if (! is_string($primaryKey) || $primaryKey === '') {
            throw new RuntimeException('APP_KEY 未配置或 config(geoflow.api_key_crypto_roots) 为空，无法加密 API Key');
        }

        $iv = random_bytes(16);
        $cipherText = openssl_encrypt($plainText, 'AES-256-CBC', $primaryKey, OPENSSL_RAW_DATA, $iv);
        if ($cipherText === false) {
            throw new RuntimeException('API Key 加密失败');
        }

        return 'enc:v1:'.base64_encode($iv.$cipherText);
    }

    /**
     * 读取 ai_models 中 API Key（仅接受 enc:v1）。
     */
    public function decrypt(string $storedApiKey): string
    {
        if ($storedApiKey === '') {
            return '';
        }
        if (! str_starts_with($storedApiKey, 'enc:v1:')) {
            return '';
        }

        $payload = base64_decode(substr($storedApiKey, 7), true);
        if ($payload === false || strlen($payload) <= 16) {
            return '';
        }

        $iv = substr($payload, 0, 16);
        $cipherText = substr($payload, 16);
        foreach ($this->resolveEncryptionKeys() as $key) {
            $plainText = openssl_decrypt($cipherText, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
            if ($plainText !== false) {
                return $plainText;
            }
        }

        return '';
    }

    /**
     * 对 API Key 做掩码显示，避免后台泄露完整密钥。
     */
    public function mask(string $storedApiKey): string
    {
        $plainApiKey = $this->decrypt($storedApiKey);
        $length = strlen($plainApiKey);
        if ($length <= 8) {
            return str_repeat('*', max($length, 4));
        }

        return substr($plainApiKey, 0, 4).str_repeat('*', max($length - 8, 8)).substr($plainApiKey, -4);
    }

    /**
     * 从配置解析加解密密钥（与 config/geoflow.php 中 api_key_crypto_roots 顺序一致，便于密钥轮换时兼容旧密文）。
     *
     * @return list<string>
     */
    private function resolveEncryptionKeys(): array
    {
        $keys = [];
        $roots = config('geoflow.api_key_crypto_roots', []);
        if (! is_array($roots)) {
            $roots = [];
        }

        foreach ($roots as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }
            if ($candidate === '') {
                continue;
            }

            $normalized = $candidate;
            if (str_starts_with($normalized, 'base64:')) {
                $decoded = base64_decode(substr($normalized, 7), true);
                if ($decoded !== false) {
                    $normalized = $decoded;
                }
            }

            $derived = hash('sha256', $normalized, true);
            $exists = false;
            foreach ($keys as $current) {
                if (hash_equals($current, $derived)) {
                    $exists = true;
                    break;
                }
            }
            if (! $exists) {
                $keys[] = $derived;
            }
        }

        return $keys;
    }
}
