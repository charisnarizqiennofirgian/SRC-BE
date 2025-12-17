<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SawmillProduction;
use App\Models\SawmillProductionLog;
use App\Models\SawmillProductionRst;
use App\Models\Stock;
use App\Models\Item;
use App\Models\Inventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SawmillProductionController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'warehouse_from_id' => ['required', 'exists:warehouses,id'],
            'warehouse_to_id' => ['required', 'exists:warehouses,id'],
            'notes' => ['nullable', 'string'],

            // identitas PO & produk akhir (boleh nullable di awal, tapi flow PM: sebaiknya wajib)
            'ref_po_id' => ['nullable', 'string', 'max:255'],
            'ref_product_id' => ['nullable', 'integer'],

            'logs' => ['required', 'array', 'min:1'],
            'logs.*.item_log_id' => ['required', 'exists:items,id'],
            'logs.*.qty_log_pcs' => ['required', 'integer', 'min:1'],

            'rsts' => ['required', 'array', 'min:1'],
            'rsts.*.item_rst_id' => ['required', 'exists:items,id'],
            'rsts.*.qty_rst_pcs' => ['required', 'integer', 'min:1'],
            'rsts.*.volume_rst_m3' => ['required', 'numeric', 'min:0'],
        ]);

        return DB::transaction(function () use ($data) {
            $runningNumber = SawmillProduction::whereYear('date', now()->year)
                ->whereMonth('date', now()->month)
                ->count() + 1;

            $documentNumber = 'SW-' . now()->format('Ym') . '-' . str_pad($runningNumber, 3, '0', STR_PAD_LEFT);

            $production = SawmillProduction::create([
                'document_number'   => $documentNumber,
                'date'              => $data['date'],
                'warehouse_from_id' => $data['warehouse_from_id'],
                'warehouse_to_id'   => $data['warehouse_to_id'],
                'notes'             => $data['notes'] ?? null,
                'ref_po_id'         => $data['ref_po_id'] ?? null,       // simpan di header
                'ref_product_id'    => $data['ref_product_id'] ?? null,
            ]);

            // Kurangi stok LOG di gudang asal + simpan volume_log_m3
            foreach ($data['logs'] as $log) {
                $stock = Stock::where('item_id', $log['item_log_id'])
                    ->where('warehouse_id', $data['warehouse_from_id'])
                    ->lockForUpdate()
                    ->first();

                $currentQty = $stock?->quantity ?? 0;

                if ($currentQty < $log['qty_log_pcs']) {
                    throw ValidationException::withMessages([
                        'logs' => ["Stok log untuk item {$log['item_log_id']} di gudang asal tidak mencukupi."],
                    ]);
                }

                if ($stock) {
                    $stock->decrement('quantity', $log['qty_log_pcs']);
                }

                // ambil volume per batang dari master item
                $itemLog       = Item::find($log['item_log_id']);
                $volumePerPcs  = $itemLog?->volume_m3 ?? 0;
                $volumeLogTotal = $log['qty_log_pcs'] * $volumePerPcs;

                SawmillProductionLog::create([
                    'sawmill_production_id' => $production->id,
                    'item_log_id'           => $log['item_log_id'],
                    'qty_log_pcs'           => $log['qty_log_pcs'],
                    'volume_log_m3'         => $volumeLogTotal,
                ]);
            }

            // Tambah stok RST di gudang tujuan + catat ke tabel inventories (tracking PO & produk)
            foreach ($data['rsts'] as $rst) {
                $stock = Stock::where('item_id', $rst['item_rst_id'])
                    ->where('warehouse_id', $data['warehouse_to_id'])
                    ->lockForUpdate()
                    ->first();

                if ($stock) {
                    $stock->increment('quantity', $rst['qty_rst_pcs']);
                } else {
                    Stock::create([
                        'item_id'      => $rst['item_rst_id'],
                        'warehouse_id' => $data['warehouse_to_id'],
                        'quantity'     => $rst['qty_rst_pcs'],
                    ]);
                }

                SawmillProductionRst::create([
                    'sawmill_production_id' => $production->id,
                    'item_rst_id'           => $rst['item_rst_id'],
                    'qty_rst_pcs'           => $rst['qty_rst_pcs'],
                    'volume_rst_m3'         => $rst['volume_rst_m3'],
                ]);

                // === INVENTORY PER GUDANG PER PO / PRODUK ===
                // Satu baris per kombinasi: gudang tujuan + item RST + ref_po_id + ref_product_id
                $inventory = Inventory::where('warehouse_id', $data['warehouse_to_id'])
                    ->where('item_id', $rst['item_rst_id'])
                    ->where('ref_po_id', $data['ref_po_id'] ?? null)
                    ->where('ref_product_id', $data['ref_product_id'] ?? null)
                    ->lockForUpdate()
                    ->first();

                if ($inventory) {
                    $inventory->qty += $rst['qty_rst_pcs']; // di sini pakai pcs; kalau mau pakai m3 tinggal ganti
                    $inventory->save();
                } else {
                    Inventory::create([
                        'warehouse_id'   => $data['warehouse_to_id'],
                        'item_id'        => $rst['item_rst_id'],
                        'qty'            => $rst['qty_rst_pcs'],
                        'ref_po_id'      => $data['ref_po_id'] ?? null,
                        'ref_product_id' => $data['ref_product_id'] ?? null,
                    ]);
                }
            }

            // === HITUNG TOTAL & RENDEMEN ===
            $totalLogM3   = $production->logs()->sum('volume_log_m3');
            $totalRstM3   = $production->rsts()->sum('volume_rst_m3');
            $yieldPercent = $totalLogM3 > 0
                ? ($totalRstM3 / $totalLogM3) * 100
                : 0;

            $production->update([
                'total_log_m3'   => $totalLogM3,
                'total_rst_m3'   => $totalRstM3,
                'yield_percent'  => $yieldPercent,
            ]);

            return response()->json([
                'success' => true,
                'data'    => $production->load('logs', 'rsts'),
            ], 201);
        });
    }
}
