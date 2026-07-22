<?php
namespace UDrive\Encryption;

class CryptoEngine {
    private string $key;
    private string $cipher;
    private int $chunkSize;

    public function __construct(string $key) {
        $config = require __DIR__ . '/../../config.php';
        $this->key = $key;
        $this->cipher = $config['encryption']['cipher'];
        $this->chunkSize = $config['encryption']['chunk_size'];
    }

    public function getKey(): string {
        return $this->key;
    }

    public function encryptData(string $plaintext): array {
        $ivLen = openssl_cipher_iv_length($this->cipher);
        $iv = openssl_random_pseudo_bytes($ivLen);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );
        return [
            'ciphertext' => $ciphertext,
            'iv' => $iv,
            'tag' => $tag,
        ];
    }

    public function decryptData(string $ciphertext, string $iv, string $tag): string {
        return openssl_decrypt(
            $ciphertext,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            ''
        );
    }

    public function encryptFile(string $sourcePath, string $destPath): array {
        $ivLen = openssl_cipher_iv_length($this->cipher);
        $iv = openssl_random_pseudo_bytes($ivLen);
        $tag = '';

        $inHandle = fopen($sourcePath, 'rb');
        $outHandle = fopen($destPath, 'wb');

        if (!$inHandle || !$outHandle) {
            throw new \RuntimeException("Cannot open files for encryption");
        }

        fwrite($outHandle, $iv);

        $allCiphertext = '';
        while (!feof($inHandle)) {
            $plaintext = fread($inHandle, $this->chunkSize);
            if ($plaintext === false || $plaintext === '') break;

            $ciphertext = openssl_encrypt(
                $plaintext,
                $this->cipher,
                $this->key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                '',
                16
            );
            $allCiphertext .= $ciphertext;
        }

        fwrite($outHandle, $allCiphertext);
        fwrite($outHandle, $tag);

        fclose($inHandle);
        fclose($outHandle);

        return ['iv' => bin2hex($iv), 'tag' => bin2hex($tag)];
    }

    public function decryptFile(string $sourcePath, string $destPath): bool {
        $inHandle = fopen($sourcePath, 'rb');
        $outHandle = fopen($destPath, 'wb');

        if (!$inHandle || !$outHandle) {
            throw new \RuntimeException("Cannot open files for decryption");
        }

        $ivLen = openssl_cipher_iv_length($this->cipher);
        $iv = fread($inHandle, $ivLen);

        fseek($inHandle, -16, SEEK_END);
        $tag = fread($inHandle, 16);

        fseek($inHandle, $ivLen, SEEK_SET);
        $ciphertext = fread($inHandle, filesize($sourcePath) - $ivLen - 16);

        fclose($inHandle);

        $plaintext = openssl_decrypt(
            $ciphertext,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            ''
        );

        if ($plaintext === false) {
            fclose($outHandle);
            return false;
        }

        fwrite($outHandle, $plaintext);
        fclose($outHandle);
        return true;
    }

    public function encryptName(string $name): string {
        $result = $this->encryptData($name);
        return base64_encode($result['iv'] . $result['ciphertext'] . $result['tag']);
    }

    public function decryptName(string $encryptedName): string {
        $data = base64_decode($encryptedName);
        if ($data === false || strlen($data) < 28) return '[encrypted]';

        $ivLen = openssl_cipher_iv_length($this->cipher);
        $iv = substr($data, 0, $ivLen);
        $tag = substr($data, -16);
        $ciphertext = substr($data, $ivLen, -16);

        $result = openssl_decrypt(
            $ciphertext,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            ''
        );

        return $result !== false ? $result : '[decryption failed]';
    }

    public function encryptStream($inputStream, $outputStream): array {
        $ivLen = openssl_cipher_iv_length($this->cipher);
        $iv = openssl_random_pseudo_bytes($ivLen);
        $tag = '';

        fwrite($outputStream, $iv);

        $allCiphertext = '';
        while (!feof($inputStream)) {
            $plaintext = fread($inputStream, $this->chunkSize);
            if ($plaintext === false || $plaintext === '') break;

            $ciphertext = openssl_encrypt(
                $plaintext,
                $this->cipher,
                $this->key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                '',
                16
            );
            $allCiphertext .= $ciphertext;
        }

        fwrite($outputStream, $allCiphertext);
        fwrite($outputStream, $tag);

        return ['iv' => bin2hex($iv), 'tag' => bin2hex($tag)];
    }
}
