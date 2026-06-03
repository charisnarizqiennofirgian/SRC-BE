<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dokumen;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DokumenController extends Controller
{
    public function index(Request $request)
    {
        $query = Dokumen::with('uploader:id,name')
            ->orderByDesc('created_at');

        if ($request->filled('kategori')) {
            $query->where('kategori', $request->kategori);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('nama_file', 'like', '%' . $request->search . '%')
                  ->orWhere('keterangan', 'like', '%' . $request->search . '%');
            });
        }

        $dokumen = $query->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data'    => $dokumen,
        ]);
    }

    public function upload(Request $request)
    {
        $request->validate([
            'file'       => 'required|file|max:51200|mimes:jpg,jpeg,png,pdf,xlsx,xls,dwg,dxf,doc,docx',
            'nama_file'  => 'required|string|max:255',
            'kategori'   => 'required|in:Gambar Produk,Daftar Bahan,Gambar Teknik,Lainnya',
            'keterangan' => 'nullable|string|max:500',
        ]);

        $file      = $request->file('file');
        $namaAsli  = $file->getClientOriginalName();
        $ekstensi  = $file->getClientOriginalExtension();
        $namaSimpa = Str::slug($request->nama_file) . '_' . time() . '.' . $ekstensi;
        $path      = $file->storeAs('', $namaSimpa, 'dokumen');

        $dokumen = Dokumen::create([
            'nama_file'    => $request->nama_file,
            'nama_asli'    => $namaAsli,
            'path_file'    => $path,
            'tipe_file'    => $file->getMimeType(),
            'ukuran_file'  => $file->getSize(),
            'kategori'     => $request->kategori,
            'keterangan'   => $request->keterangan,
            'diupload_oleh'=> Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'File berhasil diupload!',
            'data'    => $dokumen->load('uploader:id,name'),
        ], 201);
    }

    public function revisi(Request $request, $id)
    {
        $dokumen = Dokumen::findOrFail($id);

        $request->validate([
            'file'       => 'required|file|max:51200|mimes:jpg,jpeg,png,pdf,xlsx,xls,dwg,dxf,doc,docx',
            'keterangan' => 'nullable|string|max:500',
        ]);

        // Hapus file lama dari disk
        if (Storage::disk('dokumen')->exists($dokumen->path_file)) {
            Storage::disk('dokumen')->delete($dokumen->path_file);
        }

        // Simpan file baru
        $file      = $request->file('file');
        $ekstensi  = $file->getClientOriginalExtension();
        $namaSimpa = Str::slug($dokumen->nama_file) . '_' . time() . '.' . $ekstensi;
        $path      = $file->storeAs('', $namaSimpa, 'dokumen');

        // Update record — nama_file & kategori tetap, hanya file yang ganti
        $dokumen->update([
            'nama_asli'     => $file->getClientOriginalName(),
            'path_file'     => $path,
            'tipe_file'     => $file->getMimeType(),
            'ukuran_file'   => $file->getSize(),
            'keterangan'    => $request->filled('keterangan') ? $request->keterangan : $dokumen->keterangan,
            'diupload_oleh' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Dokumen berhasil direvisi!',
            'data'    => $dokumen->fresh()->load('uploader:id,name'),
        ]);
    }

    public function download($id)
    {
        $dokumen = Dokumen::findOrFail($id);

        if (!Storage::disk('dokumen')->exists($dokumen->path_file)) {
            return response()->json([
                'success' => false,
                'message' => 'File tidak ditemukan di server.'
            ], 404);
        }

        return Storage::disk('dokumen')->download(
            $dokumen->path_file,
            $dokumen->nama_asli
        );
    }

    public function hapus($id)
    {
        $dokumen = Dokumen::findOrFail($id);

        // Cek hak hapus — hanya uploader atau admin
        if ($dokumen->diupload_oleh !== Auth::id()) {
            $user = Auth::user();
            if (!$user->hasRole('super-admin') && !$user->hasRole('admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak punya akses untuk menghapus file ini.'
                ], 403);
            }
        }

        Storage::disk('dokumen')->delete($dokumen->path_file);
        $dokumen->delete();

        return response()->json([
            'success' => true,
            'message' => 'File berhasil dihapus.'
        ]);
    }
}
