<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Warehouse;
use App\Models\SalesOrder;
use App\Models\InventoryLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProductionMonitoringController extends Controller
{
    // Sub-tabel map — dipakai di index, detail, dan exportExcel
    private array $subTableMap = [
        'KD'         => 'kd_productions',
        'PEMBAHANAN' => 'pembahanan_productions',
        'MOULDING'   => 'moulding_productions',
        'MESIN'      => 'mesin_productions',
    ];

    private function getSearchIds(string $txType, array $poIds): array
    {
        $searchIds = $poIds;
        if (isset($this->subTableMap[$txType]) && !empty($poIds)) {
            $subIds = DB::table($this->subTableMap[$txType])
                ->whereIn('ref_po_id', $poIds)
                ->pluck('id')
                ->toArray();
            $searchIds = array_merge($poIds, $subIds);
        }
        return $searchIds;
    }

    public function index(Request $request)
    {
        try {
            $warehouses = Warehouse::whereIn('code', [
                'RUSKOMP', 'ASSEMBLING', 'SANDING', 'RUSTIK', 'FINISHING', 'PACKING'
            ])->pluck('id', 'code');

            $warehouseHilir = [
                'ruskomp'    => $warehouses['RUSKOMP']    ?? null,
                'assembling' => $warehouses['ASSEMBLING'] ?? null,
                'sanding'    => $warehouses['SANDING']    ?? null,
                'rustik'     => $warehouses['RUSTIK']     ?? null,
                'finishing'  => $warehouses['FINISHING']  ?? null,
                'packing'    => $warehouses['PACKING']    ?? null,
            ];

            $query = SalesOrder::with(['buyer', 'details.item', 'productionOrders'])
                ->where('status', '!=', 'Draft')
                ->orderBy('created_at', 'desc');

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('so_number', 'LIKE', "%{$search}%")
                      ->orWhereHas('buyer', fn($bq) => $bq->where('name', 'LIKE', "%{$search}%"));
                });
            }

            if ($request->filled('limit')) {
                $query->limit($request->limit);
            }

            $salesOrders = $query->get();
            $result = [];

            foreach ($salesOrders as $so) {
                $poIds = $so->productionOrders->pluck('id')->toArray();

                foreach ($so->details as $detail) {
                    $itemId = $detail->item_id;

                    // === ZONA HULU ===
                    $stageTypes = [
                        'sanwil'     => 'SAWMILL',
                        'kd'         => 'KD',
                        'pembahanan' => 'PEMBAHANAN',
                        'moulding'   => 'MOULDING',
                        'mesin'      => 'MESIN',
                    ];

                    $statusHulu = [];
                    foreach ($stageTypes as $key => $txType) {
                        if (empty($poIds)) {
                            $statusHulu[$key] = 'waiting';
                            continue;
                        }

                        $searchIds = $this->getSearchIds($txType, $poIds);

                        $totalIn  = InventoryLog::where('transaction_type', $txType)
                            ->whereIn('reference_id', $searchIds)
                            ->where('direction', 'IN')
                            ->sum('qty');

                        $totalOut = InventoryLog::where('transaction_type', $txType)
                            ->whereIn('reference_id', $searchIds)
                            ->where('direction', 'OUT')
                            ->sum('qty');

                        if ($totalIn == 0) {
                            $statusHulu[$key] = 'waiting';
                        } else {
                            $statusHulu[$key] = 'in_progress'; // selalu in_progress kalau ada aktivitas
                        }
                    }

                    // === ZONA HILIR ===
                    $qtyHilir = [];
                    foreach ($warehouseHilir as $key => $warehouseId) {
                        if (!$warehouseId) { $qtyHilir[$key] = 0; continue; }
                        $qtyHilir[$key] = (float) Inventory::where('warehouse_id', $warehouseId)
                            ->where('item_id', $itemId)->sum('qty_pcs');
                    }

                    $target = (float) $detail->quantity;

                    $qtyQcFinal = InventoryLog::where('transaction_type', 'QC_FINAL')
                        ->where('item_id', $itemId)
                        ->where('direction', 'IN')
                        ->when(!empty($poIds), fn($q) => $q->whereIn('reference_id', $poIds))
                        ->sum('qty');

                    $qtyReject = InventoryLog::where('transaction_type', 'LIKE', '%REJECT%')
                        ->when(!empty($poIds), fn($q) => $q->whereIn('reference_id', $poIds))
                        ->where('direction', 'IN')
                        ->sum('qty');

                    $qtyPacking  = $qtyHilir['packing'];
                    $poCompleted = $so->productionOrders->where('status', 'completed')->count() > 0;

                    $result[] = [
                        'so_id'             => $so->id,
                        'so_number'         => $so->so_number,
                        'so_date'           => $so->so_date ? Carbon::parse($so->so_date)->format('d/m/Y') : '-',
                        'buyer_name'        => $so->buyer?->name ?? '-',
                        'item_id'           => $itemId,
                        'item_name'         => $detail->item?->name ?? '-',
                        'item_code'         => $detail->item?->code ?? '-',
                        'target'            => $target,
                        'po_completed'      => $poCompleted,

                        'status_sanwil'     => $statusHulu['sanwil'],
                        'status_kd'         => $statusHulu['kd'],
                        'status_pembahanan' => $statusHulu['pembahanan'],
                        'status_moulding'   => $statusHulu['moulding'],
                        'status_mesin'      => $statusHulu['mesin'],

                        'qty_ruskomp'       => $qtyHilir['ruskomp'],
                        'qty_assembling'    => $qtyHilir['assembling'],
                        'qty_sanding'       => $qtyHilir['sanding'],
                        'qty_rustik'        => $qtyHilir['rustik'],
                        'qty_finishing'     => $qtyHilir['finishing'],
                        'qty_qc_final'      => (float) $qtyQcFinal,
                        'qty_packing'       => $qtyHilir['packing'],
                        'qty_reject'        => (float) $qtyReject,
                        'has_reject'        => $qtyReject > 0,

                        'sisa'              => max(0, $target - $qtyPacking),
                        'is_done'           => ($qtyPacking >= $target && $target > 0) || $poCompleted,
                    ];
                }
            }

            return response()->json([
                'success'  => true,
                'data'     => $result,
                'total_so' => $salesOrders->count(),
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function detail(Request $request)
    {
        $request->validate([
            'so_id'   => ['required', 'integer'],
            'item_id' => ['nullable', 'integer'],
        ]);

        try {
            $so    = SalesOrder::with(['buyer', 'productionOrders'])->findOrFail($request->so_id);
            $poIds = $so->productionOrders->pluck('id')->toArray();

            if (empty($poIds)) {
                return response()->json([
                    'success'    => true,
                    'so_number'  => $so->so_number,
                    'buyer_name' => $so->buyer?->name ?? '-',
                    'stages'     => [],
                    'rejects'    => [],
                ]);
            }

            $stages = [
                'SAWMILL'         => 'Sawmill',
                'KD'              => 'KD',
                'PEMBAHANAN'      => 'Pembahanan',
                'MOULDING'        => 'Moulding',
                'MESIN'           => 'Mesin',
                'RUSTIK_KOMPONEN' => 'Rustik Komponen',
                'SUB_ASSEMBLING'  => 'Sub Assembling',
                'RAKIT'           => 'Rakit',
                'SANDING'         => 'Sanding',
                'RUSTIK'          => 'Rustik',
                'FINISHING'       => 'Finishing',
                'QC_FINAL'        => 'QC Final',
                'PACKING'         => 'Packing',
            ];

            $result = [];
            $mapLog = fn($log) => [
                'date'             => Carbon::parse($log->date)->format('d/m/Y'),
                'time'             => $log->time,
                'item_name'        => $log->item?->name ?? '-',
                'item_code'        => $log->item?->code ?? '-',
                'warehouse_name'   => $log->warehouse?->name ?? '-',
                'qty'              => (float) $log->qty,
                'notes'            => $log->notes ?? '-',
                'user_name'        => $log->user?->name ?? '-',
                'reference_number' => $log->reference_number ?? '-',
            ];

            foreach ($stages as $type => $label) {
                $searchIds = $this->getSearchIds($type, $poIds);

                // Untuk MESIN, kumpulkan subIds agar bisa query nama mesin
                $subIds = [];
                if ($type === 'MESIN' && !empty($poIds)) {
                    $subIds = DB::table('mesin_productions')
                        ->whereIn('ref_po_id', $poIds)
                        ->pluck('id')
                        ->toArray();
                }

                $logsIn  = InventoryLog::where('transaction_type', $type)
                    ->whereIn('reference_id', $searchIds)->where('direction', 'IN')
                    ->with(['warehouse', 'item', 'user'])->orderBy('date', 'asc')->get();

                $logsOut = InventoryLog::where('transaction_type', $type)
                    ->whereIn('reference_id', $searchIds)->where('direction', 'OUT')
                    ->with(['warehouse', 'item', 'user'])->orderBy('date', 'asc')->get();

                if ($logsIn->isEmpty() && $logsOut->isEmpty()) continue;

                // Ambil nama mesin jika stage MESIN
                $machineName = null;
                if ($type === 'MESIN' && !empty($subIds)) {
                    $machineName = DB::table('mesin_productions')
                        ->join('machines', 'machines.id', '=', 'mesin_productions.machine_id')
                        ->whereIn('mesin_productions.id', $subIds)
                        ->pluck('machines.name')
                        ->unique()
                        ->implode(', ');
                }

                $result[] = [
                    'stage'        => $label . ($machineName ? " — {$machineName}" : ''),
                    'type'         => $type,
                    'total_in'     => (float) $logsIn->sum('qty'),
                    'total_out'    => (float) $logsOut->sum('qty'),
                    'inputs'       => $logsIn->map($mapLog),
                    'outputs'      => $logsOut->map($mapLog),
                ];
            }

            $rejectLogs = InventoryLog::where('transaction_type', 'LIKE', '%REJECT%')
                ->whereIn('reference_id', $poIds)->where('direction', 'IN')
                ->with(['warehouse', 'item', 'user'])->orderBy('date', 'asc')->get();

            $rejects = $rejectLogs->map(fn($log) => [
                'date'             => $log->date,
                'item_name'        => $log->item?->name ?? '-',
                'item_code'        => $log->item?->code ?? '-',
                'qty'              => (float) $log->qty,
                'transaction_type' => $log->transaction_type,
                'notes'            => $log->notes ?? '-',
                'reference_number' => $log->reference_number ?? '-',
                'user_name'        => $log->user?->name ?? '-',
            ])->toArray();

            return response()->json([
                'success'    => true,
                'so_number'  => $so->so_number,
                'buyer_name' => $so->buyer?->name ?? '-',
                'po_numbers' => $so->productionOrders->pluck('po_number'),
                'stages'     => $result,
                'rejects'    => $rejects,
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function exportExcel(Request $request)
    {
        $request->validate(['so_id' => ['required', 'integer']]);

        try {
            $so    = SalesOrder::with(['buyer', 'productionOrders', 'details.item'])
                        ->findOrFail($request->so_id);
            $poIds = $so->productionOrders->pluck('id')->toArray();

            $stages = [
                'SAWMILL'         => 'Sawmill',
                'KD'              => 'KD',
                'PEMBAHANAN'      => 'Pembahanan',
                'MOULDING'        => 'Moulding',
                'MESIN'           => 'Mesin',
                'RUSTIK_KOMPONEN' => 'Rustik Komponen',
                'SUB_ASSEMBLING'  => 'Sub Assembling',
                'RAKIT'           => 'Rakit',
                'SANDING'         => 'Sanding',
                'RUSTIK'          => 'Rustik',
                'FINISHING'       => 'Finishing',
                'QC_FINAL'        => 'QC Final',
                'PACKING'         => 'Packing',
            ];

            $stagesData = [];
            foreach ($stages as $type => $label) {
                $searchIds = $this->getSearchIds($type, $poIds);

                $logsIn  = InventoryLog::where('transaction_type', $type)
                    ->whereIn('reference_id', $searchIds)->where('direction', 'IN')
                    ->with(['warehouse', 'item', 'user'])->orderBy('date', 'asc')->get();

                $logsOut = InventoryLog::where('transaction_type', $type)
                    ->whereIn('reference_id', $searchIds)->where('direction', 'OUT')
                    ->with(['warehouse', 'item', 'user'])->orderBy('date', 'asc')->get();

                if ($logsIn->isEmpty() && $logsOut->isEmpty()) continue;

                $stagesData[] = [
                    'label'   => $label,
                    'type'    => $type,
                    'total_in'  => $logsIn->sum('qty'),
                    'total_out' => $logsOut->sum('qty'),
                    'inputs'  => $logsIn,
                    'outputs' => $logsOut,
                ];
            }

            $rejectLogs = InventoryLog::where('transaction_type', 'LIKE', '%REJECT%')
                ->whereIn('reference_id', $poIds)->where('direction', 'IN')
                ->with(['warehouse', 'item', 'user'])->orderBy('date', 'asc')->get();

            // === BUAT EXCEL ===
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Laporan Produksi');

            $stageColors = [
                'SAWMILL'         => 'D97706',
                'KD'              => '0369A1',
                'PEMBAHANAN'      => '7C3AED',
                'MOULDING'        => '059669',
                'MESIN'           => 'DC2626',
                'RUSTIK_KOMPONEN' => '9A3412',
                'SUB_ASSEMBLING'  => '1D4ED8',
                'RAKIT'           => '0E7490',
                'SANDING'         => 'B45309',
                'RUSTIK'          => '9A3412',
                'FINISHING'       => 'BE185D',
                'QC_FINAL'        => '166534',
                'PACKING'         => '1E40AF',
            ];

            $row = 1;

            $sheet->mergeCells("A{$row}:H{$row}");
            $sheet->setCellValue("A{$row}", 'LAPORAN PRODUKSI');
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1E3A5F']],
                'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(28);
            $row++;

            $infoRows = [
                ['No. SO',        $so->so_number],
                ['Buyer',         $so->buyer?->name ?? '-'],
                ['Item',          $so->details->first()?->item?->name ?? '-'],
                ['Kode Item',     $so->details->first()?->item?->code ?? '-'],
                ['Target Qty',    $so->details->sum('quantity') . ' pcs'],
                ['Tanggal Cetak', now()->format('d/m/Y H:i')],
            ];

            foreach ($infoRows as $info) {
                $sheet->setCellValue("A{$row}", $info[0]);
                $sheet->setCellValue("B{$row}", $info[1]);
                $sheet->getStyle("A{$row}")->getFont()->setBold(true);
                $sheet->getStyle("A{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB('F3F4F6');
                $sheet->mergeCells("B{$row}:H{$row}");
                $row++;
            }

            $row++;
            $colHeaders = ['TANGGAL', 'NO. DOKUMEN', 'TIPE', 'ITEM', 'GUDANG', 'QTY', 'CATATAN', 'USER'];

            foreach ($stagesData as $stage) {
                $color = $stageColors[$stage['type']] ?? '374151';

                $sheet->mergeCells("A{$row}:H{$row}");
                $sheet->setCellValue("A{$row}", strtoupper($stage['label']));
                $sheet->getStyle("A{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => $color]],
                    'alignment' => ['horizontal' => 'left', 'vertical' => 'center'],
                ]);
                $sheet->getRowDimension($row)->setRowHeight(22);
                $row++;

                $sheet->setCellValue("A{$row}", "Total Input: {$stage['total_out']} pcs | Total Output: {$stage['total_in']} pcs");
                $sheet->mergeCells("A{$row}:H{$row}");
                $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->setSize(9);
                $sheet->getStyle("A{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB('FFF7ED');
                $row++;

                if ($stage['outputs']->isNotEmpty()) {
                    $sheet->setCellValue("A{$row}", 'BAHAN MASUK / DIPAKAI');
                    $sheet->mergeCells("A{$row}:H{$row}");
                    $sheet->getStyle("A{$row}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '92400E']],
                        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FEF3C7']],
                    ]);
                    $row++;

                    foreach ($colHeaders as $i => $h) $sheet->setCellValue(chr(65 + $i) . $row, $h);
                    $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => 'FFFFFF']],
                        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '6B7280']],
                    ]);
                    $row++;

                    foreach ($stage['outputs'] as $log) {
                        $sheet->setCellValue("A{$row}", Carbon::parse($log->date)->format('d/m/Y'));
                        $sheet->setCellValue("B{$row}", $log->reference_number ?? '-');
                        $sheet->setCellValue("C{$row}", 'OUT');
                        $sheet->setCellValue("D{$row}", ($log->item?->code ? "[{$log->item->code}] " : '') . ($log->item?->name ?? '-'));
                        $sheet->setCellValue("E{$row}", $log->warehouse?->name ?? '-');
                        $sheet->setCellValue("F{$row}", $log->qty);
                        $sheet->setCellValue("G{$row}", $log->notes ?? '-');
                        $sheet->setCellValue("H{$row}", $log->user?->name ?? '-');
                        $sheet->getStyle("A{$row}:H{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB('FFFBEB');
                        $row++;
                    }
                }

                if ($stage['inputs']->isNotEmpty()) {
                    $sheet->setCellValue("A{$row}", 'HASIL / KELUAR');
                    $sheet->mergeCells("A{$row}:H{$row}");
                    $sheet->getStyle("A{$row}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '065F46']],
                        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'D1FAE5']],
                    ]);
                    $row++;

                    foreach ($colHeaders as $i => $h) $sheet->setCellValue(chr(65 + $i) . $row, $h);
                    $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => 'FFFFFF']],
                        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '6B7280']],
                    ]);
                    $row++;

                    foreach ($stage['inputs'] as $log) {
                        $sheet->setCellValue("A{$row}", Carbon::parse($log->date)->format('d/m/Y'));
                        $sheet->setCellValue("B{$row}", $log->reference_number ?? '-');
                        $sheet->setCellValue("C{$row}", 'IN');
                        $sheet->setCellValue("D{$row}", ($log->item?->code ? "[{$log->item->code}] " : '') . ($log->item?->name ?? '-'));
                        $sheet->setCellValue("E{$row}", $log->warehouse?->name ?? '-');
                        $sheet->setCellValue("F{$row}", $log->qty);
                        $sheet->setCellValue("G{$row}", $log->notes ?? '-');
                        $sheet->setCellValue("H{$row}", $log->user?->name ?? '-');
                        $sheet->getStyle("A{$row}:H{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB('F0FDF4');
                        $row++;
                    }
                }

                $row++;
            }

            if ($rejectLogs->isNotEmpty()) {
                $sheet->mergeCells("A{$row}:H{$row}");
                $sheet->setCellValue("A{$row}", 'REJECT');
                $sheet->getStyle("A{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'DC2626']],
                ]);
                $sheet->getRowDimension($row)->setRowHeight(22);
                $row++;

                foreach ($colHeaders as $i => $h) $sheet->setCellValue(chr(65 + $i) . $row, $h);
                $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '6B7280']],
                ]);
                $row++;

                foreach ($rejectLogs as $log) {
                    $sheet->setCellValue("A{$row}", Carbon::parse($log->date)->format('d/m/Y'));
                    $sheet->setCellValue("B{$row}", $log->reference_number ?? '-');
                    $sheet->setCellValue("C{$row}", $log->transaction_type);
                    $sheet->setCellValue("D{$row}", ($log->item?->code ? "[{$log->item->code}] " : '') . ($log->item?->name ?? '-'));
                    $sheet->setCellValue("E{$row}", $log->warehouse?->name ?? '-');
                    $sheet->setCellValue("F{$row}", $log->qty);
                    $sheet->setCellValue("G{$row}", $log->notes ?? '-');
                    $sheet->setCellValue("H{$row}", $log->user?->name ?? '-');
                    $sheet->getStyle("A{$row}:H{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB('FEF2F2');
                    $row++;
                }
            }

            foreach (range('A', 'H') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
            $sheet->getStyle("A1:H" . ($row - 1))->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'E5E7EB']]],
            ]);

            $filename = 'Laporan_Produksi_' . str_replace('/', '-', $so->so_number) . '_' . now()->format('Ymd') . '.xlsx';
            $writer   = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $tempFile = tempnam(sys_get_temp_dir(), 'excel_');
            $writer->save($tempFile);

            return response()->download($tempFile, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}