<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryLog;
use App\Models\Warehouse;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class InventoryLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $query = InventoryLog::with([
                'item:id,name,code',
                'item.unit:id,name',
                'warehouse:id,name',
            ])
            ->select([
                'id',
                'date',
                'time',
                'item_id',
                'warehouse_id',
                'qty',
                'direction',
                'transaction_type',
                'reference_number',
                'division',
                'notes',
                'created_at'
            ])
            ->orderBy('date', 'asc')
            ->orderBy('time', 'asc')
            ->orderBy('id', 'asc');

            // Filter tanggal (wajib)
            if ($request->filled('start_date') && $request->filled('end_date')) {
                $query->whereBetween('date', [$request->start_date, $request->end_date]);
            }

            // Filter gudang
            if ($request->filled('warehouse_id')) {
                $query->where('warehouse_id', $request->warehouse_id);
            }

            // Filter tipe transaksi
            if ($request->filled('transaction_type')) {
                $query->where('transaction_type', $request->transaction_type);
            }

            // Filter item
            if ($request->filled('item_id')) {
                $query->where('item_id', $request->item_id);
            }

            // Hitung summary (sebelum pagination)
            $summaryQuery = clone $query;
            $summary = [
                'total_masuk' => (clone $summaryQuery)->where('direction', 'IN')->sum('qty'),
                'total_keluar' => (clone $summaryQuery)->where('direction', 'OUT')->sum('qty'),
            ];

            // Hitung saldo awal jika filter item dipilih
            $saldoAwal = 0;
            $showSaldo = $request->filled('item_id');

            if ($showSaldo && $request->filled('start_date')) {
                $saldoAwal = $this->hitungSaldoAwal(
                    $request->item_id,
                    $request->start_date,
                    $request->warehouse_id
                );
            }

            $logs = $query->paginate($request->input('per_page', 50));

            // Transform data dengan running balance jika filter item dipilih
            $runningBalance = $saldoAwal;
            $logs->getCollection()->transform(function ($log) use ($showSaldo, &$runningBalance) {
                $masuk = $log->direction === 'IN' ? (float) $log->qty : null;
                $keluar = $log->direction === 'OUT' ? (float) $log->qty : null;

                // Hitung running balance
                if ($showSaldo) {
                    if ($masuk) {
                        $runningBalance += $masuk;
                    }
                    if ($keluar) {
                        $runningBalance -= $keluar;
                    }
                }

                return [
                    'id' => $log->id,
                    'tanggal' => $log->date->format('d/m/Y'),
                    'jam' => $log->time ? substr($log->time, 0, 5) : '-',
                    'no_bukti' => $log->reference_number ?? '-',
                    'nama_item' => $log->item->name ?? '-',
                    'kode_item' => $log->item->code ?? '-',
                    'satuan' => $log->item->unit->name ?? '-',
                    'tipe' => $this->getLabelTipe($log->transaction_type),
                    'tipe_raw' => $log->transaction_type,
                    'gudang' => $log->warehouse->name ?? '-',
                    'masuk' => $masuk ? $this->formatNumber($masuk) : null,
                    'keluar' => $keluar ? $this->formatNumber($keluar) : null,
                    'saldo' => $showSaldo ? $this->formatNumber($runningBalance) : null,
                    'keterangan' => $log->notes ?? $log->division ?? '-',
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $logs,
                'summary' => [
                    'total_masuk' => $this->formatNumber($summary['total_masuk']),
                    'total_keluar' => $this->formatNumber($summary['total_keluar']),
                    'saldo_awal' => $showSaldo ? $this->formatNumber($saldoAwal) : null,
                ],
                'show_saldo' => $showSaldo,
            ]);

        } catch (\Exception $e) {
            Log::error('Gagal mengambil laporan mutasi: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data.'
            ], 500);
        }
    }

    private function hitungSaldoAwal(int $itemId, string $startDate, ?int $warehouseId = null): float
    {
        $query = InventoryLog::where('item_id', $itemId)
            ->where('date', '<', $startDate);

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $totalMasuk = (clone $query)->where('direction', 'IN')->sum('qty');
        $totalKeluar = (clone $query)->where('direction', 'OUT')->sum('qty');

        return (float) $totalMasuk - (float) $totalKeluar;
    }

    public function getWarehouses(): JsonResponse
    {
        try {
            $warehouses = Warehouse::select('id', 'name')->orderBy('name')->get();

            return response()->json([
                'success' => true,
                'data' => $warehouses
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data gudang.'
            ], 500);
        }
    }

    public function getTransactionTypes(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'PURCHASE', 'label' => 'Pembelian'],
                ['value' => 'SALE', 'label' => 'Penjualan'],
                ['value' => 'PRODUCTION', 'label' => 'Produksi'],
                ['value' => 'USAGE', 'label' => 'Pemakaian'],
                ['value' => 'ADJUSTMENT', 'label' => 'Penyesuaian'],
                ['value' => 'TRANSFER_IN', 'label' => 'Transfer Masuk'],
                ['value' => 'TRANSFER_OUT', 'label' => 'Transfer Keluar'],
            ]
        ]);
    }

    public function getItems(Request $request): JsonResponse
    {
        try {
            $query = Item::select('id', 'code', 'name')
                ->orderBy('name');

            // Searchable: filter berdasarkan keyword
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                      ->orWhere('code', 'LIKE', "%{$search}%");
                });
            }

            // Limit hasil untuk performa
            $items = $query->limit(50)->get();

            return response()->json([
                'success' => true,
                'data' => $items
            ]);
        } catch (\Exception $e) {
            Log::error('Gagal mengambil daftar item: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data item.'
            ], 500);
        }
    }

    private function getLabelTipe(string $type): string
    {
        $labels = [
            'PURCHASE' => 'BELI',
            'SALE' => 'JUAL',
            'PRODUCTION' => 'PROD',
            'USAGE' => 'PAKAI',
            'ADJUSTMENT' => 'ADJUST',
            'TRANSFER_IN' => 'MASUK',
            'TRANSFER_OUT' => 'KELUAR',
        ];

        return $labels[$type] ?? $type;
    }

    private function formatNumber($num)
    {
        if ($num === null) return null;
        $number = floatval($num);
        if (floor($number) == $number) {
            return (int) $number;
        }
        return round($number, 2);
    }
}
