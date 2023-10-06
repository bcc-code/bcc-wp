<?php

class BCC_Storage {
    private BCC_Encryption $_encryption;

    function __construct ($encryption) {
        $this->_encryption = $encryption;
    }

    public function set(string $cache_key, mixed $value, int $expiration_duration) : bool {
        $serialized_value = maybe_serialize($value);
        $encrypted_value = $this->_encryption->encrypt($serialized_value);
        return set_transient($cache_key, $encrypted_value, $expiration_duration);
    }
    public function get(string $cache_key) : mixed {
        $encrypted_value = get_transient($cache_key);
        if($encrypted_value === false) {
            return null;
        }
        $decrypted_value = $this->_encryption->decrypt($encrypted_value);
        return maybe_unserialize($decrypted_value);
    }
}
?>