<?php

namespace Tests\Feature\Produksi;

use App\Http\Controllers\Api\FinishingController;
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
 * Finishing sekarang bisa ambil dari Gudang Sanding ATAU Rustik (per baris item),
 * bukan lagi terkunci ke Assembling. Invariant:
 * - sourceItems() default mengembalikan stok dari SANDING + RUSTIK, dengan info gudang per baris
 * - store() mengurangi stok dari gudang yang dipilih per item, menambah ke FINISHING
 * - store() masih menerima source_warehouse_id di level atas
 * - store() menolak (bukan 500) kalau gudang sumber tidak ada sama sekali
 */
class FinishingSourceWarehouseTest extends TestCase
{
    use DatabaseTransactions;

    private Warehouse $whSanding;
    private Warehouse $whRustik;
    private Warehouse $whFinishing;
    private Item $itemA;
    private Item $itemB;
    private ProductionOrder $po;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create([
            'name' => 'Test User',
            'email' => 'test-finishing-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
        ]));

        $this->whSanding   = Warehouse::create(['code' => 'SANDING', 'name' => 'Test Gudang Sanding']);
        $this->whRustik    = Warehouse::create(['code' => 'RUSTIK', 'name' => 'Test Gudang Rustik']);
        $this->whFinishing = Warehouse::create(['code' => 'FINISHING', 'name' => 'Test Gudang Finishing']);

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

        Inventory::create(['item_id' => $this->itemA->id, 'warehouse_id' => $this->whSanding->id, 'qty_pcs' => 20]);
        Inventory::create(['item_id' => $this->itemB->id, 'warehouse_id' => $this->whRustik->id, 'qty_pcs' => 15]);

        $buyer = Buyer::create(['code' => 'BYR-TEST', 'name' => 'Test Buyer']);
        $so = SalesOrder::create([
            'so_number' => 'SO-TEST-FNS-0001',
            'buyer_id' => $buyer->id,
            'user_id' => auth()->id(),
            'so_date' => date('Y-m-d'),
            'status' => 'Confirmed',
        ]);
        $this->po = ProductionOrder::create([
            'po_number' => 'PO-TEST-FNS-0001',
            'sales_order_id' => $so->id,
            'type' => 'production',
            'status' => 'released',
            'current_stage' => 'sanding',
            'created_by' => auth()->id(),
        ]);
    }

    private function controller(): FinishingController
    {
        return app(FinishingController::class);
    }

    public function test_source_items_default_mengembalikan_stok_dari_sanding_dan_rustik(): void
    {
        $res = $this->controller()->sourceItems(new Request());
        $data = collect($res->getData(true)['data']);

        $this->assertEqualsCanonicalizing(
            ['SANDING', 'RUSTIK'],
            $data->pluck('warehouse_code')->unique()->values()->all()
        );

        $rowA = $data->firstWhere('item_id', $this->itemA->id);
        $this->assertNotNull($rowA);
        $this->assertEquals('SANDING', $rowA['warehouse_code']);
        $this->assertEquals($this->whSanding->id, $rowA['warehouse_id']);
        $this->assertEquals(20, (float) $rowA['qty_available']);

        $rowB = $data->firstWhere('item_id', $this->itemB->id);
        $this->assertEquals('RUSTIK', $rowB['warehouse_code']);
    }

    public function test_store_per_item_mengurangi_dari_gudang_yang_dipilih(): void
    {
        $req = Request::create('/produksi/finishing/store', 'POST', [
            'date' => date('Y-m-d'),
            'ref_po_id' => $this->po->id,
            'items' => [
                ['item_id' => $this->itemA->id, 'source_warehouse_id' => $this->whSanding->id, 'qty' => 5],
                ['item_id' => $this->itemB->id, 'source_warehouse_id' => $this->whRustik->id, 'qty' => 3],
            ],
        ]);

        $res = $this->controller()->store($req);
        $this->assertEquals(201, $res->getStatusCode());

        $this->assertEquals(15, (float) Inventory::where('item_id', $this->itemA->id)->where('warehouse_id', $this->whSanding->id)->value('qty_pcs'));
        $this->assertEquals(12, (float) Inventory::where('item_id', $this->itemB->id)->where('warehouse_id', $this->whRustik->id)->value('qty_pcs'));

        $this->assertEquals(5, (float) Inventory::where('item_id', $this->itemA->id)->where('warehouse_id', $this->whFinishing->id)->value('qty_pcs'));
        $this->assertEquals(3, (float) Inventory::where('item_id', $this->itemB->id)->where('warehouse_id', $this->whFinishing->id)->value('qty_pcs'));

        $outA = InventoryLog::where('transaction_type', 'FINISHING')->where('direction', 'OUT')
            ->where('item_id', $this->itemA->id)->first();
        $this->assertEquals($this->whSanding->id, $outA->warehouse_id);

        $this->po->refresh();
        $this->assertEquals('finishing', $this->po->current_stage);
    }

    public function test_store_masih_terima_source_warehouse_id_level_atas(): void
    {
        $req = Request::create('/produksi/finishing/store', 'POST', [
            'date' => date('Y-m-d'),
            'ref_po_id' => $this->po->id,
            'source_warehouse_id' => $this->whRustik->id,
            'items' => [
                ['item_id' => $this->itemB->id, 'qty' => 4],
            ],
        ]);

        $res = $this->controller()->store($req);
        $this->assertEquals(201, $res->getStatusCode());
        $this->assertEquals(11, (float) Inventory::where('item_id', $this->itemB->id)->where('warehouse_id', $this->whRustik->id)->value('qty_pcs'));
    }

    public function test_store_menolak_kalau_tidak_ada_gudang_sumber_sama_sekali(): void
    {
        $req = Request::create('/produksi/finishing/store', 'POST', [
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
        $req = Request::create('/produksi/finishing/store', 'POST', [
            'date' => date('Y-m-d'),
            'ref_po_id' => $this->po->id,
            'items' => [
                ['item_id' => $this->itemA->id, 'source_warehouse_id' => $this->whRustik->id, 'qty' => 1],
            ],
        ]);

        try {
            $this->controller()->store($req);
            $this->fail('Seharusnya ValidationException karena stok tidak ada di RUSTIK');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Test Gudang Rustik', json_encode($e->errors()));
        }

        $this->assertEquals(20, (float) Inventory::where('item_id', $this->itemA->id)->where('warehouse_id', $this->whSanding->id)->value('qty_pcs'));
    }
}
