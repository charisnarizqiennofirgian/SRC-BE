<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Password Policy Configuration
    |--------------------------------------------------------------------------
    |
    | Konfigurasi untuk kebijakan password yang fleksibel
    |
    */

    'min_length' => env('PASSWORD_MIN_LENGTH', 3), // Minimum 3 karakter (bisa disesuaikan)
    'max_length' => env('PASSWORD_MAX_LENGTH', 255), // Maximum 255 karakter
    'require_uppercase' => env('PASSWORD_REQUIRE_UPPERCASE', false), // Tidak wajib huruf besar
    'require_lowercase' => env('PASSWORD_REQUIRE_LOWERCASE', false), // Tidak wajib huruf kecil
    'require_numbers' => env('PASSWORD_REQUIRE_NUMBERS', false), // Tidak wajib angka
    'require_symbols' => env('PASSWORD_REQUIRE_SYMBOLS', false), // Tidak wajib simbol
    'allow_common_passwords' => env('PASSWORD_ALLOW_COMMON', true), // Boleh password umum seperti "admin"
];
