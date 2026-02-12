<?php

namespace App\Imports;

use App\Models\ChartOfAccount;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;

class ChartOfAccountImport implements ToArray, WithCalculatedFormulas
{
    private $rowCount = 0;
    private $skippedCount = 0;
    private $errors = [];

    public function array(array $rows)
    {
        $isFirstRow = true;

        foreach ($rows as $index => $row) {
            if ($isFirstRow) {
                $isFirstRow = false;
                continue;
            }

            // Format Excel klien: CODE | NAME | TYPE | CURRENCY
            $code = isset($row[0]) ? $this->cleanString($row[0]) : '';
            $name = isset($row[1]) ? $this->cleanString($row[1]) : '';
            $typeFromExcel = isset($row[2]) ? $this->cleanString($row[2]) : '';
            $currency = isset($row[3]) ? $this->cleanString($row[3]) : '';

            // Skip jika code atau name kosong
            if (empty($code) || empty($name)) {
                continue;
            }

            // Skip jika nama terlalu pendek
            if (strlen($name) < 3) {
                continue;
            }

            // Normalize TYPE dari Excel klien
            $type = $this->normalizeTypeFromExcel($typeFromExcel);

            // Jika TYPE dari Excel tidak bisa dipetakan, coba auto-detect dari kode
            if (!$type) {
                $type = $this->getTypeFromCode($code);
            }

            if (!$type) {
                $this->errors[] = "Baris " . ($index + 1) . ": Kode '$code' - Type '$typeFromExcel' tidak bisa ditentukan";
                $this->skippedCount++;
                continue;
            }

            // Auto-detect CURRENCY dari nama jika kosong
            if (empty($currency)) {
                $currency = $this->getCurrencyFromName($name);
            } else {
                $currency = strtoupper(trim($currency));
                if (!in_array($currency, ['IDR', 'USD', 'EUR', 'SGD', 'JPY', 'CNY'])) {
                    $currency = 'IDR';
                }
            }

            try {
                ChartOfAccount::updateOrCreate(
                    ['code' => $code],
                    [
                        'name' => $name,
                        'type' => $type,
                        'currency' => $currency,
                        'is_active' => true,
                    ]
                );

                $this->rowCount++;
            } catch (\Exception $e) {
                $this->errors[] = "Baris " . ($index + 1) . ": " . $e->getMessage();
                $this->skippedCount++;
            }
        }
    }

    /**
     * Normalize TYPE dari Excel klien ke system type
     */
    private function normalizeTypeFromExcel(string $typeFromExcel): ?string
    {
        if (empty($typeFromExcel)) {
            return null;
        }

        $typeUpper = strtoupper(trim($typeFromExcel));

        // Mapping type klien → system
        $mapping = [
            // ASET variants
            'HARTA LANCAR' => 'ASET',
            'HARTA TIDAK LANCAR' => 'ASET',
            'ASET' => 'ASET',
            'HARTA' => 'ASET',
            'AKTIVA' => 'ASET',
            'AKTIVA LANCAR' => 'ASET',
            'AKTIVA TETAP' => 'ASET',
            'ASSET' => 'ASET',
            'CURRENT ASSETS' => 'ASET',
            'FIXED ASSETS' => 'ASET',

            // KEWAJIBAN variants
            'HUTANG' => 'KEWAJIBAN',
            'KEWAJIBAN' => 'KEWAJIBAN',
            'LIABILITAS' => 'KEWAJIBAN',
            'PASIVA' => 'KEWAJIBAN',
            'LIABILITY' => 'KEWAJIBAN',
            'LIABILITIES' => 'KEWAJIBAN',
            'CURRENT LIABILITIES' => 'KEWAJIBAN',
            'LONG TERM LIABILITIES' => 'KEWAJIBAN',

            // MODAL variants
            'MODAL SAHAM' => 'MODAL',
            'MODAL' => 'MODAL',
            'EKUITAS' => 'MODAL',
            'EQUITY' => 'MODAL',
            'LABA YG DITAHAN' => 'MODAL',
            'LABA DITAHAN' => 'MODAL',
            'RETAINED EARNINGS' => 'MODAL',

            // PENDAPATAN variants
            'PENJUALAN' => 'PENDAPATAN',
            'PENDAPATAN' => 'PENDAPATAN',
            'PENGHASILAN' => 'PENDAPATAN',
            'INCOME' => 'PENDAPATAN',
            'REVENUE' => 'PENDAPATAN',
            'SALES' => 'PENDAPATAN',
            'PENDAPATAN LAIN - LAIN DI LUAR USAHA' => 'PENDAPATAN',
            'PENDAPATAN LAIN-LAIN' => 'PENDAPATAN',
            'OTHER INCOME' => 'PENDAPATAN',

            // HPP variants
            'HARGA POKOK PENJUALAN' => 'HPP',
            'HPP' => 'HPP',
            'COGS' => 'HPP',
            'COST OF GOODS SOLD' => 'HPP',

            // BIAYA variants
            'BIAYA' => 'BIAYA',
            'BEBAN' => 'BIAYA',
            'EXPENSE' => 'BIAYA',
            'EXPENSES' => 'BIAYA',
            'BIAYA PENJUALAN' => 'BIAYA',
            'BIAYA ADMINISTRASI UMUM' => 'BIAYA',
            'BIAYA ADMINISTRASI' => 'BIAYA',
            'BIAYA OPERASIONAL' => 'BIAYA',
            'OPERATING EXPENSES' => 'BIAYA',
            'SELLING EXPENSES' => 'BIAYA',
            'ADMINISTRATIVE EXPENSES' => 'BIAYA',
            'RETUR DAN POTONGAN PENJUALAN' => 'BIAYA',
            'POTONGAN PENJUALAN' => 'BIAYA',
            'SALES DISCOUNT' => 'BIAYA',
        ];

        return $mapping[$typeUpper] ?? null;
    }

    /**
     * Fallback: Auto-detect type dari kode (untuk baris yang TYPE-nya kosong)
     */
    private function getTypeFromCode(string $code): ?string
    {
        $firstDigit = substr($code, 0, 1);
        $threeDigits = (int) substr(str_replace('.', '', $code), 0, 3);

        switch ($firstDigit) {
            case '1':
            case '2':
                return 'ASET';

            case '3':
                return 'KEWAJIBAN';

            case '4':
                return 'MODAL';

            case '5':
                if ($threeDigits >= 520 && $threeDigits < 600) {
                    return 'BIAYA';
                }
                return 'PENDAPATAN';

            case '6':
                return 'HPP';

            case '7':
                return 'BIAYA';

            case '8':
                return 'PENDAPATAN';

            case '9':
                return 'MODAL';

            default:
                return null;
        }
    }

    /**
     * Deteksi currency dari nama akun
     */
    private function getCurrencyFromName(string $name): string
    {
        $nameUpper = strtoupper($name);

        if (
            str_contains($nameUpper, '-USD') ||
            str_contains($nameUpper, ' USD') ||
            str_contains($nameUpper, 'USD-') ||
            str_contains($nameUpper, 'DOLLAR')
        ) {
            return 'USD';
        }

        if (
            str_contains($nameUpper, '-EURO') ||
            str_contains($nameUpper, ' EURO') ||
            str_contains($nameUpper, 'EURO-') ||
            str_contains($nameUpper, '€')
        ) {
            return 'EUR';
        }

        if (
            str_contains($nameUpper, '-SGD') ||
            str_contains($nameUpper, ' SGD')
        ) {
            return 'SGD';
        }

        return 'IDR';
    }

    private function cleanString($value): string
    {
        if ($value === null) {
            return '';
        }

        $value = (string) $value;
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        $value = trim($value);

        return $value;
    }

    public function getRowCount(): int
    {
        return $this->rowCount;
    }

    public function getSkippedCount(): int
    {
        return $this->skippedCount;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}