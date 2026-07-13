<?php

namespace App\Console\Commands;

use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\Item;
use App\Models\MesinProduction;
use App\Models\MesinProductionInput;
use App\Models\MesinProductionOutput;
use App\Models\MesinProductionReject;
use App\Models\MouldingProduction;
use App\Models\MouldingProductionOutput;
use App\Models\MouldingProductionReject;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderDetail;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RevertMouldingMesin extends Command
{
    protected $signature = 'app:revert-moulding-mesin
        {identifier : Nomor PO (po_number) atau nomor SO (so_number)}
        {--po_id= : ID production_orders langsung, dipakai kalau identifier ambigu (>1 PO cocok)}
        {--detail= : ID production_order_details spesifik (kalau mau revert 1 produk saja, bukan semua produk di PO)}
        {--dry-run : Tampilkan apa yang akan dihapus/dikembalikan tanpa menyimpan}';

    protected $description = 'Hapus transaksi Moulding & Mesin utk sebuah PO (reverse stok S4S/MESIN/REJECT + qty_natural/qty_warna, hapus record & InventoryLog terkait, reset current_stage detail ke null) supaya admin bisa input ulang dari awal. WAJIB pastikan belum ada transaksi Assembling yang memakai stok hasil Moulding/Mesin ini sebelum dijalankan tanpa --dry-run.';

    public function handle()
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('=== DRY RUN — tidak ada perubahan yang disimpan ===');
        }

        $po = $this->resolveProductionOrder();
        if (!$po) {
            return 1;
        }

        $detailsQuery = ProductionOrderDetail::where('production_order_id', $po->id);
        if ($this->option('detail')) {
            $detailsQuery->where('id', $this->option('detail'));
        }
        $details = $detailsQuery->with('item')->get();

        if ($details->isEmpty()) {
            $this->error('Tidak ada production_order_details yang cocok.');
            return 1;
        }

        $detailIds = $details->pluck('id')->all();
        $this->info("PO: {$po->po_number} (id={$po->id})");
        foreach ($details as $d) {
            $this->line("  - detail_id={$d->id} item={$d->item?->name} current_stage={$d->current_stage}");
        }

        // Kalau revert scope-nya seluruh PO (bukan 1 detail spesifik lewat --detail), ikut sertakan
        // juga transaksi lama yang production_order_detail_id-nya NULL (dibuat sebelum kolom ini ada,
        // sebelum migrasi 2026_06_23) — data itu tidak bisa dipastikan milik produk yang mana, tapi
        // karena scope-nya "hapus semua utk PO ini", aman ikut kehapus juga.
        $includeLegacy = !$this->option('detail');

        $mesinRecords = MesinProduction::where('ref_po_id', $po->id)
            ->where(function ($q) use ($detailIds, $includeLegacy) {
                $q->whereIn('production_order_detail_id', $detailIds);
                if ($includeLegacy) {
                    $q->orWhereNull('production_order_detail_id');
                }
            })
            ->get();
        $mouldingRecords = MouldingProduction::where('ref_po_id', $po->id)
            ->where(function ($q) use ($detailIds, $includeLegacy) {
                $q->whereIn('production_order_detail_id', $detailIds);
                if ($includeLegacy) {
                    $q->orWhereNull('production_order_detail_id');
                }
            })
            ->get();

        $legacyCount = $mesinRecords->whereNull('production_order_detail_id')->count()
            + $mouldingRecords->whereNull('production_order_detail_id')->count();
        if ($legacyCount > 0) {
            $this->warn("Termasuk {$legacyCount} transaksi lama (dibuat sebelum ada penandaan per-produk) yang tidak bisa dipastikan milik produk mana di PO ini — ikut terhapus karena scope-nya seluruh PO.");
        }

        $this->info("Ditemukan {$mesinRecords->count()} transaksi Mesin dan {$mouldingRecords->count()} transaksi Moulding yang akan direvert.");

        if ($mesinRecords->isEmpty() && $mouldingRecords->isEmpty()) {
            $this->warn('Tidak ada apa-apa untuk direvert.');
            return 0;
        }

        DB::beginTransaction();
        try {
            $warehouses = Warehouse::whereIn('code', ['S4S', 'MESIN', 'REJECT'])->get()->keyBy('code');
            foreach (['S4S', 'MESIN', 'REJECT'] as $code) {
                if (!$warehouses->has($code)) {
                    throw new \RuntimeException("Gudang {$code} tidak ditemukan.");
                }
            }

            // Revert Mesin dulu (kejadiannya paling akhir), baru Moulding — urutan mundur kronologis
            foreach ($mesinRecords as $mesin) {
                $this->revertMesin($mesin, $warehouses);
            }
            foreach ($mouldingRecords as $moulding) {
                $this->revertMoulding($moulding, $warehouses);
            }

            // current_stage detail cuma pernah diisi 'moulding'/'mesin' oleh 2 stage ini — aman direset ke null
            ProductionOrderDetail::whereIn('id', $detailIds)
                ->whereIn('current_stage', ['moulding', 'mesin'])
                ->update(['current_stage' => null]);

            if ($dryRun) {
                DB::rollBack();
                $this->warn('Dry-run selesai, tidak ada yang disimpan. Jalankan tanpa --dry-run untuk eksekusi beneran.');
            } else {
                DB::commit();
                $this->info('Selesai. Data Moulding & Mesin untuk PO ini sudah dihapus dan stok dikembalikan.');
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Gagal, semua perubahan dibatalkan: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    private function resolveProductionOrder(): ?ProductionOrder
    {
        if ($this->option('po_id')) {
            $po = ProductionOrder::find($this->option('po_id'));
            if (!$po) {
                $this->error('po_id tidak ditemukan.');
            }
            return $po;
        }

        $identifier = $this->argument('identifier');
        $candidates = ProductionOrder::where('po_number', $identifier)
            ->orWhereHas('salesOrder', fn ($q) => $q->where('so_number', $identifier))
            ->get();

        if ($candidates->isEmpty()) {
            $this->error("Tidak ada Production Order yang cocok dengan '{$identifier}'.");
            return null;
        }

        if ($candidates->count() > 1) {
            $this->error("Ditemukan {$candidates->count()} PO yang cocok, sebutkan salah satu lewat --po_id=");
            foreach ($candidates as $c) {
                $this->line("  - id={$c->id} po_number={$c->po_number} type={$c->type}");
            }
            return null;
        }

        return $candidates->first();
    }

    private function revertMesin(MesinProduction $mesin, $warehouses)
    {
        $this->line("Revert Mesin: {$mesin->document_number} (id={$mesin->id})");

        foreach (MesinProductionOutput::where('mesin_production_id', $mesin->id)->get() as $output) {
            $inv = Inventory::where('item_id', $output->item_id)
                ->where('warehouse_id', $warehouses['MESIN']->id)
                ->lockForUpdate()
                ->first();

            $available = (float) ($inv->qty_pcs ?? 0);
            if (!$inv || $available < $output->qty) {
                throw new \RuntimeException("Stok MESIN item_id={$output->item_id} tidak cukup untuk direvert (tersedia {$available}, butuh {$output->qty}) — kemungkinan sudah dipakai di Assembling. Batalkan, cek dulu manual.");
            }
            $inv->decrement('qty_pcs', $output->qty);

            $item = Item::lockForUpdate()->find($output->item_id);
            if ($item && $item->type === Item::TYPE_COMPONENT) {
                $inputRow = $output->mesin_production_input_id
                    ? MesinProductionInput::find($output->mesin_production_input_id)
                    : null;
                $bucket = ($inputRow?->finishing) === 'warna' ? 'qty_warna' : 'qty_natural';

                if ((float) $item->{$bucket} < $output->qty) {
                    throw new \RuntimeException("Bucket {$bucket} item {$item->code} kurang dari qty yang mau direvert.");
                }
                $item->{$bucket} = (float) $item->{$bucket} - $output->qty;
                $item->stock = (float) $item->qty_natural + (float) $item->qty_warna;
                $item->save();

                $inv->{$bucket} = max(0, (float) $inv->{$bucket} - $output->qty);
                $inv->save();
            }
        }

        foreach (MesinProductionInput::where('mesin_production_id', $mesin->id)->get() as $input) {
            $inv = Inventory::where('item_id', $input->item_id)
                ->where('warehouse_id', $warehouses['S4S']->id)
                ->lockForUpdate()
                ->first();

            if ($inv) {
                $inv->increment('qty_pcs', $input->qty);
            } else {
                $inv = Inventory::create([
                    'item_id' => $input->item_id,
                    'warehouse_id' => $warehouses['S4S']->id,
                    'qty_pcs' => $input->qty,
                ]);
            }

            $item = Item::lockForUpdate()->find($input->item_id);
            if ($item && $item->type === Item::TYPE_COMPONENT) {
                $bucket = $input->finishing === 'warna' ? 'qty_warna' : 'qty_natural';
                $item->{$bucket} = (float) $item->{$bucket} + $input->qty;
                $item->stock = (float) $item->qty_natural + (float) $item->qty_warna;
                $item->save();

                $inv->{$bucket} = (float) $inv->{$bucket} + $input->qty;
                $inv->save();
            }
        }

        foreach (MesinProductionReject::where('mesin_production_id', $mesin->id)->get() as $reject) {
            $inv = Inventory::where('item_id', $reject->item_id)
                ->where('warehouse_id', $warehouses['REJECT']->id)
                ->lockForUpdate()
                ->first();
            $available = (float) ($inv->qty_pcs ?? 0);
            if (!$inv || $available < $reject->qty) {
                throw new \RuntimeException("Stok REJECT item_id={$reject->item_id} tidak cukup untuk direvert.");
            }
            $inv->decrement('qty_pcs', $reject->qty);
        }

        InventoryLog::where('reference_type', 'MesinProduction')
            ->where('reference_id', $mesin->id)
            ->delete();

        $mesin->delete(); // cascade hapus inputs/outputs/rejects
    }

    private function revertMoulding(MouldingProduction $moulding, $warehouses)
    {
        $this->line("Revert Moulding: {$moulding->document_number} (id={$moulding->id})");

        foreach (MouldingProductionOutput::where('moulding_production_id', $moulding->id)->get() as $output) {
            $inv = Inventory::where('item_id', $output->item_id)
                ->where('warehouse_id', $warehouses['S4S']->id)
                ->lockForUpdate()
                ->first();

            $available = (float) ($inv->qty_pcs ?? 0);
            if (!$inv || $available < $output->qty) {
                throw new \RuntimeException("Stok S4S item_id={$output->item_id} tidak cukup untuk direvert (tersedia {$available}, butuh {$output->qty}).");
            }
            $inv->decrement('qty_pcs', $output->qty);

            $item = Item::lockForUpdate()->find($output->item_id);
            if ($item && $item->type === Item::TYPE_COMPONENT) {
                $bucket = $output->finishing === 'warna' ? 'qty_warna' : 'qty_natural';
                if ((float) $item->{$bucket} < $output->qty) {
                    throw new \RuntimeException("Bucket {$bucket} item {$item->code} kurang dari qty yang mau direvert.");
                }
                $item->{$bucket} = (float) $item->{$bucket} - $output->qty;
                $item->stock = (float) $item->qty_natural + (float) $item->qty_warna;
                $item->save();

                $inv->{$bucket} = max(0, (float) $inv->{$bucket} - $output->qty);
                $inv->save();
            }
        }

        foreach (MouldingProductionReject::where('moulding_production_id', $moulding->id)->get() as $reject) {
            $inv = Inventory::where('item_id', $reject->item_id)
                ->where('warehouse_id', $warehouses['REJECT']->id)
                ->lockForUpdate()
                ->first();
            $available = (float) ($inv->qty_pcs ?? 0);
            if (!$inv || $available < $reject->qty) {
                throw new \RuntimeException("Stok REJECT item_id={$reject->item_id} tidak cukup untuk direvert.");
            }
            $inv->decrement('qty_pcs', $reject->qty);
        }

        // Input RST: warehouse asalnya tidak disimpan di moulding_production_inputs,
        // ambil dari InventoryLog OUT yang dibuat bareng saat store() (1:1 per baris input).
        $outLogs = InventoryLog::where('reference_type', 'MouldingProduction')
            ->where('reference_id', $moulding->id)
            ->where('direction', 'OUT')
            ->where('transaction_type', 'MOULDING')
            ->get();

        foreach ($outLogs as $log) {
            $inv = Inventory::where('item_id', $log->item_id)
                ->where('warehouse_id', $log->warehouse_id)
                ->lockForUpdate()
                ->first();
            if ($inv) {
                $inv->increment('qty_pcs', $log->qty);
            } else {
                Inventory::create([
                    'item_id' => $log->item_id,
                    'warehouse_id' => $log->warehouse_id,
                    'qty_pcs' => $log->qty,
                ]);
            }
        }

        InventoryLog::where('reference_type', 'MouldingProduction')
            ->where('reference_id', $moulding->id)
            ->delete();

        $moulding->delete(); // cascade hapus inputs/outputs/rejects
    }
}
