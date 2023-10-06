<?php

class BCC_Encryption {
    private string $_encryption_key;
    private string $_encryption_method = "AES-256-CBC";

    function __construct( string $password ) {
        $this->_encryption_key = hash('sha256', $password, true);
    }

    
    function encrypt(string $plaintext) : string {
        $iv = openssl_random_pseudo_bytes(16);
    
        $ciphertext = openssl_encrypt($plaintext, $this->_encryption_method, $this->_encryption_key, OPENSSL_RAW_DATA, $iv);
        $hash = hash_hmac('sha256', $ciphertext . $iv, $this->_encryption_key, true);
    
        return base64_encode($iv . $hash . $ciphertext);
    }
    
    function decrypt(string $encrypted) : string|null {
        $decoded = base64_decode($encrypted);
        $iv = substr($decoded, 0, 16);
        $hash = substr($decoded, 16, 32);
        $ciphertext = substr($decoded, 48);
    
        if (!hash_equals(hash_hmac('sha256', $ciphertext . $iv, $this->_encryption_key, true), $hash)) return null;

        return openssl_decrypt($ciphertext, $this->_encryption_method, $this->_encryption_key, OPENSSL_RAW_DATA, $iv);
    }
}

?>