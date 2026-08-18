<?php

namespace App\Exports;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class PurchaseRequestRekapExport implements FromArray, WithHeadings, WithEvents
{
    private string $prStart;
    private string $prEnd;
    private float $grandTotalQty   = 0;
    private float $grandTotalHarga = 0;
    private int $lastDataRow = 1;

    public function __construct(string $prStart, string $prEnd)
    {
        $this->prStart = $prStart;
        $this->prEnd   = $prEnd;
    }

    public function array(): array
    {
        $prs = PurchaseRequest::whereBetween('pr_number', [$this->prStart, $this->prEnd])
            ->with(['details.item'])
            ->orderBy('pr_number')
            ->get();

        $rows = [];

        foreach ($prs as $pr) {
            $po            = PurchaseOrder::where('pr_id', $pr->id)->with(['details', 'supplier'])->first();
            $priceByItemId = $po ? $po->details->pluck('price', 'item_id') : collect();
            $supplierName  = $po->supplier->name ?? '-';

            // Tiap baris detail PR ditampilkan apa adanya (tidak digabung), walau item/PR-nya sama
            foreach ($pr->details as $detail) {
                $itemName = $detail->item->name ?? ('Item #' . $detail->item_id);
                $qty      = (float) $detail->qty_approved;
                $harga    = $priceByItemId->get($detail->item_id);
                $total    = $harga !== null ? round($qty * (float) $harga, 2) : null;

                $rows[] = [
                    $pr->pr_number,
                    $supplierName,
                    $itemName,
                    $qty,
                    $harga !== null ? (float) $harga : '-',
                    $total !== null ? $total : '-',
                ];

                $this->grandTotalQty   += $qty;
                $this->grandTotalHarga += $total ?? 0;
            }
        }

        $rows[] = ['', '', "TOTAL KESELURUHAN ({$this->prStart} s/d {$this->prEnd})", $this->grandTotalQty, '', round($this->grandTotalHarga, 2)];

        // +1 karena baris heading menempati row 1 di sheet
        $this->lastDataRow = count($rows) + 1;

        return $rows;
    }

    public function headings(): array
    {
        return ['No. PR', 'Nama Supplier', 'Nama Item', 'QTY', 'Harga', 'Total'];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $last  = $this->lastDataRow;

                $sheet->getStyle('A1:F1')->getFont()->setBold(true);
                $sheet->getStyle('A1:F1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9D9D9');

                $sheet->getStyle("C{$last}:F{$last}")->getFont()->setBold(true);
                $sheet->getStyle("C{$last}:F{$last}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('C6E0B4');

                $sheet->getStyle("A1:F{$last}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                $sheet->getStyle("E2:F{$last}")->getNumberFormat()->setFormatCode('#,##0');

                foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }
            },
        ];
    }
}
