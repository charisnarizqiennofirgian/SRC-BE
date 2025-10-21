<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\Config;

class FlexiblePassword implements Rule
{
    protected $minLength;
    protected $maxLength;
    protected $requireUppercase;
    protected $requireLowercase;
    protected $requireNumbers;
    protected $requireSymbols;

    public function __construct()
    {
        $this->minLength = Config::get('password_policy.min_length', 3);
        $this->maxLength = Config::get('password_policy.max_length', 255);
        $this->requireUppercase = Config::get('password_policy.require_uppercase', false);
        $this->requireLowercase = Config::get('password_policy.require_lowercase', false);
        $this->requireNumbers = Config::get('password_policy.require_numbers', false);
        $this->requireSymbols = Config::get('password_policy.require_symbols', false);
    }

    public function passes($attribute, $value)
    {
        // Cek panjang minimum dan maximum
        if (strlen($value) < $this->minLength || strlen($value) > $this->maxLength) {
            return false;
        }

        // Cek huruf besar jika diperlukan
        if ($this->requireUppercase && !preg_match('/[A-Z]/', $value)) {
            return false;
        }

        // Cek huruf kecil jika diperlukan
        if ($this->requireLowercase && !preg_match('/[a-z]/', $value)) {
            return false;
        }

        // Cek angka jika diperlukan
        if ($this->requireNumbers && !preg_match('/[0-9]/', $value)) {
            return false;
        }

        // Cek simbol jika diperlukan
        if ($this->requireSymbols && !preg_match('/[^A-Za-z0-9]/', $value)) {
            return false;
        }

        return true;
    }

    public function message()
    {
        $message = "Password harus memiliki minimal {$this->minLength} karakter";

        if ($this->requireUppercase) {
            $message .= ", minimal 1 huruf besar";
        }

        if ($this->requireLowercase) {
            $message .= ", minimal 1 huruf kecil";
        }

        if ($this->requireNumbers) {
            $message .= ", minimal 1 angka";
        }

        if ($this->requireSymbols) {
            $message .= ", minimal 1 simbol";
        }

        return $message . ".";
    }
}
