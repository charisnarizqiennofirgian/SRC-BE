<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class PurchaseOrderRekapSupplierExport implements FromArray, WithEvents, WithTitle, WithColumnWidths
{
    const HEADINGS = [
        'No', 'No. PO', 'Jenis', 'Tgl. PO', 'Supplier', 'Kode Item', 'Nama Item', 'Satuan',
        'Jumlah Pesanan', 'Harga', 'Mata Uang', 'Tanggal Kirim', 'Sudah Diterima', 'Sisa Belum Dikirim',
    ];

    private int $headerRow;

    public function __construct(private Collection $rows, private array $summary)
    {
        $this->headerRow = count($summary) + 3;
    }

    public function title(): string
    {
        return 'Rekap PO';
    }

    public function columnWidths(): array
    {
        return [
            'A' => 6, 'B' => 26, 'C' => 12, 'D' => 12, 'E' => 30, 'F' => 16, 'G' => 45,
            'H' => 9, 'I' => 15, 'J' => 15, 'K' => 10, 'L' => 14, 'M' => 15, 'N' => 18,
        ];
    }

    public function array(): array
    {
        $out = [['REKAP PURCHASE ORDER PER SUPPLIER']];
        foreach ($this->summary as $label => $value) {
            $out[] = ["{$label}: {$value}"];
        }
        $out[] = [''];
        $out[] = self::HEADINGS;

        foreach ($this->rows->values() as $i => $r) {
            $out[] = [
                $i + 1,
                $r['po_number'],
                $r['type'] === 'karton' ? 'Karton Box' : 'Operasional',
                $this->date($r['order_date']),
                $r['supplier_name'],
                $r['item_code'],
                $r['item_name'],
                $r['unit_name'],
                $r['qty_ordered'],
                $r['price'],
                $r['currency'],
                $this->date($r['delivery_date']) ?: '-',
                $r['qty_received'],
                $r['qty_remaining'],
            ];
        }

        return $out;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $header = $this->headerRow;
                $last = $header + max(1, $this->rows->count());

                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle("A{$header}:N{$header}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E5E7EB']],
                ]);
                $sheet->getStyle("A{$header}:N{$last}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                $sheet->getStyle("I" . ($header + 1) . ":J{$last}")->getNumberFormat()->setFormatCode('#,##0.##');
                $sheet->getStyle("M" . ($header + 1) . ":N{$last}")->getNumberFormat()->setFormatCode('#,##0.##');
                $sheet->getStyle("N{$header}:N{$last}")->getFont()->setBold(true);

                foreach ($this->rows->values() as $i => $r) {
                    if ($r['qty_remaining'] > 0) {
                        $row = $header + 1 + $i;
                        $sheet->getStyle("N{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEF3C7');
                    }
                }

                $sheet->freezePane('A' . ($header + 1));
                $sheet->setAutoFilter("A{$header}:N{$last}");
            },
        ];
    }

    private function date($value): string
    {
        return $value ? date('d-m-Y', strtotime($value)) : '';
    }
}
