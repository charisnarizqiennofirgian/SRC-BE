<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    // TAMBAHKAN 'guard_name' DI SINI
    protected $fillable = ['name', 'guard_name'];

    /**
     * Relasi many-to-many dengan User.
     */
    public function users()
    {
        return $this->belongsToMany(User::class);
    }
}