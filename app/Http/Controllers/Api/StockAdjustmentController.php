<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Item;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\KayuLogStockImport;
use App\Imports\KayuStockImport;
use App\Imports\UmumStockImport;
use App\Imports\KartonBoxStockImport;
use App\Imports\KomponenStockImport;
use App\Exports\KayuLogTemplateExport;
use App\Exports\KayuTemplateExport;
use App\Exports\ProdukJadiTemplateExport;
use App\Exports\UmumTemplateExport;
use App\Exports\KartonBoxTemplateExport;
use App\Exports\KomponenTemplateExport;

class StockAdjustmentController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'item_id'  => 'required|integer|exists:items,id',
            'type'     => 'required|string|in:Stok Masuk,Stok Keluar',
            'quantity' => 'required|numeric|min:0.01',
            'notes'    => 'nullable|string|max:1000',
        ], [
            'quantity.min' => 'Kuantitas harus lebih besar dari 0.',
            'type.in'      => 'Tipe penyesuaian tidak valid.'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors'  => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $item = Item::lockForUpdate()->findOrFail($request->item_id);

            $quantity         = (float) $request->quantity;
            $type             = $request->type;
            $movementQuantity = ($type === 'Stok Keluar') ? -$quantity : $quantity;

            StockMovement::create([
                'item_id'  => $item->id,
                'type'     => $type,
                'quantity' => $movementQuantity,
                'notes'    => $request->notes ?? 'Penyesuaian manual dari admin.',
            ]);

            $item->increment('stock', $movementQuantity);
            $item->refresh();

            DB::commit();

            return response()->json([
                'success'   => true,
                'message'   => 'Penyesuaian stok berhasil disimpan.',
                'new_stock' => $item->stock
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Gagal melakukan penyesuaian stok: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan pada server.',
                'error'   => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /* ================== UMUM (Bahan Operasional & Penolong) ================== */

    public function downloadTemplateUmum()
    {
        try {
            return Excel::download(
                new UmumTemplateExport,
                'template_saldo_awal_umum.xlsx'
            );
        } catch (\Exception $e) {
            Log::error('Gagal download template umum: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mendownload template saldo awal umum.'
            ], 500);
        }
    }

    public function uploadSaldoAwalUmum(Request $request)
    {
        if (!$request->hasFile('file')) {
            return response()->json([
                'success' => false,
                'message' => 'File tidak ditemukan. Pastikan field name adalah "file".',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'max:5120'
            ]
        ], [
            'file.required' => 'File wajib di-upload.',
            'file.file'     => 'File yang di-upload tidak valid.',
            'file.mimetypes'=> 'File harus berformat Excel (.xls, .xlsx) atau CSV (.csv).',
            'file.max'      => 'Ukuran file maksimal 5MB.'
        ]);

        if ($validator->fails()) {
            Log::warning('Validasi file saldo awal umum gagal:', [
                'errors'    => $validator->errors()->toArray(),
                'file_info' => $request->hasFile('file') ? [
                    'original_name' => $request->file('file')->getClientOriginalName(),
                    'mime_type'     => $request->file('file')->getMimeType(),
                    'size'          => $request->file('file')->getSize(),
                ] : 'No file uploaded'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validasi file gagal.',
                'errors'  => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            Excel::import(new UmumStockImport, $request->file('file'));

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Upload saldo awal UMUM berhasil. Master data dan stok telah diperbarui.'
            ], 201);

        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            DB::rollBack();
            $failures = $e->failures();
            Log::error('Gagal validasi Excel Saldo Awal Umum:', ['failures' => $failures]);

            return response()->json([
                'success' => false,
                'message' => 'Data di Excel tidak valid.',
                'errors'  => $failures,
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Gagal upload Saldo Awal Umum: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan internal saat memproses file.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /* ================== KARTON BOX ================== */

    public function downloadTemplateKartonBox()
    {
        try {
            return Excel::download(
                new KartonBoxTemplateExport,
                'template_saldo_awal_karton_box.xlsx'
            );
        } catch (\Exception $e) {
            Log::error('Gagal download template karton box: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mendownload template karton box.'
            ], 500);
        }
    }

    public function uploadSaldoAwalKartonBox(Request $request)
    {
        if (!$request->hasFile('file')) {
            return response()->json([
                'success' => false,
                'message' => 'File tidak ditemukan. Pastikan field name adalah "file".',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'max:5120'
            ]
        ], [
            'file.required' => 'File wajib di-upload.',
            'file.file'     => 'File yang di-upload tidak valid.',
            'file.mimetypes'=> 'File harus berformat Excel (.xls, .xlsx) atau CSV (.csv).',
            'file.max'      => 'Ukuran file maksimal 5MB.'
        ]);

        if ($validator->fails()) {
            Log::warning('Validasi file karton box gagal:', [
                'errors'    => $validator->errors()->toArray(),
                'file_info' => $request->hasFile('file') ? [
                    'original_name' => $request->file('file')->getClientOriginalName(),
                    'mime_type'     => $request->file('file')->getMimeType(),
                    'size'          => $request->file('file')->getSize(),
                ] : 'No file uploaded'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validasi file gagal.',
                'errors'  => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            Excel::import(new KartonBoxStockImport, $request->file('file'));

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Upload saldo awal Karton Box berhasil. Master data dan stok telah diperbarui.'
            ], 201);

        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            DB::rollBack();
            $failures = $e->failures();
            Log::error('Gagal validasi Excel Karton Box:', ['failures' => $failures]);

            return response()->json([
                'success' => false,
                'message' => 'Data di Excel tidak valid.',
                'errors'  => $failures,
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Gagal upload Saldo Awal Karton Box: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan internal saat memproses file.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /* ================== KOMPONEN ================== */

    public function downloadTemplateKomponen()
    {
        try {
            return Excel::download(
                new KomponenTemplateExport,
                'template_saldo_awal_komponen.xlsx'
            );
        } catch (\Exception $e) {
            Log::error('Gagal download template komponen: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mendownload template komponen.'
            ], 500);
        }
    }

    public function uploadSaldoAwalKomponen(Request $request)
    {
        if (!$request->hasFile('file')) {
            return response()->json([
                'success' => false,
                'message' => 'File tidak ditemukan. Pastikan field name adalah "file".',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'max:5120'
            ]
        ], [
            'file.required' => 'File wajib di-upload.',
            'file.file'     => 'File yang di-upload tidak valid.',
            'file.mimetypes'=> 'File harus berformat Excel (.xls, .xlsx) atau CSV (.csv).',
            'file.max'      => 'Ukuran file maksimal 5MB.'
        ]);

        if ($validator->fails()) {
            Log::warning('Validasi file komponen gagal:', [
                'errors'    => $validator->errors()->toArray(),
                'file_info' => $request->hasFile('file') ? [
                    'original_name' => $request->file('file')->getClientOriginalName(),
                    'mime_type'     => $request->file('file')->getMimeType(),
                    'size'          => $request->file('file')->getSize(),
                ] : 'No file uploaded'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validasi file gagal.',
                'errors'  => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            Excel::import(new KomponenStockImport, $request->file('file'));

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Upload saldo awal Komponen berhasil. Master data dan stok telah diperbarui.'
            ], 201);

        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            DB::rollBack();
            $failures = $e->failures();
            Log::error('Gagal validasi Excel Komponen:', ['failures' => $failures]);

            return response()->json([
                'success' => false,
                'message' => 'Data di Excel tidak valid.',
                'errors'  => $failures,
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Gagal upload Saldo Awal Komponen: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan internal saat memproses file.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /* ================== KAYU LOG ================== */

    public function uploadSaldoAwalKayu(Request $request)
    {
        if (!$request->hasFile('file')) {
            return response()->json([
                'success' => false,
                'message' => 'File tidak ditemukan. Pastikan field name adalah "file".',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'max:5120'
            ]
        ], [
            'file.required' => 'File wajib di-upload.',
            'file.file'     => 'File yang di-upload tidak valid.',
            'file.mimetypes'=> 'File harus berformat Excel (.xls, .xlsx) atau CSV (.csv).',
            'file.max'      => 'Ukuran file maksimal 5MB.'
        ]);

        if ($validator->fails()) {
            Log::warning('Validasi file kayu log gagal:', [
                'errors'    => $validator->errors()->toArray(),
                'file_info' => $request->hasFile('file') ? [
                    'original_name' => $request->file('file')->getClientOriginalName(),
                    'mime_type'     => $request->file('file')->getMimeType(),
                    'size'          => $request->file('file')->getSize(),
                ] : 'No file uploaded'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validasi file gagal.',
                'errors'  => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            Excel::import(new KayuLogStockImport, $request->file('file'));

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Upload saldo awal kayu berhasil. Master data dan stok telah diperbarui.'
            ], 201);

        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            DB::rollBack();
            $failures = $e->failures();
            Log::error('Gagal validasi Excel Kayu Log:', ['failures' => $failures]);

            return response()->json([
                'success' => false,
                'message' => 'Data di Excel tidak valid.',
                'errors'  => $failures,
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Gagal upload Saldo Awal Kayu Log: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan internal saat memproses file.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function downloadTemplateKayu()
    {
        try {
            return Excel::download(
                new KayuLogTemplateExport,
                'template_saldo_awal_kayu_log.xlsx'
            );
        } catch (\Exception $e) {
            Log::error('Gagal download template kayu log: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mendownload template kayu log.'
            ], 500);
        }
    }

    /* ================== KAYU RST ================== */

    public function downloadTemplateKayuRst()
    {
        try {
            return Excel::download(
                new KayuTemplateExport,
                'template_saldo_awal_kayu_rst.xlsx'
            );
        } catch (\Exception $e) {
            Log::error('Gagal download template kayu RST: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mendownload template kayu RST.'
            ], 500);
        }
    }

    public function uploadSaldoAwalKayuRst(Request $request)
    {
        if (!$request->hasFile('file')) {
            return response()->json([
                'success' => false,
                'message' => 'File tidak ditemukan. Pastikan field name adalah "file".',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'max:5120'
            ]
        ], [
            'file.required' => 'File wajib di-upload.',
            'file.file'     => 'File yang di-upload tidak valid.',
            'file.mimetypes'=> 'File harus berformat Excel (.xls, .xlsx) atau CSV (.csv).',
            'file.max'      => 'Ukuran file maksimal 5MB.'
        ]);

        if ($validator->fails()) {
            Log::warning('Validasi file kayu RST gagal:', [
                'errors'    => $validator->errors()->toArray(),
                'file_info' => $request->hasFile('file') ? [
                    'original_name' => $request->file('file')->getClientOriginalName(),
                    'mime_type'     => $request->file('file')->getMimeType(),
                    'size'          => $request->file('file')->getSize(),
                ] : 'No file uploaded'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validasi file gagal.',
                'errors'  => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            Excel::import(new KayuStockImport, $request->file('file'));

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Upload saldo awal kayu RST berhasil. Master data dan stok telah diperbarui.'
            ], 201);

        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            DB::rollBack();
            $failures = $e->failures();
            Log::error('Gagal validasi Excel Kayu RST:', ['failures' => $failures]);

            return response()->json([
                'success' => false,
                'message' => 'Data di Excel tidak valid.',
                'errors'  => $failures,
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Gagal upload Saldo Awal Kayu RST: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan internal saat memproses file.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /* ================== PRODUK JADI ================== */

    public function downloadProdukJadiTemplate()
    {
        try {
            $fileName = 'Template_Produk_Jadi_' . now()->format('Ymd_His') . '.xlsx';
            return Excel::download(new ProdukJadiTemplateExport, $fileName);
        } catch (\Exception $e) {
            Log::error('Gagal download template produk jadi: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mendownload template produk jadi. Pastikan file export sudah diperbarui.',
                'error'   => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function uploadSaldoAwalProdukJadi(Request $request)
    {
        if (!$request->hasFile('file')) {
            return response()->json([
                'success' => false,
                'message' => 'File tidak ditemukan. Pastikan field name adalah "file".',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'max:5120'
            ]
        ], [
            'file.required' => 'File wajib di-upload.',
            'file.file'     => 'File yang di-upload tidak valid.',
            'file.mimetypes'=> 'File harus berformat Excel (.xls, .xlsx) atau CSV (.csv).',
            'file.max'      => 'Ukuran file maksimal 5MB.'
        ]);

        if ($validator->fails()) {
            Log::warning('Validasi file produk jadi gagal:', [
                'errors'    => $validator->errors()->toArray(),
                'file_info' => $request->hasFile('file') ? [
                    'original_name' => $request->file('file')->getClientOriginalName(),
                    'mime_type'     => $request->file('file')->getMimeType(),
                    'size'          => $request->file('file')->getSize(),
                ] : 'No file uploaded'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validasi file gagal.',
                'errors'  => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            Excel::import(new \App\Imports\ProdukJadiStockImport, $request->file('file'));

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Upload saldo awal produk jadi berhasil. Master data dan stok telah diperbarui.'
            ], 201);

        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            DB::rollBack();
            $failures = $e->failures();
            Log::error('Gagal validasi Excel Produk Jadi:', ['failures' => $failures]);

            return response()->json([
                'success' => false,
                'message' => 'Data di Excel tidak valid.',
                'errors'  => $failures,
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Gagal upload Saldo Awal Produk Jadi: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan internal saat memproses file.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /* ================== BOM PRODUK ================== */

    public function downloadTemplateBom()
    {
        try {
            return Excel::download(
                new \App\Exports\BomTemplateExport,
                'template_bom_produk.xlsx'
            );
        } catch (\Exception $e) {
            Log::error('Gagal download template BOM: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mendownload template BOM.'
            ], 500);
        }
    }

    public function uploadBom(Request $request)
    {
        if (!$request->hasFile('file')) {
            return response()->json([
                'success' => false,
                'message' => 'File tidak ditemukan. Pastikan field name adalah "file".',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'max:5120'
            ]
        ]);

        if ($validator->fails()) {
            Log::warning('Validasi file BOM gagal:', [
                'errors' => $validator->errors()->toArray(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validasi file gagal.',
                'errors'  => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            Excel::import(new \App\Imports\ProductBomImport, $request->file('file'));

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Upload BOM berhasil diproses.'
            ], 201);
        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            DB::rollBack();
            $failures = $e->failures();
            Log::error('Gagal validasi Excel BOM:', ['failures' => $failures]);

            return response()->json([
                'success' => false,
                'message' => 'Data di Excel tidak valid.',
                'errors'  => $failures,
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Gagal upload BOM: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan internal saat memproses file.',
            ], 500);
        }
    }
}
