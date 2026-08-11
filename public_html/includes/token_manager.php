<?php
/**
 * Token Security Manager
 *
 * Handles AES-256-CBC encryption and decryption of sensitive Meta Graph API access tokens.
 *
 * @package MetaPanel\Includes
 */

require_once __DIR__ . '/config.php';

class TokenManager {
    /**
     * Cipher algorithm
     */
    private const CIPHER = 'AES-256-CBC';

    /**
     * Encrypts a plain access token string.
     *
     * @param string $plainToken Token to encrypt
     * @return string Base64 encoded payload with IV
     */
    public static function encrypt(string $plainToken): string {
        if (empty($plainToken)) {
            return '';
        }

        // Derive 32-byte binary key from AES_KEY
        $key = hash('sha256', AES_KEY, true);
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = openssl_random_pseudo_bytes($ivLength);

        $encrypted = openssl_encrypt($plainToken, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        // Combine IV and encrypted data and base64 encode
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypts an encrypted token string.
     *
     * @param string $encryptedPayload Encrypted base64 token payload
     * @return string Plain token or empty string if decryption fails
     */
    public static function decrypt(string $encryptedPayload): string {
        if (empty($encryptedPayload)) {
            return '';
        }

        $raw = base64_decode($encryptedPayload, true);
        if ($raw === false) {
            return '';
        }

        $key = hash('sha256', AES_KEY, true);
        $ivLength = openssl_cipher_iv_length(self::CIPHER);

        if (strlen($raw) < $ivLength) {
            return '';
        }

        $iv = substr($raw, 0, $ivLength);
        $ciphertext = substr($raw, $ivLength);

        $decrypted = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        return $decrypted !== false ? $decrypted : '';
    }
}
