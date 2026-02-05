<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Exports\ChartOfAccountExport;
use App\Exports\ChartOfAccountTemplateExport;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Shuchkin\SimpleXLSX;

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

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:2048',
        ]);

        try {
            $file = $request->file('file');
            $filePath = $file->getRealPath();

            $xlsx = SimpleXLSX::parse($filePath);

            if (!$xlsx) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal membaca file: ' . SimpleXLSX::parseError()
                ], 400);
            }

            $rows = $xlsx->rows();

            if (empty($rows)) {
                return response()->json([
                    'success' => false,
                    'message' => 'File kosong atau format tidak valid'
                ], 400);
            }

            $imported = 0;
            $skipped = 0;
            $isFirstRow = true;

            foreach ($rows as $row) {
                if ($isFirstRow) {
                    $isFirstRow = false;
                    continue;
                }

                $code = isset($row[0]) ? trim((string) $row[0]) : '';
                $name = isset($row[1]) ? trim((string) $row[1]) : '';
                $type = isset($row[2]) ? strtoupper(trim((string) $row[2])) : '';
                $currency = isset($row[3]) ? strtoupper(trim((string) $row[3])) : 'IDR';

                if (empty($code) || empty($name)) {
                    continue;
                }

                if (ChartOfAccount::where('code', $code)->exists()) {
                    $skipped++;
                    continue;
                }

                $type = $this->normalizeType($type);
                if (!$type) {
                    $skipped++;
                    continue;
                }

                ChartOfAccount::create([
                    'code' => $code,
                    'name' => $name,
                    'type' => $type,
                    'currency' => $currency ?: 'IDR',
                    'is_active' => true,
                ]);

                $imported++;
            }

            return response()->json([
                'success' => true,
                'message' => 'Import berhasil!',
                'data' => [
                    'imported' => $imported,
                    'skipped' => $skipped,
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Error import COA: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal import data.',
                'detail' => $e->getMessage()
            ], 500);
        }
    }

    public function export()
    {
        $filename = 'chart_of_accounts_' . date('Y-m-d_His') . '.xlsx';

        return Excel::download(new ChartOfAccountExport(), $filename);
    }

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
