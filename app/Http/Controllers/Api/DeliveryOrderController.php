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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class DeliveryOrderController extends Controller
{
    /**
     * Tempel daftar SO unik yang terlibat di DO ini (via delivery_order_details ->
     * sales_order_detail -> sales_order) sebagai attribute 'sales_orders' — dipakai frontend
     * untuk menampilkan badge multi-SO. sales_order_id/salesOrder (tunggal) tetap ada sebagai
     * SO "utama" untuk kompatibilitas tampilan lama.
     */
    private function attachSalesOrdersInfo(DeliveryOrder $deliveryOrder): DeliveryOrder
    {
        $deliveryOrder->loadMissing('details.salesOrderDetail.salesOrder:id,so_number,buyer_id,currency');
        $deliveryOrder->setAttribute(
            'sales_orders',
            $deliveryOrder->details
                ->pluck('salesOrderDetail.salesOrder')
                ->filter()
                ->unique('id')
                ->values()
        );
        return $deliveryOrder;
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $search = $request->get('search', '');

            $query = DeliveryOrder::with([
                'salesOrder',
                'buyer',
                'user',
                'details.salesOrderDetail.salesOrder:id,so_number,buyer_id,currency',
            ])->orderBy('created_at', 'desc');

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('do_number', 'LIKE', "%{$search}%")
                        ->orWhereHas('salesOrder', function ($sq) use ($search) {
                            $sq->where('so_number', 'LIKE', "%{$search}%");
                        })
                        ->orWhereHas('details.salesOrderDetail.salesOrder', function ($sq) use ($search) {
                            $sq->where('so_number', 'LIKE', "%{$search}%");
                        });
                });
            }

            $deliveryOrders = $query->paginate($perPage);
            $deliveryOrders->getCollection()->transform(fn ($do) => $this->attachSalesOrdersInfo($do));

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

    /**
     * Validasi stok per TOTAL item_id (bukan per baris) — 1 item bisa muncul di beberapa baris
     * kalau mode Air Freight dipecah per crate/box (lihat FormPengiriman.vue), jadi harus
     * dijumlah dulu sebelum dibandingkan ke stok tersedia.
     */
    private function validateStockForDetails(array $details, int $packingWarehouseId): void
    {
        $shippedByItem = [];
        foreach ($details as $detail) {
            $shippedByItem[$detail['item_id']] = ($shippedByItem[$detail['item_id']] ?? 0) + (float) ($detail['quantity_shipped'] ?? 0);
        }

        foreach ($shippedByItem as $itemId => $totalShipped) {
            $item = Item::find($itemId);
            if (!$item) {
                throw new \Exception("Item ID {$itemId} tidak ditemukan");
            }

            $currentStock = (float) Inventory::where('item_id', $itemId)
                ->where('warehouse_id', $packingWarehouseId)
                ->sum('qty_pcs');

            if ($currentStock <= 0) {
                $currentStock = (float) $item->stock;
            }

            if ($currentStock < $totalShipped) {
                throw new \Exception("Stock {$item->name} di Gudang Packing tidak cukup. Tersedia: {$currentStock}, Diminta: {$totalShipped}");
            }
        }
    }

    /**
     * Hitung nw/gw/m3/wood per baris (fallback ke master item kalau baris tidak isi sendiri)
     * beserta total-nya — dipakai bareng oleh store() dan update().
     */
    private function calculateDetailTotals(array $detail, Item $item): array
    {
        $nwPerBox    = $detail['nw_per_box'] ?? $item->nw_per_box ?? null;
        $gwPerBox    = $detail['gw_per_box'] ?? $item->gw_per_box ?? null;
        $m3PerCarton = $detail['m3_per_carton'] ?? $item->m3_per_carton ?? null;
        $woodPerPcs  = $detail['wood_consumed_per_pcs'] ?? $item->wood_consumed_per_pcs ?? null;
        $quantityBoxes   = $detail['quantity_boxes'] ?? null;
        $quantityShipped = $detail['quantity_shipped'] ?? 0;

        return [
            'nw_per_box'             => $nwPerBox,
            'gw_per_box'             => $gwPerBox,
            'm3_per_carton'          => $m3PerCarton,
            'wood_consumed_per_pcs'  => $woodPerPcs,
            'quantity_boxes'         => $quantityBoxes,
            'quantity_shipped'       => $quantityShipped,
            'quantity_crates'        => $detail['quantity_crates'] ?? null,
            'total_nw'               => ($nwPerBox && $quantityBoxes) ? $nwPerBox * $quantityBoxes : null,
            'total_gw'               => ($gwPerBox && $quantityBoxes) ? $gwPerBox * $quantityBoxes : null,
            'total_m3'               => ($m3PerCarton && $quantityBoxes) ? $m3PerCarton * $quantityBoxes : null,
            'total_wood_consumed'    => ($woodPerPcs && $quantityShipped) ? $woodPerPcs * $quantityShipped : null,
        ];
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
            $doNumber = 'DO-' . date('Y') . '-' . date('m') . '-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

            $validated = $request->validate([
                'barcode_image' => 'nullable|image|mimes:jpeg,png|max:1024',
                'rex_certificate_file' => 'nullable|mimes:pdf|max:2048',
                'forwarder_name' => 'nullable|string',
                'peb_number' => 'nullable|string|max:100',
                'container_type' => 'nullable|string|max:50',
                'shipment_mode' => 'nullable|in:SEA,AIR',
            ]);

            // Pengiriman bisa menggabungkan beberapa SO sekaligus (mis. kirim tablet dari SO-001
            // + kursi dari SO-005 dalam satu DO). sales_order_ids dikirim sebagai array/JSON;
            // fallback ke sales_order_id tunggal untuk kompatibilitas kalau ada caller lama.
            $salesOrderIds = $request->sales_order_ids;
            if (is_string($salesOrderIds)) {
                $salesOrderIds = json_decode($salesOrderIds, true);
            }
            if (empty($salesOrderIds) || !is_array($salesOrderIds)) {
                $salesOrderIds = $request->sales_order_id ? [$request->sales_order_id] : [];
            }
            if (empty($salesOrderIds)) {
                throw new \Exception('Minimal pilih 1 Sales Order.');
            }

            $salesOrders = SalesOrder::whereIn('id', $salesOrderIds)->get();
            if ($salesOrders->count() !== count(array_unique($salesOrderIds))) {
                throw new \Exception('Ada Sales Order yang tidak ditemukan.');
            }
            if ($salesOrders->pluck('buyer_id')->unique()->count() > 1) {
                throw new \Exception('Semua Sales Order yang digabung dalam satu pengiriman harus dari buyer yang sama.');
            }
            if ($salesOrders->pluck('currency')->unique()->count() > 1) {
                throw new \Exception('Semua Sales Order yang digabung dalam satu pengiriman harus memakai currency yang sama.');
            }

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
                'sales_order_id' => $salesOrders->first()->id,
                'buyer_id' => $salesOrders->first()->buyer_id,
                'user_id' => Auth::id(),
                'delivery_date' => $request->delivery_date,
                'driver_name' => $request->driver_name,
                'vehicle_number' => $request->vehicle_number,
                'notes' => $request->notes,
                'status' => 'DRAFT',
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

            $packingWarehouseId = Warehouse::where('code', 'PACKING')->value('id');
            if (!$packingWarehouseId) {
                throw new \Exception('Gudang Packing tidak ditemukan. Pastikan warehouse dengan code PACKING sudah ada.');
            }

            $this->validateStockForDetails($details, $packingWarehouseId);

            foreach ($details as $detail) {
                $item = Item::with('unit')->find($detail['item_id']);
                if (!$item) {
                    throw new \Exception("Item ID {$detail['item_id']} tidak ditemukan");
                }

                DeliveryOrderDetail::create(array_merge(
                    [
                        'delivery_order_id' => $deliveryOrder->id,
                        'sales_order_detail_id' => $detail['sales_order_detail_id'],
                        'item_id' => $detail['item_id'],
                        'item_name' => $item->name,
                        'item_unit' => $item->unit->name ?? 'Pcs',
                        'hs_code' => $item->hs_code ?? null,
                    ],
                    $this->calculateDetailTotals($detail, $item)
                ));
            }

            $deliveryOrder = DeliveryOrder::with(['salesOrder.buyer', 'buyer', 'user', 'details.item'])
                ->find($deliveryOrder->id);
            $deliveryOrder->barcode_image = $deliveryOrder->barcode_image
                ? asset('storage/' . $deliveryOrder->barcode_image)
                : null;
            $this->attachSalesOrdersInfo($deliveryOrder);

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Delivery Order berhasil dibuat dengan status DRAFT. Stok belum berkurang.',
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

    public function ship($id)
    {
        DB::beginTransaction();
        try {
            $deliveryOrder = DeliveryOrder::with('details.salesOrderDetail')->findOrFail($id);

            if ($deliveryOrder->status !== 'DRAFT') {
                throw new \Exception("Hanya DO dengan status DRAFT yang bisa dikirim. Status saat ini: {$deliveryOrder->status}");
            }

            $packingWarehouseId = Warehouse::where('code', 'PACKING')->value('id');
            if (!$packingWarehouseId) {
                throw new \Exception('Gudang Packing tidak ditemukan. Pastikan warehouse dengan code PACKING sudah ada.');
            }

            foreach ($deliveryOrder->details as $detail) {
                if (!$detail->nw_per_box || !$detail->gw_per_box) {
                    throw new \Exception("Item {$detail->item_name} belum memiliki data NW/GW. Harap lengkapi data timbangan sebelum mengirim!");
                }

                $item = Item::find($detail->item_id);
                if (!$item) {
                    throw new \Exception("Item ID {$detail->item_id} tidak ditemukan");
                }

                $inventoryStock = (float) Inventory::where('item_id', $detail->item_id)
                    ->where('warehouse_id', $packingWarehouseId)
                    ->sum('qty_pcs');

                // Fallback ke items.stock jika inventories PACKING belum terisi
                $useItemStockFallback = $inventoryStock <= 0;
                $effectiveStock = $useItemStockFallback ? (float) $item->stock : $inventoryStock;

                if ($effectiveStock < $detail->quantity_shipped) {
                    throw new \Exception("Stock {$item->name} di Gudang Packing tidak cukup. Tersedia: {$effectiveStock}, Diminta: {$detail->quantity_shipped}");
                }

                if (!$useItemStockFallback) {
                    // Normal: kurangi dari inventories
                    $inventories = Inventory::where('item_id', $detail->item_id)
                        ->where('warehouse_id', $packingWarehouseId)
                        ->where('qty_pcs', '>', 0)
                        ->lockForUpdate()
                        ->get();

                    $remaining = $detail->quantity_shipped;
                    foreach ($inventories as $inv) {
                        /** @var Inventory $inv */
                        if ($remaining <= 0) break;
                        $toTake = min($remaining, $inv->qty_pcs);
                        $inv->decrement('qty_pcs', $toTake);
                        $remaining -= $toTake;
                    }
                } else {
                    // Fallback: stok ada di items.stock, sync ke inventories sekalian
                    $inv = Inventory::firstOrCreate(
                        ['warehouse_id' => $packingWarehouseId, 'item_id' => $detail->item_id],
                        ['qty_pcs' => 0, 'qty_m3' => 0]
                    );
                    $inv->update(['qty_pcs' => max(0, (float) $item->stock - $detail->quantity_shipped)]);
                }

                // Selalu kurangi items.stock agar tetap sinkron
                $item->decrement('stock', $detail->quantity_shipped);

                StockMovement::create([
                    'item_id' => $detail->item_id,
                    'type' => 'OUT',
                    'quantity' => $detail->quantity_shipped,
                    'notes' => "Pengiriman barang (DO: {$deliveryOrder->do_number})",
                ]);

                InventoryLog::create([
                    'date' => now()->toDateString(),
                    'time' => now()->toTimeString(),
                    'item_id' => $detail->item_id,
                    'warehouse_id' => $packingWarehouseId,
                    'qty' => $detail->quantity_shipped,
                    'direction' => 'OUT',
                    'transaction_type' => 'SALE',
                    'reference_type' => 'DeliveryOrder',
                    'reference_id' => $deliveryOrder->id,
                    'reference_number' => $deliveryOrder->do_number,
                    'notes' => "Pengiriman ke " . $deliveryOrder->buyer->name,
                    'user_id' => Auth::id(),
                ]);

                $soDetail = SalesOrderDetail::find($detail->sales_order_detail_id);
                if ($soDetail) {
                    $soDetail->increment('quantity_shipped', $detail->quantity_shipped);
                }
            }

            $deliveryOrder->status = 'SHIPPED';
            $deliveryOrder->save();

            // DO bisa gabungan beberapa SO — update status TIAP SO yang terlibat berdasarkan
            // sisa detail SO itu sendiri, bukan cuma satu SO "utama" di header.
            $salesOrderIds = $deliveryOrder->details
                ->pluck('salesOrderDetail.sales_order_id')
                ->filter()
                ->unique();

            foreach ($salesOrderIds as $soId) {
                $salesOrder = SalesOrder::with('details')->find($soId);
                if (!$salesOrder) continue;
                $allDelivered = true;
                foreach ($salesOrder->details as $detail) {
                    if ($detail->quantity > $detail->quantity_shipped) {
                        $allDelivered = false;
                        break;
                    }
                }
                $salesOrder->status = $allDelivered ? 'Shipped' : 'Partial Shipped';
                $salesOrder->save();
            }

            DB::commit();

            $deliveryOrder = $deliveryOrder->fresh(['details', 'salesOrder', 'buyer']);
            $this->attachSalesOrdersInfo($deliveryOrder);

            return response()->json([
                'success' => true,
                'message' => 'Barang berhasil dikirim! Status: SHIPPED, Stok telah berkurang.',
                'data' => $deliveryOrder
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengirim barang: ' . $e->getMessage()
            ], 500);
        }
    }

    public function markDelivered($id)
    {
        DB::beginTransaction();
        try {
            $deliveryOrder = DeliveryOrder::with('details.salesOrderDetail')->findOrFail($id);

            if ($deliveryOrder->status !== 'SHIPPED') {
                throw new \Exception("Hanya DO dengan status SHIPPED yang bisa dikonfirmasi terima. Status saat ini: {$deliveryOrder->status}");
            }

            $deliveryOrder->status = 'DELIVERED';
            $deliveryOrder->save();

            $salesOrderIds = $deliveryOrder->details
                ->pluck('salesOrderDetail.sales_order_id')
                ->filter()
                ->unique();

            foreach ($salesOrderIds as $soId) {
                $salesOrder = SalesOrder::with('details')->find($soId);
                if (!$salesOrder) continue;
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

            DB::commit();

            $deliveryOrder = $deliveryOrder->fresh(['details', 'salesOrder', 'buyer']);
            $this->attachSalesOrdersInfo($deliveryOrder);

            return response()->json([
                'success' => true,
                'message' => 'Barang berhasil dikonfirmasi diterima customer. Status: DELIVERED. Siap untuk di-invoice!',
                'data' => $deliveryOrder
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal konfirmasi penerimaan: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getAvailableForInvoice(Request $request)
    {
        try {
            $query = DeliveryOrder::with([
                'salesOrder',
                'buyer',
                'details.item',
                'details.salesOrderDetail.salesOrder',
            ])->where('status', 'DELIVERED');

            if ($request->filled('buyer_id')) {
                $query->where('buyer_id', $request->buyer_id);
            }

            $deliveryOrders = $query->orderBy('delivery_date', 'desc')->get();

            // DO gabungan bisa hasilkan >1 invoice (1 per SO yang terlibat — lihat
            // InvoiceService::createInvoicesFromDeliveryOrder), jadi "belum di-invoice" dicek
            // per baris detail (via delivery_order_detail_id di sales_invoice_details), bukan
            // per DO — supaya DO yang sebagian SO-nya sudah di-invoice tetap muncul kalau masih
            // ada SO lain dalam DO yang sama yang belum.
            $allDetailIds = $deliveryOrders->pluck('details')->flatten()->pluck('id');
            $invoicedDetailIds = $allDetailIds->isEmpty()
                ? collect()
                : \App\Models\SalesInvoiceDetail::whereIn('delivery_order_detail_id', $allDetailIds)
                    ->pluck('delivery_order_detail_id');

            $deliveryOrders = $deliveryOrders
                ->filter(fn ($do) => $do->details->contains(fn ($d) => !$invoicedDetailIds->contains($d->id)))
                ->map(fn ($do) => $this->attachSalesOrdersInfo($do))
                ->values();

            // current_unit_price — lihat SalesOrderDetail::resolveCurrent(); dipakai preview
            // InvoiceCreate.vue supaya tidak menampilkan harga 0/usang sebelum invoice dibuat.
            $deliveryOrders->each(function ($do) {
                $do->details->each(function ($detail) {
                    $frozenSoDetail = $detail->salesOrderDetail;
                    $currentSoDetail = $frozenSoDetail
                        ? SalesOrderDetail::resolveCurrent($frozenSoDetail->sales_order_id, $detail->item_id)
                        : null;
                    $detail->current_unit_price = (float) (
                        $currentSoDetail->unit_price ?? $frozenSoDetail->unit_price ?? 0
                    );
                });
            });

            return response()->json($deliveryOrders);
        } catch (\Exception $e) {
            Log::error('Error fetching available DOs: ' . $e->getMessage());
            return response()->json(['message' => 'Error fetching delivery orders'], 500);
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
                'details.salesOrderDetail.salesOrder:id,so_number,buyer_id,currency'
            ])->findOrFail($id);

            // current_stock dihitung sama seperti getOpenSalesOrders() — stok gudang PACKING
            // dulu, fallback ke items.stock. Dipakai frontend (mode edit) untuk validasi qty
            // kirim; kalau cuma pakai items.stock langsung, item finished_good yang kolom
            // stock-nya tidak sinkron akan salah tampil "stok 0" padahal barangnya ada di PACKING.
            $packingWarehouseId = Warehouse::where('code', 'PACKING')->value('id');
            $deliveryOrder->details->each(function ($detail) use ($packingWarehouseId) {
                $packingStock = (float) Inventory::where('item_id', $detail->item_id)
                    ->where('warehouse_id', $packingWarehouseId)
                    ->sum('qty_pcs');
                $detail->current_stock = $packingStock > 0
                    ? $packingStock
                    : (float) ($detail->item->stock ?? 0);
            });

            // current_unit_price — lihat SalesOrderDetail::resolveCurrent() untuk kenapa ini
            // perlu (sales_order_detail_id di DO bisa nyangkut ke baris SO detail yang sudah
            // soft-deleted/usang kalau SO diedit setelah DO dibuat).
            $deliveryOrder->details->each(function ($detail) {
                $frozenSoDetail = $detail->salesOrderDetail; // relasi withTrashed()
                $currentSoDetail = $frozenSoDetail
                    ? SalesOrderDetail::resolveCurrent($frozenSoDetail->sales_order_id, $detail->item_id)
                    : null;
                $detail->current_unit_price = (float) (
                    $currentSoDetail->unit_price ?? $frozenSoDetail->unit_price ?? 0
                );
            });

            $fields = ['consignee_info', 'applicant_info', 'notify_info'];
            foreach ($fields as $f) {
                $val = $deliveryOrder->$f;
                $deliveryOrder->$f = $val && is_string($val) ? json_decode($val) : (object) [];
            }

            $deliveryOrder->barcode_image = $deliveryOrder->barcode_image
                ? asset('storage/' . $deliveryOrder->barcode_image)
                : null;
            $this->attachSalesOrdersInfo($deliveryOrder);

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
            $do = DeliveryOrder::with('details')->findOrFail($id);

            if ($do->status !== 'DRAFT') {
                throw new \Exception('Hanya DO dengan status DRAFT yang bisa diedit');
            }

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

            if ($request->has('details')) {
                $details = $request->details;
                if (is_string($details)) {
                    $details = json_decode($details, true);
                }

                $packingWarehouseId = Warehouse::where('code', 'PACKING')->value('id');
                if ($packingWarehouseId) {
                    $this->validateStockForDetails($details, $packingWarehouseId);
                }

                // Sync penuh: baris yang sudah ada di-update, baris baru (tanpa 'id' — mis.
                // pecahan crate/box baru yang ditambah admin saat edit) dibuat, baris yang
                // sudah tidak ada lagi di payload (dihapus admin) dihapus dari DB.
                $keptDetailIds = [];

                foreach ($details as $detail) {
                    if (isset($detail['id'])) {
                        $doDetail = DeliveryOrderDetail::find($detail['id']);
                        if (!$doDetail || $doDetail->delivery_order_id != $do->id) {
                            continue;
                        }

                        $nwPerBox = $detail['nw_per_box'] ?? $doDetail->nw_per_box;
                        $gwPerBox = $detail['gw_per_box'] ?? $doDetail->gw_per_box;
                        $m3PerCarton = $detail['m3_per_carton'] ?? $doDetail->m3_per_carton;
                        $woodPerPcs = $detail['wood_consumed_per_pcs'] ?? $doDetail->wood_consumed_per_pcs;
                        $quantityBoxes = $detail['quantity_boxes'] ?? $doDetail->quantity_boxes;
                        $quantityShipped = $detail['quantity_shipped'] ?? $doDetail->quantity_shipped;

                        $doDetail->update([
                            'quantity_shipped' => $quantityShipped,
                            'quantity_boxes' => $quantityBoxes,
                            'quantity_crates' => $detail['quantity_crates'] ?? $doDetail->quantity_crates,
                            'nw_per_box' => $nwPerBox,
                            'gw_per_box' => $gwPerBox,
                            'm3_per_carton' => $m3PerCarton,
                            'wood_consumed_per_pcs' => $woodPerPcs,
                            'total_nw' => ($nwPerBox && $quantityBoxes) ? $nwPerBox * $quantityBoxes : null,
                            'total_gw' => ($gwPerBox && $quantityBoxes) ? $gwPerBox * $quantityBoxes : null,
                            'total_m3' => ($m3PerCarton && $quantityBoxes) ? $m3PerCarton * $quantityBoxes : null,
                            'total_wood_consumed' => ($woodPerPcs && $quantityShipped) ? $woodPerPcs * $quantityShipped : null,
                        ]);
                        $keptDetailIds[] = $doDetail->id;
                    } else {
                        $item = Item::with('unit')->find($detail['item_id']);
                        if (!$item) {
                            throw new \Exception("Item ID {$detail['item_id']} tidak ditemukan");
                        }

                        $newDetail = DeliveryOrderDetail::create(array_merge(
                            [
                                'delivery_order_id' => $do->id,
                                'sales_order_detail_id' => $detail['sales_order_detail_id'],
                                'item_id' => $detail['item_id'],
                                'item_name' => $item->name,
                                'item_unit' => $item->unit->name ?? 'Pcs',
                                'hs_code' => $item->hs_code ?? null,
                            ],
                            $this->calculateDetailTotals($detail, $item)
                        ));
                        $keptDetailIds[] = $newDetail->id;
                    }
                }

                DeliveryOrderDetail::where('delivery_order_id', $do->id)
                    ->whereNotIn('id', $keptDetailIds)
                    ->delete();
            }

            $do = DeliveryOrder::with(['details.item'])->find($do->id);
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

            if ($deliveryOrder->status !== 'DRAFT') {
                throw new \Exception('Hanya DO dengan status DRAFT yang bisa dihapus');
            }

            if ($deliveryOrder->barcode_image) {
                Storage::disk('public')->delete($deliveryOrder->barcode_image);
            }
            if ($deliveryOrder->rex_certificate_file) {
                Storage::disk('public')->delete($deliveryOrder->rex_certificate_file);
            }

            $deliveryOrder->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Delivery Order berhasil dihapus'
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus DO: ' . $e->getMessage()
            ], 500);
        }
    }
}