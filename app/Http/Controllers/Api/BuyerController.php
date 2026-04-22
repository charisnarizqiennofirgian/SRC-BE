<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class BuyerController extends Controller
{
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $search = $request->input('search');

            $query = Buyer::with('receivableAccount')->latest();

            if ($search) {
                $query->where('code', 'like', "%{$search}%")
                      ->orWhere('name', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
            }

            $buyers = $query->paginate($perPage);

            return response()->json($buyers, 200);

        } catch (\Exception $e) {
            \Log::error('Error saat mengambil data buyer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data buyer.',
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'code' => 'required|string|max:255|unique:buyers',
                'name' => 'required|string|max:255',
                'address' => 'nullable|string',
                'phone' => 'nullable|string|max:20',
                'receivable_account_id' => 'nullable|exists:chart_of_accounts,id',
            ]);

            $buyer = Buyer::create($validatedData);
            $buyer->load('receivableAccount');

            return response()->json([
                'success' => true,
                'message' => 'Buyer berhasil ditambahkan.',
                'data' => $buyer
            ], 201);

        } catch (ValidationException $e) {
            \Log::error('Validation error saat menambah buyer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Data tidak valid.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Error saat menambah buyer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menambahkan buyer.',
            ], 500);
        }
    }

    public function update(Request $request, Buyer $buyer)
    {
        try {
            $validatedData = $request->validate([
                'code' => 'required|string|max:255|unique:buyers,code,' . $buyer->id,
                'name' => 'required|string|max:255',
                'address' => 'nullable|string',
                'phone' => 'nullable|string|max:20',
                'receivable_account_id' => 'nullable|exists:chart_of_accounts,id',
            ]);

            $buyer->update($validatedData);
            $buyer->load('receivableAccount');

            return response()->json([
                'success' => true,
                'message' => 'Buyer berhasil diperbarui.',
                'data' => $buyer
            ], 200);

        } catch (ValidationException $e) {
            \Log::error('Validation error saat update buyer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Data tidak valid.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Error saat update buyer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui buyer.',
            ], 500);
        }
    }

    public function destroy(Buyer $buyer)
    {
        try {
            $buyer->delete();

            return response()->json([
                'success' => true,
                'message' => 'Buyer berhasil dihapus.'
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Error saat menghapus buyer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus buyer. Kemungkinan sudah terhubung dengan data lain.'
            ], 409);
        }
    }

    // =============================================
    // GET: Download Template Excel
    // =============================================
    public function downloadTemplate()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Template Buyer');

        // Header
        $headers = ['code', 'name', 'address', 'phone'];
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A5F']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];

        foreach ($headers as $i => $header) {
            $col = chr(65 + $i);
            $sheet->setCellValue("{$col}1", strtoupper($header));
            $sheet->getColumnDimension($col)->setWidth(25);
        }
        $sheet->getStyle('A1:D1')->applyFromArray($headerStyle);

        // Contoh data
        $examples = [
            ['BUY-001', 'PT Contoh Buyer', 'Jl. Contoh No. 1, Jakarta', '021-1234567'],
            ['BUY-002', 'CV Buyer Dua', 'Jl. Sample No. 2, Surabaya', '031-7654321'],
        ];

        foreach ($examples as $i => $row) {
            $rowNum = $i + 2;
            foreach ($row as $j => $val) {
                $sheet->setCellValue(chr(65 + $j) . $rowNum, $val);
            }
        }

        $sheet->getStyle('A2:D3')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('F0F9FF');

        $filename = 'Template_Buyer.xlsx';
        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'buyer_template_');
        $writer->save($tempFile);

        return response()->download($tempFile, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    // =============================================
    // POST: Import dari Excel
    // =============================================
    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:5120'],
        ]);

        try {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
            $spreadsheet = $reader->load($request->file('file')->getPathname());
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();

            // Skip header row
            array_shift($rows);

            $imported = 0;
            $skipped  = 0;
            $errors   = [];

            foreach ($rows as $i => $row) {
                $rowNum = $i + 2;

                // Skip baris kosong
                if (empty($row[0]) && empty($row[1])) continue;

                $code = trim($row[0] ?? '');
                $name = trim($row[1] ?? '');

                if (empty($code) || empty($name)) {
                    $errors[] = "Baris {$rowNum}: Kode dan nama wajib diisi.";
                    $skipped++;
                    continue;
                }

                // Skip kalau kode sudah ada
                if (Buyer::where('code', $code)->exists()) {
                    $errors[] = "Baris {$rowNum}: Kode '{$code}' sudah ada, dilewati.";
                    $skipped++;
                    continue;
                }

                Buyer::create([
                    'code'    => $code,
                    'name'    => $name,
                    'address' => trim($row[2] ?? ''),
                    'phone'   => trim($row[3] ?? ''),
                ]);

                $imported++;
            }

            return response()->json([
                'success'  => true,
                'message'  => "Import selesai: {$imported} buyer berhasil diimport, {$skipped} dilewati.",
                'imported' => $imported,
                'skipped'  => $skipped,
                'errors'   => $errors,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membaca file Excel: ' . $e->getMessage(),
            ], 500);
        }
    }

    // =============================================
    // GET: Export semua buyer ke Excel
    // =============================================
    public function export()
    {
        $buyers = Buyer::orderBy('code')->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data Buyer');

        // Header
        $headers = ['NO', 'KODE', 'NAMA', 'ALAMAT', 'TELEPON'];
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A5F']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];
        $colWidths = [8, 20, 35, 40, 20];

        foreach ($headers as $i => $header) {
            $col = chr(65 + $i);
            $sheet->setCellValue("{$col}1", $header);
            $sheet->getColumnDimension($col)->setWidth($colWidths[$i]);
        }
        $sheet->getStyle('A1:E1')->applyFromArray($headerStyle);
        $sheet->getRowDimension(1)->setRowHeight(22);

        // Data
        foreach ($buyers as $i => $buyer) {
            $row = $i + 2;
            $sheet->setCellValue("A{$row}", $i + 1);
            $sheet->setCellValue("B{$row}", $buyer->code);
            $sheet->setCellValue("C{$row}", $buyer->name);
            $sheet->setCellValue("D{$row}", $buyer->address ?? '-');
            $sheet->setCellValue("E{$row}", $buyer->phone ?? '-');

            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:E{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F0F9FF');
            }
        }

        // Border
        $lastRow = count($buyers) + 1;
        $sheet->getStyle("A1:E{$lastRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'E5E7EB']]],
        ]);

        $filename = 'Data_Buyer_' . now()->format('Ymd') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'buyer_export_');
        $writer->save($tempFile);

        return response()->download($tempFile, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
