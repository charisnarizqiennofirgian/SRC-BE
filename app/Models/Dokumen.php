<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Dokumen extends Model
{
    protected $table = 'dokumen';

    protected $fillable = [
        'nama_file',
        'nama_asli',
        'path_file',
        'tipe_file',
        'ukuran_file',
        'kategori',
        'keterangan',
        'diupload_oleh',
    ];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'diupload_oleh');
    }

    public function getUkuranFormatAttribute(): string
    {
        $bytes = $this->ukuran_file;
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024)    return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }
}
