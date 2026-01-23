<?php

namespace App\Imports;

use App\Models\ChartOfAccount;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;

class ChartOfAccountImport implements ToArray, WithCalculatedFormulas
{
    private $rowCount = 0;
    private $skippedCount = 0;

    public function array(array $rows)
    {
        // Skip header row (baris pertama)
        $isFirstRow = true;

        foreach ($rows as $row) {
            // Skip header
            if ($isFirstRow) {
                $isFirstRow = false;
                continue;
            }

            // Ambil nilai berdasarkan index kolom
            $code = isset($row[0]) ? $this->cleanString($row[0]) : '';
            $name = isset($row[1]) ? $this->cleanString($row[1]) : '';
            $type = isset($row[2]) ? $this->cleanString($row[2]) : '';
            $currency = isset($row[3]) ? $this->cleanString($row[3]) : 'IDR';

            // Skip jika code atau name kosong
            if (empty($code) || empty($name)) {
                continue;
            }

            // Cek duplikat
            $existingAccount = ChartOfAccount::where('code', $code)->first();
            if ($existingAccount) {
                $this->skippedCount++;
                continue;
            }

            // Normalize type
            $type = $this->normalizeType($type);
            if (!$type) {
                $this->skippedCount++;
                continue;
            }

            // Simpan ke database
            ChartOfAccount::create([
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'currency' => !empty($currency) ? strtoupper($currency) : 'IDR',
                'is_active' => true,
            ]);

            $this->rowCount++;
        }
    }

    private function cleanString($value): string
    {
        if ($value === null) {
            return '';
        }

        // Convert to string dan hapus karakter aneh
        $value = (string) $value;
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        $value = trim($value);

        return $value;
    }

    private function normalizeType(string $type): ?string
    {
        $type = strtoupper(trim($type));

        $mapping = [
            'ASET' => 'ASET',
            'HARTA' => 'ASET',
            'AKTIVA' => 'ASET',
            'ASSET' => 'ASET',
            'KEWAJIBAN' => 'KEWAJIBAN',
            'HUTANG' => 'KEWAJIBAN',
            'LIABILITAS' => 'KEWAJIBAN',
            'LIABILITY' => 'KEWAJIBAN',
            'MODAL' => 'MODAL',
            'EKUITAS' => 'MODAL',
            'EQUITY' => 'MODAL',
            'PENDAPATAN' => 'PENDAPATAN',
            'PENGHASILAN' => 'PENDAPATAN',
            'INCOME' => 'PENDAPATAN',
            'REVENUE' => 'PENDAPATAN',
            'BIAYA' => 'BIAYA',
            'BEBAN' => 'BIAYA',
            'EXPENSE' => 'BIAYA',
        ];

        return $mapping[$type] ?? null;
    }

    public function getRowCount(): int
    {
        return $this->rowCount;
    }

    public function getSkippedCount(): int
    {
        return $this->skippedCount;
    }
}
