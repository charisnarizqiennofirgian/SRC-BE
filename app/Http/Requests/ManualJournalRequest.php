<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ManualJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => 'required|date',
            'description' => 'required|string|max:500',
            'entries' => 'required|array|min:2',
            'entries.*.account_id' => 'required|exists:chart_of_accounts,id',
            'entries.*.debit' => 'required|numeric|min:0',
            'entries.*.credit' => 'required|numeric|min:0',
            'entries.*.description' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'date.required' => 'Tanggal transaksi harus diisi',
            'description.required' => 'Keterangan harus diisi',
            'entries.required' => 'Minimal harus ada 2 baris jurnal',
            'entries.min' => 'Minimal harus ada 2 baris jurnal',
            'entries.*.account_id.required' => 'Akun harus dipilih',
            'entries.*.account_id.exists' => 'Akun tidak valid',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $entries = $this->input('entries', []);

            $totalDebit = 0;
            $totalCredit = 0;

            foreach ($entries as $index => $entry) {
                $debit = floatval($entry['debit'] ?? 0);
                $credit = floatval($entry['credit'] ?? 0);

                if ($debit > 0 && $credit > 0) {
                    $validator->errors()->add(
                        "entries.{$index}",
                        "Baris tidak boleh memiliki Debit dan Kredit bersamaan"
                    );
                }

                if ($debit == 0 && $credit == 0) {
                    $validator->errors()->add(
                        "entries.{$index}",
                        "Baris harus memiliki Debit atau Kredit"
                    );
                }

                $totalDebit += $debit;
                $totalCredit += $credit;
            }

            if (abs($totalDebit - $totalCredit) > 0.01) {
                $validator->errors()->add(
                    'entries',
                    "Total Debit (Rp " . number_format($totalDebit, 0, ',', '.') .
                    ") tidak sama dengan Total Kredit (Rp " . number_format($totalCredit, 0, ',', '.') . ")"
                );
            }
        });
    }
}
