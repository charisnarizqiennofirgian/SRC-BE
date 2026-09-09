<?php

namespace Tests\Feature\Produksi;

use App\Http\Controllers\Api\SandingController;
use App\Models\Buyer;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\Item;
use App\Models\ProductionOrder;
use App\Models\SalesOrder;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Sanding sekarang bisa ambil dari Gudang Mesin ATAU Assembling (per baris item),
 * bukan lagi terkunci ke Assembling. Invariant:
 * - sourceItems() default mengembalikan stok dari MESIN + ASSEMBLING, dengan info gudang per baris
 * - store() mengurangi stok dari gudang yang dipilih per item, menambah ke SANDING
 * - store() masih menerima source_warehouse_id di level atas (kompatibilitas menu Sampel)
 * - store() menolak (bukan 500) kalau gudang sumber tidak ada sama sekali
 */
class SandingSourceWarehouseTest extends TestCase
{
    use DatabaseTransactions;

    private Warehouse $whMesin;
    private Warehouse $whAssembling;
    private Warehouse $whSanding;
    private Item $itemA;
    private Item $itemB;
    private ProductionOrder $po;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create([
            'name' => 'Test User',
            'email' => 'test-sanding-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
        ]));

        $this->whMesin      = Warehouse::create(['code' => 'MESIN', 'name' => 'Test Gudang Mesin']);
        $this->whAssembling = Warehouse::create(['code' => 'ASSEMBLING', 'name' => 'Test Gudang Assembling']);
        $this->whSanding    = Warehouse::create(['code' => 'SANDING', 'name' => 'Test Gudang Sanding']);

        $category = Category::create(['name' => 'Komponen']);
        $unit = Unit::create(['name' => 'Pieces', 'short_name' => 'PCS']);

        $this->itemA = Item::create([
            'name' => 'Item A', 'code' => 'ITM-A',
            'category_id' => $category->id, 'unit_id' => $unit->id, 'stock' => 0,
        ]);
        $this->itemB = Item::create([
            'name' => 'Item B', 'code' => 'ITM-B',
            'category_id' => $category->id, 'unit_id' => $unit->id, 'stock' => 0,
        ]);

        // Item A ada di MESIN (qty 20), Item B ada di ASSEMBLING (qty 15)
        Inventory::create(['item_id' => $this->itemA->id, 'warehouse_id' => $this->whMesin->id, 'qty_pcs' => 20]);
        Inventory::create(['item_id' => $this->itemB->id, 'warehouse_id' => $this->whAssembling->id, 'qty_pcs' => 15]);

        $buyer = Buyer::create(['code' => 'BYR-TEST', 'name' => 'Test Buyer']);
        $so = SalesOrder::create([
            'so_number' => 'SO-TEST-SND-0001',
            'buyer_id' => $buyer->id,
            'user_id' => auth()->id(),
            'so_date' => date('Y-m-d'),
            'status' => 'Confirmed',
        ]);
        $this->po = ProductionOrder::create([
            'po_number' => 'PO-TEST-SND-0001',
            'sales_order_id' => $so->id,
            'type' => 'production',
            'status' => 'released',
            'current_stage' => 'assembly',
            'created_by' => auth()->id(),
        ]);
    }

    private function controller(): SandingController
    {
        return app(SandingController::class);
    }

    public function test_source_items_default_mengembalikan_stok_dari_mesin_dan_assembling(): void
    {
        $res = $this->controller()->sourceItems(new Request());
        $data = collect($res->getData(true)['data']);

        $this->assertEqualsCanonicalizing(
            ['MESIN', 'ASSEMBLING'],
            $data->pluck('warehouse_code')->unique()->values()->all()
        );

        $rowA = $data->firstWhere('item_id', $this->itemA->id);
        $this->assertNotNull($rowA);
        $this->assertEquals('MESIN', $rowA['warehouse_code']);
        $this->assertEquals($this->whMesin->id, $rowA['warehouse_id']);
        $this->assertEquals(20, (float) $rowA['qty_available']);

        $rowB = $data->firstWhere('item_id', $this->itemB->id);
        $this->assertEquals('ASSEMBLING', $rowB['warehouse_code']);
    }

    public function test_store_per_item_mengurangi_dari_gudang_yang_dipilih(): void
    {
        $req = Request::create('/produksi/sanding/store', 'POST', [
            'date' => date('Y-m-d'),
            'ref_po_id' => $this->po->id,
            'items' => [
                ['item_id' => $this->itemA->id, 'source_warehouse_id' => $this->whMesin->id, 'qty' => 5],
                ['item_id' => $this->itemB->id, 'source_warehouse_id' => $this->whAssembling->id, 'qty' => 3],
            ],
        ]);

        $res = $this->controller()->store($req);
        $this->assertEquals(201, $res->getStatusCode());

        // MESIN: 20 - 5 = 15 ; ASSEMBLING: 15 - 3 = 12
        $this->assertEquals(15, (float) Inventory::where('item_id', $this->itemA->id)->where('warehouse_id', $this->whMesin->id)->value('qty_pcs'));
        $this->assertEquals(12, (float) Inventory::where('item_id', $this->itemB->id)->where('warehouse_id', $this->whAssembling->id)->value('qty_pcs'));

        // Masuk ke SANDING
        $this->assertEquals(5, (float) Inventory::where('item_id', $this->itemA->id)->where('warehouse_id', $this->whSanding->id)->value('qty_pcs'));
        $this->assertEquals(3, (float) Inventory::where('item_id', $this->itemB->id)->where('warehouse_id', $this->whSanding->id)->value('qty_pcs'));

        // Log OUT tercatat dengan gudang sumber yang benar
        $outA = InventoryLog::where('transaction_type', 'SANDING')->where('direction', 'OUT')
            ->where('item_id', $this->itemA->id)->first();
        $this->assertEquals($this->whMesin->id, $outA->warehouse_id);

        $this->po->refresh();
        $this->assertEquals('sanding', $this->po->current_stage);
    }

    public function test_store_masih_terima_source_warehouse_id_level_atas(): void
    {
        // Bentuk payload menu Sampel: source_warehouse_id di level atas, item tanpa gudang sendiri
        $req = Request::create('/produksi/sanding/store', 'POST', [
            'date' => date('Y-m-d'),
            'ref_po_id' => $this->po->id,
            'source_warehouse_id' => $this->whAssembling->id,
            'items' => [
                ['item_id' => $this->itemB->id, 'qty' => 4],
            ],
        ]);

        $res = $this->controller()->store($req);
        $this->assertEquals(201, $res->getStatusCode());
        $this->assertEquals(11, (float) Inventory::where('item_id', $this->itemB->id)->where('warehouse_id', $this->whAssembling->id)->value('qty_pcs'));
    }

    public function test_store_menolak_kalau_tidak_ada_gudang_sumber_sama_sekali(): void
    {
        $req = Request::create('/produksi/sanding/store', 'POST', [
            'date' => date('Y-m-d'),
            'ref_po_id' => $this->po->id,
            'items' => [
                ['item_id' => $this->itemA->id, 'qty' => 1],
            ],
        ]);

        $this->expectException(ValidationException::class);
        $this->controller()->store($req);
    }

    public function test_store_menolak_kalau_stok_di_gudang_terpilih_tidak_cukup(): void
    {
        $req = Request::create('/produksi/sanding/store', 'POST', [
            'date' => date('Y-m-d'),
            'ref_po_id' => $this->po->id,
            'items' => [
                // Item A cuma ada 20 di MESIN, tidak ada di ASSEMBLING
                ['item_id' => $this->itemA->id, 'source_warehouse_id' => $this->whAssembling->id, 'qty' => 1],
            ],
        ]);

        try {
            $this->controller()->store($req);
            $this->fail('Seharusnya ValidationException karena stok tidak ada di ASSEMBLING');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Test Gudang Assembling', json_encode($e->errors()));
        }

        // Stok MESIN tidak tersentuh
        $this->assertEquals(20, (float) Inventory::where('item_id', $this->itemA->id)->where('warehouse_id', $this->whMesin->id)->value('qty_pcs'));
    }
}
