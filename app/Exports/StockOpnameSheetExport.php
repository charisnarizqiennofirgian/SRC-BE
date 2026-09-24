<?php

namespace App\Exports;

use App\Models\StockOpname;
use App\Models\StockOpnameDetail;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class StockOpnameSheetExport implements FromArray, WithEvents, WithTitle, WithColumnWidths
{
    const HEADER_ROW = 5;

    private array $columns;

    public function __construct(private StockOpname $opname, private $details)
    {
        $types = $details->pluck('row_type')->unique();
        $hasKayu = $types->contains(StockOpnameDetail::TYPE_KAYU);
        $hasKomponen = $types->contains(StockOpnameDetail::TYPE_KOMPONEN);
        $hasPcsInput = $types->contains(fn ($t) => $t !== StockOpnameDetail::TYPE_KOMPONEN);

        $columns = [
            ['key' => 'id', 'label' => 'ID Baris', 'width' => 9],
            ['key' => 'no', 'label' => 'No', 'width' => 6],
            ['key' => 'code', 'label' => 'Kode', 'width' => 18],
            ['key' => 'name', 'label' => 'Nama Barang', 'width' => 45],
            ['key' => 'category', 'label' => 'Kategori', 'width' => 16],
        ];
        if ($hasKayu) {
            $columns[] = ['key' => 'grade', 'label' => 'Grade', 'width' => 8];
        }
        $columns[] = ['key' => 'unit', 'label' => 'Satuan', 'width' => 9];
        $columns[] = ['key' => 'system_pcs', 'label' => 'Stok Sistem', 'width' => 13];
        if ($hasKayu) {
            $columns[] = ['key' => 'system_m3', 'label' => 'Stok Sistem (m3)', 'width' => 15];
        }
        if ($hasKomponen) {
            $columns[] = ['key' => 'system_natural', 'label' => 'Sistem Natural', 'width' => 14];
            $columns[] = ['key' => 'system_warna', 'label' => 'Sistem Warna', 'width' => 14];
        }
        if ($hasPcsInput) {
            $columns[] = ['key' => 'real_pcs', 'label' => 'REAL', 'width' => 13, 'input' => true];
        }
        if ($hasKomponen) {
            $columns[] = ['key' => 'real_natural', 'label' => 'REAL Natural', 'width' => 14, 'input' => true];
            $columns[] = ['key' => 'real_warna', 'label' => 'REAL Warna', 'width' => 14, 'input' => true];
        }
        $columns[] = ['key' => 'notes', 'label' => 'Catatan', 'width' => 28, 'input' => true];

        $this->columns = $columns;
    }

    public function title(): string
    {
        return 'Stok Opname';
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
        $rows = [
            ['STOK OPNAME ' . $this->opname->opname_number],
            ['Gudang: ' . ($this->opname->warehouse?->name ?? '-')],
            ['Tanggal: ' . $this->opname->opname_date?->format('d-m-Y') . '   |   Isi kolom kuning dengan hasil hitung fisik. Kosongkan kalau tidak dihitung, isi 0 kalau barang habis. Jangan ubah kolom ID Baris.'],
            [''],
            array_column($this->columns, 'label'),
        ];

        foreach ($this->details->values() as $i => $d) {
            $isKomponen = $d->row_type === StockOpnameDetail::TYPE_KOMPONEN;
            $hasBreakdown = abs(($d->system_qty_natural + $d->system_qty_warna) - $d->system_qty_pcs) < 0.0001;
            $values = [
                'id'             => $d->id,
                'no'             => $i + 1,
                'code'           => $d->item?->code,
                'name'           => $d->item?->name,
                'category'       => $d->item?->category?->name,
                'grade'          => $d->grade,
                'unit'           => $d->item?->unit?->name ?? 'pcs',
                'system_pcs'     => $d->system_qty_pcs,
                'system_m3'      => $d->row_type === StockOpnameDetail::TYPE_KAYU ? round($d->system_qty_m3, 6) : null,
                'system_natural' => $isKomponen ? ($hasBreakdown ? $d->system_qty_natural : '-') : null,
                'system_warna'   => $isKomponen ? ($hasBreakdown ? $d->system_qty_warna : '-') : null,
                'real_pcs'       => $isKomponen ? null : $d->real_qty_pcs,
                'real_natural'   => $isKomponen && $d->real_qty_pcs !== null ? $d->real_qty_natural : null,
                'real_warna'     => $isKomponen && $d->real_qty_pcs !== null ? $d->real_qty_warna : null,
                'notes'          => $d->notes,
            ];
            $rows[] = array_map(fn ($col) => $values[$col['key']], $this->columns);
        }

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastCol = Coordinate::stringFromColumnIndex(count($this->columns));
                $lastRow = self::HEADER_ROW + $this->details->count();
                $header = self::HEADER_ROW;

                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A2:A3')->getFont()->setItalic(true);

                $sheet->getStyle("A{$header}:{$lastCol}{$header}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E5E7EB']],
                ]);
                $sheet->getStyle("A{$header}:{$lastCol}{$lastRow}")->getBorders()->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN);

                foreach ($this->columns as $i => $col) {
                    if (empty($col['input'])) {
                        continue;
                    }
                    $letter = Coordinate::stringFromColumnIndex($i + 1);
                    $sheet->getStyle("{$letter}{$header}:{$letter}{$lastRow}")->getFill()
                        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEF9C3');
                }

                $sheet->getStyle("A{$header}:A{$lastRow}")->getFont()->getColor()->setRGB('9CA3AF');
                $sheet->freezePane('A' . ($header + 1));
                $sheet->setAutoFilter("A{$header}:{$lastCol}{$lastRow}");
                $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($header, $header);
                $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
                $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
            },
        ];
    }
}
