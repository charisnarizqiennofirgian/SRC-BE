<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Exports\ChartOfAccountExport;
use App\Exports\ChartOfAccountTemplateExport;
use App\Imports\ChartOfAccountImport;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ChartOfAccountController extends Controller
{
    public function index(Request $request)
    {
        $query = ChartOfAccount::query();

        if ($request->has('type')) {
            $types = explode(',', $request->type);
            $query->whereIn('type', $types);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        $accounts = $query->orderBy('code', 'asc')->get();

        return response()->json([
            'success' => true,
            'data' => $accounts
        ]);
    }

    public function all()
    {
        try {
            $accounts = ChartOfAccount::active()
                ->orderBy('code', 'asc')
                ->get();
            return response()->json([
                'success' => true,
                'data' => $accounts
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching all COA: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data akun.',
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'code' => 'required|string|max:20|unique:chart_of_accounts,code',
            'name' => 'required|string|max:100',
            'type' => 'required|in:ASET,KEWAJIBAN,MODAL,PENDAPATAN,HPP,BIAYA',
            'currency' => 'nullable|string|max:3',
        ]);

        $account = ChartOfAccount::create([
            'code' => $request->code,
            'name' => $request->name,
            'type' => $request->type,
            'currency' => $request->currency ?? 'IDR',
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Akun berhasil ditambahkan',
            'data' => $account
        ], 201);
    }

    public function show($id)
    {
        $account = ChartOfAccount::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $account
        ]);
    }

    public function update(Request $request, $id)
    {
        $account = ChartOfAccount::findOrFail($id);

        $request->validate([
            'code' => 'required|string|max:20|unique:chart_of_accounts,code,' . $id,
            'name' => 'required|string|max:100',
            'type' => 'required|in:ASET,KEWAJIBAN,MODAL,PENDAPATAN,HPP,BIAYA',
            'currency' => 'nullable|string|max:3',
            'is_active' => 'nullable|boolean',
        ]);

        $account->update([
            'code' => $request->code,
            'name' => $request->name,
            'type' => $request->type,
            'currency' => $request->currency ?? 'IDR',
            'is_active' => $request->is_active ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Akun berhasil diupdate',
            'data' => $account
        ]);
    }

    public function destroy($id)
    {
        $account = ChartOfAccount::findOrFail($id);

        if ($account->suppliers()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Akun tidak bisa dihapus karena masih digunakan oleh Supplier'
            ], 400);
        }

        if ($account->buyers()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Akun tidak bisa dihapus karena masih digunakan oleh Buyer'
            ], 400);
        }

        $account->delete();

        return response()->json([
            'success' => true,
            'message' => 'Akun berhasil dihapus'
        ]);
    }

    public function getTypes()
    {
        return response()->json([
            'success' => true,
            'data' => ChartOfAccount::getTypes()
        ]);
    }

    public function getByType($type)
    {
        $type = strtoupper($type);

        $accounts = ChartOfAccount::where('type', $type)
            ->where('is_active', true)
            ->orderBy('code', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $accounts
        ]);
    }

    public function downloadTemplate()
    {
        return Excel::download(new ChartOfAccountTemplateExport(), 'template_coa.xlsx');
    }

    /**
     * Import COA - Support 2 format:
     * 1. Format Standard (4 kolom): Code | Name | Type | Currency
     * 2. Format Klien (2 kolom): No.Id | Name (auto-detect type dari kode)
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls|max:5120', // Hanya Excel, hapus CSV
        ]);

        try {
            $file = $request->file('file');
            $extension = strtolower($file->getClientOriginalExtension());

            // Validasi extension sekali lagi
            if (!in_array($extension, ['xlsx', 'xls'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Format file tidak valid. Gunakan file Excel (.xlsx atau .xls)',
                ], 400);
            }

            // Cek apakah file bisa dibaca
            if (!$file->isValid()) {
                return response()->json([
                    'success' => false,
                    'message' => 'File yang diupload tidak valid atau corrupt.',
                ], 400);
            }

            $import = new ChartOfAccountImport();
            $readerType = $extension === 'xlsx' ? \Maatwebsite\Excel\Excel::XLSX : \Maatwebsite\Excel\Excel::XLS;
            Excel::import($import, $file, null, $readerType);

            $imported = $import->getRowCount();
            $skipped = $import->getSkippedCount();
            $errors = $import->getErrors();

            $message = "Import selesai! $imported akun berhasil diimport";
            if ($skipped > 0) {
                $message .= ", $skipped baris dilewati";
            }
            $message .= ".";

            $response = [
                'success' => true,
                'message' => $message,
                'data' => [
                    'imported' => $imported,
                    'skipped' => $skipped,
                ]
            ];

            if (!empty($errors)) {
                $response['errors'] = $errors;
                $response['message'] .= " Lihat detail error untuk baris yang dilewati.";
            }

            return response()->json($response);

        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            // Excel validation error
            \Log::error('Excel Validation Error: ' . json_encode($e->failures()));

            return response()->json([
                'success' => false,
                'message' => 'Validasi Excel gagal. Periksa format data di file Excel.',
                'errors' => $e->failures()
            ], 422);

        } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
            // File tidak bisa dibaca
            \Log::error('Excel Reader Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'File Excel tidak valid atau corrupt. Pastikan file berformat .xlsx atau .xls dan bisa dibuka di Microsoft Excel.',
            ], 400);

        } catch (\Exception $e) {
            \Log::error('Error import COA: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Gagal import Chart of Account. Pastikan format Excel benar dan file tidak corrupt.',
                'detail' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function export()
    {
        $filename = 'chart_of_accounts_' . date('Y-m-d_His') . '.xlsx';

        return Excel::download(new ChartOfAccountExport(), $filename);
    }

    /**
     * Normalize type untuk backward compatibility
     */
    private function normalizeType(string $type): ?string
    {
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
            'HPP' => 'HPP',
            'HARGA POKOK PENJUALAN' => 'HPP',
            'COGS' => 'HPP',
            'BIAYA' => 'BIAYA',
            'BEBAN' => 'BIAYA',
            'EXPENSE' => 'BIAYA',
        ];

        return $mapping[$type] ?? null;
    }
}