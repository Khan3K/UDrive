<?php
namespace UDrive\Encryption;

class StreamCrypto {
    private CryptoEngine $engine;

    public function __construct(string $key) {
        $this->engine = new CryptoEngine($key);
    }

    public function encryptForUpload($inputStream, string $tempPath): array {
        $tempOut = fopen($tempPath, 'wb');
        if (!$tempOut) throw new \RuntimeException("Cannot create temp file for encryption");

        $result = $this->engine->encryptStream($inputStream, $tempOut);
        fclose($tempOut);

        return $result;
    }

    public function decryptForDownload(string $encryptedPath, $outputStream): bool {
        $inHandle = fopen($encryptedPath, 'rb');
        if (!$inHandle) return false;

        $cipher = 'aes-256-gcm';
        $ivLen = openssl_cipher_iv_length($cipher);
        $iv = fread($inHandle, $ivLen);

        fseek($inHandle, -16, SEEK_END);
        $tag = fread($inHandle, 16);

        fseek($inHandle, $ivLen, SEEK_SET);
        $ciphertext = fread($inHandle, filesize($encryptedPath) - $ivLen - 16);
        fclose($inHandle);

        $plaintext = openssl_decrypt(
            $ciphertext,
            $cipher,
            $this->engine->getKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            ''
        );

        if ($plaintext === false) return false;

        fwrite($outputStream, $plaintext);
        return true;
    }

    public function streamEncryptedDownload(string $encryptedPath, callable $callback): bool {
        $inHandle = fopen($encryptedPath, 'rb');
        if (!$inHandle) return false;

        $cipher = 'aes-256-gcm';
        $ivLen = openssl_cipher_iv_length($cipher);
        $iv = fread($inHandle, $ivLen);

        fseek($inHandle, -16, SEEK_END);
        $tag = fread($inHandle, 16);

        fseek($inHandle, $ivLen, SEEK_SET);
        $ciphertext = fread($inHandle, filesize($encryptedPath) - $ivLen - 16);
        fclose($inHandle);

        $plaintext = openssl_decrypt(
            $ciphertext,
            $cipher,
            $this->engine->getKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            ''
        );

        if ($plaintext === false) return false;

        $chunkSize = 65536;
        $total = strlen($plaintext);
        for ($i = 0; $i < $total; $i += $chunkSize) {
            $callback(substr($plaintext, $i, $chunkSize));
        }
        return true;
    }
}
