<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    /**
     * Display a listing of permissions grouped by module
     */
    public function index()
    {
        $permissions = Permission::all();

        return response()->json([
            'success' => true,
            'message' => 'Data permissions berhasil diambil.',
            'data' => $permissions,
        ]);
    }

    /**
     * Store a newly created permission
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|unique:permissions|max:255',
        ]);

        $permission = Permission::create([
            'name' => $validated['name'],
            'guard_name' => 'web',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Permission berhasil dibuat.',
            'data' => $permission,
        ], 201);
    }

    /**
     * Display the specified permission
     */
    public function show($id)
    {
        $permission = Permission::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $permission,
        ]);
    }

    /**
     * Update the specified permission
     */
    public function update(Request $request, $id)
    {
        $permission = Permission::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|unique:permissions,name,' . $id . '|max:255',
        ]);

        $permission->update(['name' => $validated['name']]);

        return response()->json([
            'success' => true,
            'message' => 'Permission berhasil diupdate.',
            'data' => $permission,
        ]);
    }

    /**
     * Remove the specified permission
     */
    public function destroy($id)
    {
        $permission = Permission::findOrFail($id);

        // Cek apakah permission masih dipakai di role
        if ($permission->roles()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Permission tidak bisa dihapus karena masih digunakan oleh role.',
            ], 422);
        }

        $permission->delete();

        return response()->json([
            'success' => true,
            'message' => 'Permission berhasil dihapus.',
        ]);
    }
}
