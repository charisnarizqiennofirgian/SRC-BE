<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryOrder;
use App\Models\DeliveryOrderDetail;
use App\Models\Item;
use App\Models\StockMovement;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Models\InventoryLog;
use App\Models\Warehouse;
use App\Models\Inventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DeliveryOrderController extends Controller
{
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $search = $request->get('search', '');

            $query = DeliveryOrder::with(['salesOrder', 'buyer', 'user'])
                ->orderBy('created_at', 'desc');

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('do_number', 'LIKE', "%{$search}%")
                        ->orWhereHas('salesOrder', function ($sq) use ($search) {
                            $sq->where('so_number', 'LIKE', "%{$search}%");
                        });
                });
            }

            $deliveryOrders = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Data berhasil dimuat',
                'data' => $deliveryOrders
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    public function store(Request $request)
    {
        DB::beginTransaction();
        try {
            $lastDO = DeliveryOrder::withTrashed()
                ->whereYear('created_at', date('Y'))
                ->orderBy('id', 'desc')
                ->first();
            $nextNumber = $lastDO ? (intval(substr($lastDO->do_number, -4)) + 1) : 1;
            $doNumber = 'DO/' . date('Y') . '/' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

            $validated = $request->validate([
                'barcode_image' => 'nullable|image|mimes:jpeg,png|max:1024',
                'rex_certificate_file' => 'nullable|mimes:pdf|max:2048',
                'forwarder_name' => 'nullable|string',
                'peb_number' => 'nullable|string|max:100',
                'container_type' => 'nullable|string|max:50',
                'shipment_mode' => 'nullable|in:SEA,AIR',
            ]);

            $barcodeImagePath = null;
            if ($request->hasFile('barcode_image')) {
                $barcodeImagePath = $request->file('barcode_image')->store('barcodes', 'public');
            }

            $rexCertificateFile = null;
            if ($request->hasFile('rex_certificate_file')) {
                $file = $request->file('rex_certificate_file');
                $filename = 'rex_' . time() . '_' . $file->getClientOriginalName();
                $rexCertificateFile = $file->storeAs('rex_certificates', $filename, 'public');
            }

            $deliveryOrder = DeliveryOrder::create([
                'do_number' => $doNumber,
                'sales_order_id' => $request->sales_order_id,
                'buyer_id' => $request->buyer_id,
                'user_id' => auth()->id(),
                'delivery_date' => $request->delivery_date,
                'driver_name' => $request->driver_name,
                'vehicle_number' => $request->vehicle_number,
                'notes' => $request->notes,
                'status' => 'Shipped',
                'shipment_mode' => $request->shipment_mode ?? 'SEA',
                'incoterm' => $request->incoterm,
                'bl_date' => $request->bl_date,
                'vessel_name' => $request->vessel_name,
                'mother_vessel' => $request->mother_vessel,
                'eu_factory_number' => $request->eu_factory_number,
                'port_of_loading' => $request->port_of_loading,
                'port_of_discharge' => $request->port_of_discharge,
                'final_destination' => $request->final_destination,
                'bl_number' => $request->bl_number,
                'rex_info' => $request->rex_info,
                'freight_terms' => $request->freight_terms,
                'container_number' => $request->container_number,
                'seal_number' => $request->seal_number,
                'rex_date' => $request->rex_date,
                'rex_certificate_file' => $rexCertificateFile,
                'goods_description' => $request->goods_description,
                'consignee_info' => $request->consignee_info,
                'applicant_info' => $request->applicant_info,
                'notify_info' => $request->notify_info,
                'barcode_image' => $barcodeImagePath,
                'forwarder_name' => $request->forwarder_name,
                'peb_number' => $request->peb_number,
                'container_type' => $request->container_type,
            ]);

            $details = $request->details;
            if (is_string($details)) {
                $details = json_decode($details, true);
            }

            $packingWarehouseId = 11; // Gudang Packing

            foreach ($details as $detail) {
                $item = Item::with('unit')->find($detail['item_id']);
                if (!$item) {
                    throw new \Exception("Item ID {$detail['item_id']} tidak ditemukan");
                }

                // Cek stok dari tabel inventories di Gudang Packing
                $inventory = Inventory::where('item_id', $detail['item_id'])
                    ->where('warehouse_id', $packingWarehouseId)
                    ->first();
                $currentStock = $inventory ? (float) $inventory->qty : 0;

                if ($currentStock < $detail['quantity_shipped']) {
                    throw new \Exception("Stock {$item->name} di Gudang Packing tidak cukup. Tersedia: {$currentStock}, Diminta: {$detail['quantity_shipped']}");
                }

                DeliveryOrderDetail::create([
                    'delivery_order_id' => $deliveryOrder->id,
                    'sales_order_detail_id' => $detail['sales_order_detail_id'],
                    'item_id' => $detail['item_id'],
                    'item_name' => $item->name,
                    'item_unit' => $item->unit->name ?? 'Pcs',
                    'quantity_shipped' => $detail['quantity_shipped'],
                    'quantity_boxes' => $detail['quantity_boxes'] ?? null,
                    'quantity_crates' => $detail['quantity_crates'] ?? null,
                ]);

                // Kurangi stok di tabel items
                $item->decrement('stock', $detail['quantity_shipped']);

                // Kurangi stok di tabel inventories (Gudang Packing)
                if ($inventory) {
                    $inventory->decrement('qty', $detail['quantity_shipped']);
                }

                StockMovement::create([
                    'item_id' => $detail['item_id'],
                    'type' => 'OUT',
                    'quantity' => $detail['quantity_shipped'],
                    'notes' => "Pengiriman barang (DO: {$doNumber})",
                ]);

                InventoryLog::create([
                    'date' => $request->delivery_date ?? now()->toDateString(),
                    'time' => now()->toTimeString(),
                    'item_id' => $detail['item_id'],
                    'warehouse_id' => $packingWarehouseId,
                    'qty' => $detail['quantity_shipped'],
                    'direction' => 'OUT',
                    'transaction_type' => 'SALE',
                    'reference_type' => 'DeliveryOrder',
                    'reference_id' => $deliveryOrder->id,
                    'reference_number' => $doNumber,
                    'notes' => "Pengiriman ke " . ($request->buyer_name ?? 'Buyer'),
                    'user_id' => auth()->id(),
                ]);

                $soDetail = SalesOrderDetail::find($detail['sales_order_detail_id']);
                if ($soDetail) {
                    $soDetail->increment('quantity_shipped', $detail['quantity_shipped']);
                }
            }

            $salesOrder = SalesOrder::with('details')->find($request->sales_order_id);
            if ($salesOrder) {
                $allDelivered = true;
                foreach ($salesOrder->details as $detail) {
                    if ($detail->quantity > $detail->quantity_shipped) {
                        $allDelivered = false;
                        break;
                    }
                }
                $salesOrder->status = $allDelivered ? 'Delivered' : 'Partial Delivered';
                $salesOrder->save();
            }

            $deliveryOrder = DeliveryOrder::with(['salesOrder.buyer', 'buyer', 'user', 'details.item'])
                ->find($deliveryOrder->id);
            $deliveryOrder->barcode_image = $deliveryOrder->barcode_image
                ? asset('storage/' . $deliveryOrder->barcode_image)
                : null;

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Pengiriman berhasil dibuat dan stok telah dipotong',
                'data' => $deliveryOrder
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan pengiriman: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $deliveryOrder = DeliveryOrder::with([
                'salesOrder.buyer',
                'buyer',
                'user',
                'details.item',
                'details.salesOrderDetail'
            ])->findOrFail($id);

            $fields = ['consignee_info', 'applicant_info', 'notify_info'];
            foreach ($fields as $f) {
                $val = $deliveryOrder->$f;
                $deliveryOrder->$f = $val && is_string($val) ? json_decode($val) : (object)[];
            }

            $deliveryOrder->barcode_image = $deliveryOrder->barcode_image
                ? asset('storage/' . $deliveryOrder->barcode_image)
                : null;

            return response()->json([
                'success' => true,
                'data' => $deliveryOrder
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak ditemukan'
            ], 404);
        }
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $do = DeliveryOrder::findOrFail($id);

            $validated = $request->validate([
                'barcode_image' => 'nullable|image|mimes:jpeg,png|max:1024',
                'rex_certificate_file' => 'nullable|mimes:pdf|max:2048',
                'forwarder_name' => 'nullable|string',
                'peb_number' => 'nullable|string|max:100',
                'container_type' => 'nullable|string|max:50',
                'shipment_mode' => 'nullable|in:SEA,AIR',
            ]);

            if ($request->hasFile('barcode_image')) {
                if ($do->barcode_image) {
                    Storage::disk('public')->delete($do->barcode_image);
                }
                $barcodeImagePath = $request->file('barcode_image')->store('barcodes', 'public');
                $validated['barcode_image'] = $barcodeImagePath;
            }

            if ($request->hasFile('rex_certificate_file')) {
                if ($do->rex_certificate_file) {
                    Storage::disk('public')->delete($do->rex_certificate_file);
                }
                $file = $request->file('rex_certificate_file');
                $filename = 'rex_' . time() . '_' . $file->getClientOriginalName();
                $rexCertificateFile = $file->storeAs('rex_certificates', $filename, 'public');
                $validated['rex_certificate_file'] = $rexCertificateFile;
            }

            $do->update([
                'delivery_date' => $request->delivery_date ?? $do->delivery_date,
                'driver_name' => $request->driver_name ?? $do->driver_name,
                'vehicle_number' => $request->vehicle_number ?? $do->vehicle_number,
                'notes' => $request->notes ?? $do->notes,
                'incoterm' => $request->incoterm ?? $do->incoterm,
                'bl_date' => $request->bl_date ?? $do->bl_date,
                'vessel_name' => $request->vessel_name ?? $do->vessel_name,
                'mother_vessel' => $request->mother_vessel ?? $do->mother_vessel,
                'eu_factory_number' => $request->eu_factory_number ?? $do->eu_factory_number,
                'port_of_loading' => $request->port_of_loading ?? $do->port_of_loading,
                'port_of_discharge' => $request->port_of_discharge ?? $do->port_of_discharge,
                'final_destination' => $request->final_destination ?? $do->final_destination,
                'bl_number' => $request->bl_number ?? $do->bl_number,
                'rex_info' => $request->rex_info ?? $do->rex_info,
                'freight_terms' => $request->freight_terms ?? $do->freight_terms,
                'container_number' => $request->container_number ?? $do->container_number,
                'seal_number' => $request->seal_number ?? $do->seal_number,
                'rex_date' => $request->rex_date ?? $do->rex_date,
                'goods_description' => $request->goods_description ?? $do->goods_description,
                'consignee_info' => $request->consignee_info ?? $do->consignee_info,
                'applicant_info' => $request->applicant_info ?? $do->applicant_info,
                'notify_info' => $request->notify_info ?? $do->notify_info,
                'shipment_mode' => $request->shipment_mode ?? $do->shipment_mode,
                'forwarder_name' => $request->forwarder_name ?? $do->forwarder_name,
                'peb_number' => $request->peb_number ?? $do->peb_number,
                'container_type' => $request->container_type ?? $do->container_type,
            ]);

            if (isset($validated['barcode_image'])) {
                $do->barcode_image = $validated['barcode_image'];
                $do->save();
            }
            if (isset($validated['rex_certificate_file'])) {
                $do->rex_certificate_file = $validated['rex_certificate_file'];
                $do->save();
            }

            $do = DeliveryOrder::find($do->id);
            $do->barcode_image = $do->barcode_image
                ? asset('storage/' . $do->barcode_image)
                : null;

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Delivery Order berhasil diupdate',
                'data' => $do
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal update delivery order: ' . $e->getMessage()
            ], 500);
        }
    }

    public function downloadRexCertificate($id)
    {
        $deliveryOrder = DeliveryOrder::findOrFail($id);
        if (!$deliveryOrder->rex_certificate_file) {
            return response()->json(['success' => false, 'message' => 'REX certificate not found'], 404);
        }
        $path = storage_path('app/public/' . $deliveryOrder->rex_certificate_file);
        if (!file_exists($path)) {
            return response()->json(['success' => false, 'message' => 'File not found'], 404);
        }
        return response()->file($path, [
            'Content-Type' => 'application/pdf'
        ]);
    }

    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $deliveryOrder = DeliveryOrder::with('details')->findOrFail($id);

            if ($deliveryOrder->barcode_image) {
                Storage::disk('public')->delete($deliveryOrder->barcode_image);
            }
            if ($deliveryOrder->rex_certificate_file) {
                Storage::disk('public')->delete($deliveryOrder->rex_certificate_file);
            }

            $packingWarehouseId = 11; // Gudang Packing

            foreach ($deliveryOrder->details as $detail) {
                $item = Item::find($detail->item_id);
                if ($item) {
                    $item->increment('stock', $detail->quantity_shipped);
                }

                // Kembalikan stok di tabel inventories (Gudang Packing)
                $inventory = Inventory::where('item_id', $detail->item_id)
                    ->where('warehouse_id', $packingWarehouseId)
                    ->first();
                if ($inventory) {
                    $inventory->increment('qty', $detail->quantity_shipped);
                }

                StockMovement::create([
                    'item_id' => $detail->item_id,
                    'type' => 'IN',
                    'quantity' => $detail->quantity_shipped,
                    'notes' => "Pembatalan pengiriman (DO: {$deliveryOrder->do_number})",
                ]);

                InventoryLog::create([
                    'date' => now()->toDateString(),
                    'time' => now()->toTimeString(),
                    'item_id' => $detail->item_id,
                    'warehouse_id' => $packingWarehouseId,
                    'qty' => $detail->quantity_shipped,
                    'direction' => 'IN',
                    'transaction_type' => 'ADJUSTMENT',
                    'reference_type' => 'DeliveryOrder',
                    'reference_id' => $deliveryOrder->id,
                    'reference_number' => $deliveryOrder->do_number,
                    'notes' => "Pembatalan pengiriman (DO: {$deliveryOrder->do_number})",
                    'user_id' => auth()->id(),
                ]);

                $soDetail = SalesOrderDetail::find($detail->sales_order_detail_id);
                if ($soDetail) {
                    $soDetail->decrement('quantity_shipped', $detail->quantity_shipped);
                }
            }

            $deliveryOrder->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Pengiriman berhasil dibatalkan, stok dikembalikan'
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Gagal membatalkan pengiriman: ' . $e->getMessage()
            ], 500);
        }
    }
}
