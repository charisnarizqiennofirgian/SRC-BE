<?php

// INI PERBAIKANNYA: ganti 'Http-Controllers' menjadi 'Http\Controllers'
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // Ambil semua data role dari database
        $roles = Role::all();

        // Kembalikan response
        return response()->json([
            'message' => 'Data role berhasil diambil.',
            'roles' => $roles,
        ], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate(['name' => 'required|unique:roles|max:255']);

        $role = Role::create([
            'name' => $validated['name'],
            'guard_name' => 'web'
        ]);

        return response()->json([
            'message' => 'Role berhasil dibuat.',
            'role' => $role,
        ], 201);
    }
}
