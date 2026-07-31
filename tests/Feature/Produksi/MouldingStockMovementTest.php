<?php

namespace Tests\Feature\Produksi;

use App\Http\Controllers\Api\MouldingController;
use App\Models\Buyer;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderDetail;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Salah satu alur stock movement paling sering diubah & paling sering kena bug
 * di seluruh riwayat project ini (lihat CLAUDE.md: berkali-kali insiden desync
 * stok gara-gara Moulding/Mesin/Assembling). Invariant yang WAJIB selalu benar:
 * - Input RST berkurang dari gudang yang benar-benar punya stok cukup
 * - Stok tidak boleh pernah jadi negatif
 * - Output komponen bertambah ke S4S DAN ke bucket qty_natural/qty_warna yang benar
 */
class MouldingStockMovementTest extends TestCase
{
    use DatabaseTransactions;

    private Warehouse $whBuffer;
    private Warehouse $whS4s;
    private Warehouse $whReject;
    private Item $itemRst;
    private Item $itemKomponen;
    private ProductionOrderDetail $detail;
    private ProductionOrder $po;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create([
            'name' => 'Test User',
            'email' => 'test-moulding-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
        ]));

        $this->whBuffer = Warehouse::create(['code' => 'BUFFER', 'name' => 'Test Buffer']);
        $this->whS4s    = Warehouse::create(['code' => 'S4S', 'name' => 'Test S4S']);
        $this->whReject = Warehouse::create(['code' => 'REJECT', 'name' => 'Test Reject']);
        // RSTK/RSTB juga dicek controller (urutan prioritas sumber) -- buat juga walau tidak dipakai
        Warehouse::create(['code' => 'RSTK', 'name' => 'Test RSTK']);
        Warehouse::create(['code' => 'RSTB', 'name' => 'Test RSTB']);

        $category = Category::create(['name' => 'Kayu RST']);
        $categoryKomponen = Category::create(['name' => 'Komponen']);
        $unit = Unit::create(['name' => 'Pieces', 'short_name' => 'PCS']);

        $this->itemRst = Item::create([
            'name' => 'RST Test', 'code' => 'RST-TEST',
            'category_id' => $category->id, 'unit_id' => $unit->id, 'stock' => 0,
        ]);

        $this->itemKomponen = Item::create([
            'name' => 'Komponen Test', 'code' => 'KOMP-TEST',
            'category_id' => $categoryKomponen->id, 'unit_id' => $unit->id, 'stock' => 0,
            'type' => Item::TYPE_COMPONENT,
        ]);

        Inventory::create([
            'item_id' => $this->itemRst->id,
            'warehouse_id' => $this->whBuffer->id,
            'qty_pcs' => 10,
        ]);

        $buyer = Buyer::create(['code' => 'BYR-TEST', 'name' => 'Test Buyer']);
        $so = SalesOrder::create([
            'so_number' => 'SO-TEST-0001',
            'buyer_id' => $buyer->id,
            'user_id' => auth()->id(),
            'so_date' => date('Y-m-d'),
            'status' => 'Confirmed',
        ]);

        $this->po = ProductionOrder::create([
            'po_number' => 'PO-TEST-0001',
            'sales_order_id' => $so->id,
            'type' => 'production',
            'status' => 'released',
            'current_stage' => 'moulding',
            'created_by' => auth()->id(),
        ]);

        $soDetail = SalesOrderDetail::create([
            'sales_order_id' => $so->id,
            'item_id' => $this->itemKomponen->id,
            'quantity' => 100,
            'item_name' => $this->itemKomponen->name,
            'item_unit' => 'PCS',
            'unit_price' => 0,
            'line_total' => 0,
        ]);

        $this->detail = ProductionOrderDetail::create([
            'production_order_id' => $this->po->id,
            'sales_order_detail_id' => $soDetail->id,
            'item_id' => $this->itemKomponen->id,
            'qty_planned' => 100,
            'qty_produced' => 0,
        ]);
    }

    private function submit(array $groups): \Illuminate\Http\JsonResponse
    {
        $request = Request::create('/produksi/moulding', 'POST', [
            'date' => date('Y-m-d'),
            'ref_po_id' => $this->po->id,
            'production_order_detail_id' => $this->detail->id,
            'groups' => $groups,
        ]);

        return app(MouldingController::class)->store($request);
    }

    public function test_input_berkurang_dan_output_bertambah_dengan_benar(): void
    {
        $response = $this->submit([[
            'output_item_id' => $this->itemKomponen->id,
            'output_qty' => 8,
            'finishing' => 'natural',
            'inputs' => [
                ['item_id' => $this->itemRst->id, 'qty' => 6],
            ],
        ]]);

        $this->assertEquals(201, $response->getStatusCode());

        // Input RST berkurang dari gudang BUFFER (10 - 6 = 4)
        $rstInv = Inventory::where('item_id', $this->itemRst->id)->where('warehouse_id', $this->whBuffer->id)->first();
        $this->assertEquals(4, (float) $rstInv->qty_pcs);

        // Output komponen bertambah ke S4S
        $s4sInv = Inventory::where('item_id', $this->itemKomponen->id)->where('warehouse_id', $this->whS4s->id)->first();
        $this->assertNotNull($s4sInv, 'Baris inventory S4S untuk komponen output harus terbentuk');
        $this->assertEquals(8, (float) $s4sInv->qty_pcs);
        $this->assertEquals(8, (float) $s4sInv->qty_natural, 'Finishing natural harus masuk bucket qty_natural');
        $this->assertEquals(0, (float) $s4sInv->qty_warna);

        // items.qty_natural (cache global) ikut ter-update, konsisten dengan inventories
        $this->itemKomponen->refresh();
        $this->assertEquals(8, (float) $this->itemKomponen->qty_natural);
        $this->assertEquals(8, (float) $this->itemKomponen->stock);

        // current_stage detail terupdate
        $this->detail->refresh();
        $this->assertEquals('moulding', $this->detail->current_stage);
    }

    public function test_stok_tidak_pernah_jadi_negatif_kalau_input_melebihi_stok_tersedia(): void
    {
        // Stok RST cuma 10, minta 999 -- tidak ada gudang manapun yang punya cukup
        $response = $this->submit([[
            'output_item_id' => $this->itemKomponen->id,
            'output_qty' => 5,
            'finishing' => 'natural',
            'inputs' => [
                ['item_id' => $this->itemRst->id, 'qty' => 999],
            ],
        ]]);

        // Transaksi tetap "berhasil" (201) secara desain saat ini -- input yang stoknya
        // tidak cukup cuma di-skip (bukan menolak seluruh transaksi). Yang WAJIB dijamin
        // di sini bukan soal berhasil/gagal, tapi: stok TIDAK BOLEH jadi negatif.
        $this->assertEquals(201, $response->getStatusCode());

        $rstInv = Inventory::where('item_id', $this->itemRst->id)->where('warehouse_id', $this->whBuffer->id)->first();
        $this->assertGreaterThanOrEqual(0, (float) $rstInv->qty_pcs, 'inventories.qty_pcs tidak boleh minus');
        $this->assertEquals(10, (float) $rstInv->qty_pcs, 'Stok tidak tersentuh sama sekali karena tidak ada gudang yang cukup');
    }

    public function test_reject_masuk_ke_gudang_reject_dengan_benar(): void
    {
        $itemReject = Item::create([
            'name' => 'Reject Test', 'code' => 'RJ-TEST',
            'category_id' => $this->itemKomponen->category_id, 'unit_id' => $this->itemKomponen->unit_id, 'stock' => 0,
        ]);

        $response = $this->submit([[
            'output_item_id' => $this->itemKomponen->id,
            'output_qty' => 5,
            'finishing' => 'natural',
            'inputs' => [
                ['item_id' => $this->itemRst->id, 'qty' => 3],
            ],
            'reject_item_id' => $itemReject->id,
            'reject_qty' => 2,
            'reject_type' => 'moulding',
        ]]);

        $this->assertEquals(201, $response->getStatusCode());

        $rejectInv = Inventory::where('item_id', $itemReject->id)->where('warehouse_id', $this->whReject->id)->first();
        $this->assertNotNull($rejectInv);
        $this->assertEquals(2, (float) $rejectInv->qty_pcs);
    }

    public function test_output_finishing_warna_masuk_bucket_qty_warna_bukan_natural(): void
    {
        $response = $this->submit([[
            'output_item_id' => $this->itemKomponen->id,
            'output_qty' => 4,
            'finishing' => 'warna',
            'inputs' => [],
        ]]);

        $this->assertEquals(201, $response->getStatusCode());

        $s4sInv = Inventory::where('item_id', $this->itemKomponen->id)->where('warehouse_id', $this->whS4s->id)->first();
        $this->assertEquals(0, (float) $s4sInv->qty_natural);
        $this->assertEquals(4, (float) $s4sInv->qty_warna);
    }
}
