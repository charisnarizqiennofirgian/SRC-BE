<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SawmillProduction;
use App\Models\SawmillProductionLog;
use App\Models\SawmillProductionRst;
use App\Models\Stock;
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
                'document_number' => $documentNumber,
                'date' => $data['date'],
                'warehouse_from_id' => $data['warehouse_from_id'],
                'warehouse_to_id' => $data['warehouse_to_id'],
                'notes' => $data['notes'] ?? null,
            ]);

            // Kurangi stok LOG di gudang asal
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

                SawmillProductionLog::create([
                    'sawmill_production_id' => $production->id,
                    'item_log_id' => $log['item_log_id'],
                    'qty_log_pcs' => $log['qty_log_pcs'],
                ]);
            }

            // Tambah stok RST di gudang tujuan
            foreach ($data['rsts'] as $rst) {
                $stock = Stock::where('item_id', $rst['item_rst_id'])
                    ->where('warehouse_id', $data['warehouse_to_id'])
                    ->lockForUpdate()
                    ->first();

                if ($stock) {
                    $stock->increment('quantity', $rst['qty_rst_pcs']);
                } else {
                    Stock::create([
                        'item_id' => $rst['item_rst_id'],
                        'warehouse_id' => $data['warehouse_to_id'],
                        'quantity' => $rst['qty_rst_pcs'],
                    ]);
                }

                SawmillProductionRst::create([
                    'sawmill_production_id' => $production->id,
                    'item_rst_id' => $rst['item_rst_id'],
                    'qty_rst_pcs' => $rst['qty_rst_pcs'],
                    'volume_rst_m3' => $rst['volume_rst_m3'],
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $production->load('logs', 'rsts'),
            ], 201);
        });
    }
}
