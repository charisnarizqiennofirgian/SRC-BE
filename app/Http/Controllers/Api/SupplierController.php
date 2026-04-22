<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class SupplierController extends Controller
{
    /**
     * Menampilkan semua data supplier dengan pagination.
     */
    public function index(Request $request)
    {
        try {
            // Ambil parameter dari request
            $perPage = $request->input('per_page', 10);
            $search = $request->input('search');

            // Query builder dengan latest & load relationship
            $query = Supplier::with('payableAccount:id,code,name') // 👈 TAMBAHAN INI!
                             ->latest();

            // Jika ada parameter search
            if ($search) {
                $query->where('code', 'like', "%{$search}%")
                      ->orWhere('name', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
            }

            // Paginate hasil query
            $suppliers = $query->paginate($perPage);

            return response()->json($suppliers, 200);

        } catch (\Exception $e) {
            \Log::error('Error saat mengambil data supplier: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data supplier.',
            ], 500);
        }
    }

    /**
     * Menyimpan supplier baru.
     */
    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'code' => 'required|string|max:255|unique:suppliers',
                'name' => 'required|string|max:255',
                'address' => 'nullable|string',
                'phone' => 'nullable|string|max:20',
                'payable_account_id' => 'nullable|exists:chart_of_accounts,id', // 👈 TAMBAHAN INI!
            ]);

            $supplier = Supplier::create($validatedData);

            // Load relationship untuk response
            $supplier->load('payableAccount:id,code,name');

            return response()->json([
                'success' => true,
                'message' => 'Supplier berhasil ditambahkan.',
                'data' => $supplier
            ], 201);

        } catch (ValidationException $e) {
            \Log::error('Validation error saat menambah supplier: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Data tidak valid.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Error saat menambah supplier: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menambahkan supplier.',
            ], 500);
        }
    }

    /**
     * Mengupdate data supplier.
     */
    public function update(Request $request, Supplier $supplier)
    {
        try {
            $validatedData = $request->validate([
                'code' => 'required|string|max:255|unique:suppliers,code,' . $supplier->id,
                'name' => 'required|string|max:255',
                'address' => 'nullable|string',
                'phone' => 'nullable|string|max:20',
                'payable_account_id' => 'nullable|exists:chart_of_accounts,id', // 👈 TAMBAHAN INI!
            ]);

            $supplier->update($validatedData);

            // Load relationship untuk response
            $supplier->load('payableAccount:id,code,name');

            return response()->json([
                'success' => true,
                'message' => 'Supplier berhasil diperbarui.',
                'data' => $supplier
            ], 200);

        } catch (ValidationException $e) {
            \Log::error('Validation error saat update supplier: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Data tidak valid.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Error saat update supplier: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui supplier.',
            ], 500);
        }
    }

    /**
     * Menghapus supplier.
     */
    public function destroy(Supplier $supplier)
    {
        try {
            $supplier->delete();

            return response()->json([
                'success' => true,
                'message' => 'Supplier berhasil dihapus.'
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Error saat menghapus supplier: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus supplier. Kemungkinan sudah terhubung dengan data lain.'
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
        $sheet->setTitle('Template Supplier');

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

        $examples = [
            ['SUP-001', 'PT Contoh Supplier', 'Jl. Contoh No. 1, Jakarta', '021-1234567'],
            ['SUP-002', 'CV Supplier Dua', 'Jl. Sample No. 2, Surabaya', '031-7654321'],
        ];

        foreach ($examples as $i => $row) {
            $rowNum = $i + 2;
            foreach ($row as $j => $val) {
                $sheet->setCellValue(chr(65 + $j) . $rowNum, $val);
            }
        }

        $sheet->getStyle('A2:D3')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('F0FFF4');

        $filename = 'Template_Supplier.xlsx';
        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'supplier_template_');
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

            array_shift($rows);

            $imported = 0;
            $skipped  = 0;
            $errors   = [];

            foreach ($rows as $i => $row) {
                $rowNum = $i + 2;

                if (empty($row[0]) && empty($row[1])) continue;

                $code = trim($row[0] ?? '');
                $name = trim($row[1] ?? '');

                if (empty($code) || empty($name)) {
                    $errors[] = "Baris {$rowNum}: Kode dan nama wajib diisi.";
                    $skipped++;
                    continue;
                }

                if (Supplier::where('code', $code)->exists()) {
                    $errors[] = "Baris {$rowNum}: Kode '{$code}' sudah ada, dilewati.";
                    $skipped++;
                    continue;
                }

                Supplier::create([
                    'code'    => $code,
                    'name'    => $name,
                    'address' => trim($row[2] ?? ''),
                    'phone'   => trim($row[3] ?? ''),
                ]);

                $imported++;
            }

            return response()->json([
                'success'  => true,
                'message'  => "Import selesai: {$imported} supplier berhasil diimport, {$skipped} dilewati.",
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
    // GET: Export semua supplier ke Excel
    // =============================================
    public function export()
    {
        $suppliers = Supplier::orderBy('code')->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data Supplier');

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

        foreach ($suppliers as $i => $supplier) {
            $row = $i + 2;
            $sheet->setCellValue("A{$row}", $i + 1);
            $sheet->setCellValue("B{$row}", $supplier->code);
            $sheet->setCellValue("C{$row}", $supplier->name);
            $sheet->setCellValue("D{$row}", $supplier->address ?? '-');
            $sheet->setCellValue("E{$row}", $supplier->phone ?? '-');

            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:E{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F0FFF4');
            }
        }

        $lastRow = count($suppliers) + 1;
        $sheet->getStyle("A1:E{$lastRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'E5E7EB']]],
        ]);

        $filename = 'Data_Supplier_' . now()->format('Ymd') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'supplier_export_');
        $writer->save($tempFile);

        return response()->download($tempFile, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
