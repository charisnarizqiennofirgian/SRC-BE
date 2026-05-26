<?php

namespace App\Imports;

use App\Models\ChartOfAccount;
use Maatwebsite\Excel\Concerns\ToArray;

class OpeningBalanceImport implements ToArray
{
    public array $entries      = [];
    public array $errors       = [];
    public array $debug        = [];
    public float $totalDebit   = 0;
    public float $totalCredit  = 0;

    public function array(array $rows)
    {
        // --- STEP 1: Temukan baris header ---
        // PENTING: "NERACA LAJUR/NAMA AKUN" dan "SALDO AKHIR" harus ada di BARIS YANG SAMA.
        // Ini mencegah false-positive dari sheet GL yang deskripsi transaksinya
        // kebetulan mengandung kata "Saldo Akhir".
        $namaCol        = null;
        $kodeCol        = null;
        $headerRowIndex = null;
        $saldoCols      = [];

        foreach ($rows as $rowIndex => $row) {
            $rowNama  = null;
            $rowSaldo = [];
            $rowKode  = null;

            foreach ($row as $colIndex => $cell) {
                $c = strtolower(trim((string) $cell));
                if (str_contains($c, 'neraca lajur') || str_contains($c, 'nama akun')) {
                    $rowNama = $colIndex;
                }
                if (str_contains($c, 'saldo akhir')) {
                    $rowSaldo[] = $colIndex;
                }
                if (str_contains($c, 'kode akun') || $c === 'kode') {
                    $rowKode = $colIndex;
                }
            }

            if ($rowNama !== null && count($rowSaldo) > 0) {
                $namaCol        = $rowNama;
                $saldoCols      = $rowSaldo;
                $kodeCol        = $rowKode;
                $headerRowIndex = $rowIndex;
                break;
            }
        }

        if ($headerRowIndex === null) {
            $this->debug[] = "Sheet ini tidak punya kolom NERACA LAJUR + SALDO AKHIR — dilewati.";
            return;
        }

        $this->debug[] = "Header baris " . ($headerRowIndex + 1)
            . " | Nama: kol $namaCol"
            . " | Kode: kol " . ($kodeCol ?? '-')
            . " | saldoCols: [" . implode(',', $saldoCols) . "]";

        // --- STEP 2: Tentukan kolom Saldo Akhir Debit & Kredit ---
        $saldoDebitCol  = null;
        $saldoKreditCol = null;
        $dataStartRow   = $headerRowIndex + 1;

        if (count($saldoCols) >= 2) {
            $saldoDebitCol  = $saldoCols[0];
            $saldoKreditCol = $saldoCols[1];
        } else {
            $baseSaldoCol = $saldoCols[0];

            // Cek sub-header 1-3 baris sesudah header untuk label D/K
            for ($subIdx = $headerRowIndex + 1; $subIdx <= $headerRowIndex + 3; $subIdx++) {
                if (!isset($rows[$subIdx])) break;

                $allDebitCols  = [];
                $allKreditCols = [];

                foreach ($rows[$subIdx] as $colIdx => $subCell) {
                    $sub = strtolower(trim((string) $subCell));
                    if ($sub === '' || $colIdx <= ($namaCol ?? 0)) continue;

                    $isDebit  = ($sub === 'd') || ($sub === 'db') || ($sub === 'dr')
                        || str_contains($sub, 'debit') || str_contains($sub, 'debet');
                    $isKredit = ($sub === 'k') || ($sub === 'kr') || ($sub === 'cr')
                        || str_contains($sub, 'kredit') || str_contains($sub, 'credit');

                    if ($isDebit)  $allDebitCols[]  = $colIdx;
                    if ($isKredit) $allKreditCols[] = $colIdx;
                }

                // Cari pasangan D/K terkanan yang D-nya >= baseSaldoCol - 2
                $bestDebit  = null;
                $bestKredit = null;
                foreach ($allDebitCols as $dCol) {
                    foreach ($allKreditCols as $kCol) {
                        if ($dCol >= $baseSaldoCol - 2 && ($kCol === $dCol + 1 || $kCol === $dCol + 2)) {
                            if ($bestDebit === null || $dCol > $bestDebit) {
                                $bestDebit  = $dCol;
                                $bestKredit = $kCol;
                            }
                        }
                    }
                }

                if ($bestDebit !== null) {
                    $saldoDebitCol  = $bestDebit;
                    $saldoKreditCol = $bestKredit;
                    $dataStartRow   = $subIdx + 1;
                    $this->debug[] = "Sub-header D/K di baris " . ($subIdx + 1)
                        . " | Debit: kol $bestDebit | Kredit: kol $bestKredit";
                    break;
                }
            }

            if ($saldoDebitCol === null) {
                $saldoDebitCol = $baseSaldoCol;
            }
        }

        // --- STEP 3: Jika kolom saldo berisi formula string, geser ke kolom berikutnya ---
        // Terjadi ketika Excel punya kolom SALDO AKHIR berformula (=C+D-E) tapi
        // PhpSpreadsheet membaca string formula, bukan nilai cachednya.
        // Solusi: cari kolom sebelahnya yang punya nilai numerik nyata (contoh: kolom "kontrol").
        for ($tryCol = $saldoDebitCol; $tryCol <= $saldoDebitCol + 4; $tryCol++) {
            if (!$this->isFormulaColumn($rows, $dataStartRow, $tryCol)) {
                if ($tryCol !== $saldoDebitCol) {
                    $this->debug[] = "Kol $saldoDebitCol berisi formula string, pakai kol $tryCol";
                }
                $saldoDebitCol = $tryCol;
                break;
            }
        }

        $dualColMode = $saldoKreditCol !== null;

        $this->debug[] = "Mode: " . ($dualColMode ? 'Dual-Kolom' : 'Single-Kolom')
            . " | Saldo Debit: kol $saldoDebitCol"
            . " | Saldo Kredit: kol " . ($saldoKreditCol ?? '-')
            . " | Data mulai baris " . ($dataStartRow + 1);

        // --- STEP 4: Proses baris data ---
        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex < $dataStartRow) continue;

            $namaAkun = trim((string) ($row[$namaCol] ?? ''));
            $kodeAkun = $kodeCol !== null ? trim((string) ($row[$kodeCol] ?? '')) : '';

            if ($namaAkun === '') continue;
            // Abaikan baris yang nama-nya adalah formula atau angka murni
            if (str_starts_with($namaAkun, '=')) continue;

            if ($dualColMode) {
                $valDebit  = $this->parseAngka($row[$saldoDebitCol]  ?? '');
                $valKredit = $this->parseAngka($row[$saldoKreditCol] ?? '');
                if ($valDebit == 0 && $valKredit == 0) continue;
            } else {
                $saldoAkhir = $this->parseAngka($row[$saldoDebitCol] ?? '');
                if ($saldoAkhir == 0) continue;
            }

            // --- Resolusi nama akun: exact alias → prefix alias → kode ---
            $namaUpper   = strtoupper($namaAkun);
            $candidateNames = [$namaAkun]; // default: cari nama apa adanya

            // Exact aliases
            if ($namaUpper === 'KAS RIRIK')  $candidateNames = ['KAS'];
            elseif ($namaUpper === 'KAS FABIAN') $candidateNames = ['KAS KECIL 02'];
            elseif ($namaUpper === 'BELI JADI')  $candidateNames = ['HPP - BAHAN'];
            // Prefix aliases: akun rincian klien → akun induk di COA sistem
            // Semua PIHUTANG DAGANG (lokal maupun ekspor) → PIHUTANG DAGANG
            elseif (str_starts_with($namaUpper, 'PIHUTANG DAGANG')) {
                $candidateNames = ['PIHUTANG DAGANG'];
            }
            // PIHUTANG LAIN-LAIN (karyawan) → PIHUTANG LAIN-LAIN - KARYAWAN
            elseif (str_starts_with($namaUpper, 'PIHUTANG LAIN-LAIN')) {
                $candidateNames = ['PIHUTANG LAIN-LAIN - KARYAWAN', 'PIHUTANG KARYAWAN -'];
            }
            // HUTANG DAGANG per supplier → akun induk hutang dagang
            elseif (str_starts_with($namaUpper, 'HUTANG DAGANG')) {
                $candidateNames = ['HUTANG DAGANG', 'HUTANG USAHA'];
            }

            // Cari akun di COA: coba semua candidate names, lalu fallback ke kode
            $account = null;
            foreach ($candidateNames as $namaCari) {
                $account = ChartOfAccount::whereRaw('LOWER(name) = ?', [strtolower($namaCari)])->first();
                if ($account) break;
            }
            if (!$account && $kodeAkun !== '') {
                $account = ChartOfAccount::where('code', $kodeAkun)->first();
            }

            if (!$account) {
                $this->errors[] = "Baris " . ($rowIndex + 1) . ": '{$namaAkun}' tidak ditemukan di COA sistem";
                continue;
            }

            // Tentukan nilai debit & kredit
            if ($dualColMode) {
                $debit  = $valDebit;
                $kredit = $valKredit;
            } else {
                // Kolom kontrol menyimpan nilai negatif untuk akun bersaldo kredit.
                // Pakai abs() agar jumlah selalu positif, lalu klasifikasikan berdasar tipe akun.
                $absSaldo     = abs($saldoAkhir);
                $isKontraAset = stripos($account->name, 'AKUM') !== false;
                $debit  = 0;
                $kredit = 0;
                if ($isKontraAset) {
                    $kredit = $absSaldo;
                } elseif (in_array($account->type, ['ASET', 'HPP', 'BIAYA'])) {
                    $debit = $absSaldo;
                } else {
                    $kredit = $absSaldo;
                }
            }

            // Akumulasi: jika akun sudah ada (dari baris klien sebelumnya), jumlahkan saldo
            $existingKey = null;
            foreach ($this->entries as $k => $e) {
                if ($e['account_id'] === $account->id) {
                    $existingKey = $k;
                    break;
                }
            }

            if ($existingKey !== null) {
                $this->entries[$existingKey]['debit']  += $debit;
                $this->entries[$existingKey]['credit'] += $kredit;
            } else {
                $this->entries[] = [
                    'account_id'   => $account->id,
                    'account_name' => $account->name,
                    'debit'        => $debit,
                    'credit'       => $kredit,
                    'description'  => 'Saldo Awal - ' . $account->name,
                ];
            }

            $this->totalDebit  += $debit;
            $this->totalCredit += $kredit;
        }

        $this->debug[] = "Akun berhasil: " . count($this->entries)
            . " | Total D: " . number_format($this->totalDebit)
            . " | Total K: " . number_format($this->totalCredit);
        $this->debug[] = "Akun error: " . count($this->errors);
    }

    /**
     * Cek apakah kolom mayoritas berisi formula string (=xxx) bukan nilai nyata.
     */
    private function isFormulaColumn(array $rows, int $dataStart, int $col): bool
    {
        $formulaCount = 0;
        $checked      = 0;

        foreach ($rows as $ri => $row) {
            if ($ri < $dataStart) continue;
            if ($checked >= 10) break;

            $v = trim((string) ($row[$col] ?? ''));
            if ($v === '') continue;

            if (str_starts_with($v, '=')) $formulaCount++;
            $checked++;
        }

        return $checked > 0 && $formulaCount >= (int) ceil($checked / 2);
    }

    private function parseAngka(mixed $value): float
    {
        if (is_numeric($value)) return (float) $value;

        $str = trim(preg_replace('/[Rp\s]/u', '', (string) $value));
        if (str_starts_with($str, '=')) return 0.0; // formula string, skip

        $hasDot   = str_contains($str, '.');
        $hasComma = str_contains($str, ',');

        if ($hasDot && $hasComma) {
            if (strrpos($str, ',') > strrpos($str, '.')) {
                $str = str_replace('.', '', $str);
                $str = str_replace(',', '.', $str);
            } else {
                $str = str_replace(',', '', $str);
            }
        } elseif ($hasComma && !$hasDot) {
            $str = str_replace(',', '.', $str);
        } elseif ($hasDot && !$hasComma) {
            $parts = explode('.', $str);
            if (strlen(end($parts)) !== 2) {
                $str = str_replace('.', '', $str);
            }
        }

        return (float) $str;
    }
}
