<?php

namespace App\Exports;

use App\Models\Asset;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

class AssetExport implements FromArray, WithEvents, WithTitle, WithColumnWidths, WithStrictNullComparison
{
    const HEADER_ROW = 3;

    private array $columns = [
        ['label' => 'No', 'width' => 6],
        ['label' => 'Kode', 'width' => 16],
        ['label' => 'Nama', 'width' => 32],
        ['label' => 'Kategori', 'width' => 20],
        ['label' => 'Merk', 'width' => 16],
        ['label' => 'Tipe/Model', 'width' => 18],
        ['label' => 'No. Seri', 'width' => 18],
        ['label' => 'Lokasi', 'width' => 16],
        ['label' => 'Penanggung Jawab', 'width' => 18],
        ['label' => 'Tgl Pembelian', 'width' => 14],
        ['label' => 'Harga Beli', 'width' => 16, 'money' => true],
        ['label' => 'Umur Ekonomis (th)', 'width' => 12],
        ['label' => 'Nilai Buku', 'width' => 16, 'money' => true],
        ['label' => 'Supplier', 'width' => 20],
        ['label' => 'Kondisi', 'width' => 14],
        ['label' => 'Status', 'width' => 16],
        ['label' => 'Plat Nomor', 'width' => 13],
        ['label' => 'No. Rangka', 'width' => 20],
        ['label' => 'No. Mesin', 'width' => 20],
        ['label' => 'Pajak STNK', 'width' => 13],
        ['label' => 'KIR', 'width' => 13],
        ['label' => 'Servis Terakhir', 'width' => 14],
        ['label' => 'Servis Berikutnya', 'width' => 14],
        ['label' => 'Total Biaya Servis', 'width' => 16, 'money' => true],
        ['label' => 'Catatan', 'width' => 30],
    ];

    public function __construct(private $assets)
    {
    }

    public function title(): string
    {
        return 'Inventaris';
    }

    public function columnWidths(): array
    {
        $widths = [];
        foreach ($this->columns as $i => $col) {
            $widths[Coordinate::stringFromColumnIndex($i + 1)] = $col['width'];
        }

        return $widths;
    }

    public function array(): array
    {
        $categories = Asset::getCategories();
        $conditions = Asset::getConditions();
        $statuses = Asset::getStatuses();

        $rows = [
            ['DAFTAR INVENTARIS PABRIK'],
            ['Dicetak: ' . now()->format('d-m-Y H:i')],
            array_column($this->columns, 'label'),
        ];

        foreach ($this->assets->values() as $i => $a) {
            $rows[] = [
                $i + 1,
                $a->code,
                $a->name,
                $categories[$a->category] ?? $a->category,
                $a->brand,
                $a->model_type,
                $a->serial_number,
                $a->location,
                $a->pic,
                $a->purchase_date?->format('d-m-Y'),
                (float) $a->purchase_price,
                $a->useful_life_years,
                $a->getBookValue(),
                $a->supplier_name,
                $conditions[$a->condition] ?? $a->condition,
                $statuses[$a->status] ?? $a->status,
                $a->plate_number,
                $a->chassis_number,
                $a->engine_number,
                $a->tax_due_date?->format('d-m-Y'),
                $a->kir_due_date?->format('d-m-Y'),
                $a->latestServiceRecord?->service_date?->format('d-m-Y'),
                $a->latestServiceRecord?->next_service_date?->format('d-m-Y'),
                (float) ($a->total_service_cost ?? 0),
                $a->notes,
            ];
        }

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastCol = Coordinate::stringFromColumnIndex(count($this->columns));
                $header = self::HEADER_ROW;
                $lastRow = $header + $this->assets->count();

                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A2')->getFont()->setItalic(true);
                $sheet->getStyle("A{$header}:{$lastCol}{$header}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E5E7EB']],
                ]);
                $sheet->getStyle("A{$header}:{$lastCol}{$lastRow}")->getBorders()->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN);

                foreach ($this->columns as $i => $col) {
                    if (!empty($col['money'])) {
                        $letter = Coordinate::stringFromColumnIndex($i + 1);
                        $sheet->getStyle("{$letter}" . ($header + 1) . ":{$letter}{$lastRow}")
                            ->getNumberFormat()->setFormatCode('#,##0');
                    }
                }

                $sheet->freezePane('D' . ($header + 1));
                $sheet->setAutoFilter("A{$header}:{$lastCol}{$lastRow}");
                $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($header, $header);
                $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
                $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
                $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
            },
        ];
    }
}
