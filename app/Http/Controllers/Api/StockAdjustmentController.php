<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Item;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\KayuStockImport;
use App\Exports\KayuTemplateExport;

class StockAdjustmentController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'item_id' => 'required|integer|exists:items,id',
            'type' => 'required|string|in:Stok Masuk,Stok Keluar',
            'quantity' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:1000',
        ], [
            'quantity.min' => 'Kuantitas harus lebih besar dari 0.',
            'type.in' => 'Tipe penyesuaian tidak valid.'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false, 
                'message' => 'Validasi gagal.', 
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $item = Item::lockForUpdate()->findOrFail($request->item_id);
            
            $quantity = (float) $request->quantity;
            $type = $request->type;
            $movementQuantity = ($type === 'Stok Keluar') ? -$quantity : $quantity;

            StockMovement::create([
                'item_id' => $item->id,
                'type' => $type,
                'quantity' => $movementQuantity,
                'notes' => $request->notes ?? 'Penyesuaian manual dari admin.',
            ]);

            $item->increment('stock', $movementQuantity);
            $item->refresh();

            DB::commit();
            
            return response()->json([
                'success' => true, 
                'message' => 'Penyesuaian stok berhasil disimpan.',
                'new_stock' => $item->stock 
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Gagal melakukan penyesuaian stok: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false, 
                'message' => 'Terjadi kesalahan pada server.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

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
            'file.file' => 'File yang di-upload tidak valid.',
            'file.mimetypes' => 'File harus berformat Excel (.xls, .xlsx) atau CSV (.csv).',
            'file.max' => 'Ukuran file maksimal 5MB.'
        ]);
 
        if ($validator->fails()) {
            Log::warning('Validasi file kayu gagal:', [
                'errors' => $validator->errors()->toArray(),
                'file_info' => $request->hasFile('file') ? [
                    'original_name' => $request->file('file')->getClientOriginalName(),
                    'mime_type' => $request->file('file')->getMimeType(),
                    'size' => $request->file('file')->getSize(),
                ] : 'No file uploaded'
            ]);
 
            return response()->json([
                'success' => false,
                'message' => 'Validasi file gagal.',
                'errors' => $validator->errors()
            ], 422);
        }
 
        DB::beginTransaction();
        try {
            Excel::import(new KayuStockImport, $request->file('file'));
             
            DB::commit();
             
            return response()->json([
                'success' => true,
                'message' => 'Upload saldo awal kayu berhasil. Master data dan stok telah diperbarui.'
            ], 201);
 
        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            DB::rollBack();
            $failures = $e->failures();
            Log::error('Gagal validasi Excel Kayu:', ['failures' => $failures]);
             
            return response()->json([
                'success' => false,
                'message' => 'Data di Excel tidak valid.',
                'errors' => $failures,
            ], 422);
 
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Gagal upload Saldo Awal Kayu: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
 
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan internal saat memproses file.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
    
    public function downloadTemplateKayu()
    {
        try {
            return Excel::download(new KayuTemplateExport, 'template_saldo_awal_kayu.xlsx');
        } catch (\Exception $e) {
            Log::error('Gagal download template kayu: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mendownload template kayu.'
            ], 500);
        }
    }
}