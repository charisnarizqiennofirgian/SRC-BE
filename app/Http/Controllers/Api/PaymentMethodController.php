<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Models\ChartOfAccount;
use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{
    public function index()
    {
        $paymentMethods = PaymentMethod::with('account')
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $paymentMethods
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'type' => 'required|in:BANK,CASH',
            'account_id' => 'required|exists:chart_of_accounts,id',
        ]);

        $paymentMethod = PaymentMethod::create([
            'name' => $request->name,
            'type' => $request->type,
            'account_id' => $request->account_id,
            'is_active' => true,
        ]);

        $paymentMethod->load('account');

        return response()->json([
            'success' => true,
            'message' => 'Metode pembayaran berhasil ditambahkan',
            'data' => $paymentMethod
        ], 201);
    }

    public function show($id)
    {
        $paymentMethod = PaymentMethod::with('account')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $paymentMethod
        ]);
    }

    public function update(Request $request, $id)
    {
        $paymentMethod = PaymentMethod::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:100',
            'type' => 'required|in:BANK,CASH',
            'account_id' => 'required|exists:chart_of_accounts,id',
            'is_active' => 'nullable|boolean',
        ]);

        $paymentMethod->update([
            'name' => $request->name,
            'type' => $request->type,
            'account_id' => $request->account_id,
            'is_active' => $request->is_active ?? true,
        ]);

        $paymentMethod->load('account');

        return response()->json([
            'success' => true,
            'message' => 'Metode pembayaran berhasil diupdate',
            'data' => $paymentMethod
        ]);
    }

    public function destroy($id)
    {
        $paymentMethod = PaymentMethod::findOrFail($id);

        // TODO: Cek apakah sudah dipakai di transaksi nanti

        $paymentMethod->delete();

        return response()->json([
            'success' => true,
            'message' => 'Metode pembayaran berhasil dihapus'
        ]);
    }

    public function getTypes()
    {
        return response()->json([
            'success' => true,
            'data' => PaymentMethod::getTypes()
        ]);
    }

    public function getActive()
    {
        $paymentMethods = PaymentMethod::with('account')
            ->active()
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $paymentMethods
        ]);
    }
}
