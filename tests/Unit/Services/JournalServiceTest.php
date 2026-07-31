<?php

namespace Tests\Unit\Services;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\JournalService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Jalur paling beresiko di seluruh sistem: akuntansi double-entry.
 * Aturan yang tidak boleh dilanggar: total_debit HARUS SELALU = total_credit
 * untuk jurnal manapun yang berhasil dibuat/di-repost. Kalau ada perubahan kode
 * di masa depan yang bikin aturan ini bocor, test ini yang pertama merah --
 * bukan laporan keuangan klien yang kelihatan aneh belakangan.
 */
class JournalServiceTest extends TestCase
{
    use DatabaseTransactions;

    private JournalService $service;
    private ChartOfAccount $akunPersediaan;
    private ChartOfAccount $akunHutang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create([
            'name' => 'Test User',
            'email' => 'test-journal-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
        ]));

        $this->service = new JournalService();

        $this->akunPersediaan = ChartOfAccount::create([
            'code' => 'TEST-110',
            'name' => 'Test Persediaan',
            'type' => ChartOfAccount::TYPE_ASET,
            'is_active' => true,
        ]);

        $this->akunHutang = ChartOfAccount::create([
            'code' => 'TEST-310',
            'name' => 'Test Hutang Usaha',
            'type' => ChartOfAccount::TYPE_KEWAJIBAN,
            'is_active' => true,
        ]);
    }

    public function test_jurnal_balance_berhasil_dibuat_dan_status_posted(): void
    {
        $journal = $this->service->createJournal(
            date('Y-m-d'),
            'Test jurnal manual balance',
            [
                ['account_id' => $this->akunPersediaan->id, 'description' => 'Debit', 'debit' => 100000, 'credit' => 0],
                ['account_id' => $this->akunHutang->id, 'description' => 'Kredit', 'debit' => 0, 'credit' => 100000],
            ]
        );

        $this->assertEquals(100000, (float) $journal->total_debit);
        $this->assertEquals(100000, (float) $journal->total_credit);
        $this->assertEquals(JournalEntry::STATUS_POSTED, $journal->status);
        $this->assertTrue($journal->isBalanced());
        $this->assertCount(2, $journal->lines);
    }

    public function test_jurnal_tidak_balance_ditolak_dan_tidak_tersimpan(): void
    {
        $jumlahJurnalSebelum = JournalEntry::count();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Jurnal tidak balance!');

        try {
            $this->service->createJournal(
                date('Y-m-d'),
                'Test jurnal timpang',
                [
                    ['account_id' => $this->akunPersediaan->id, 'description' => 'Debit', 'debit' => 100000, 'credit' => 0],
                    ['account_id' => $this->akunHutang->id, 'description' => 'Kredit', 'debit' => 0, 'credit' => 90000],
                ]
            );
        } finally {
            // DB::transaction() di dalam createJournal() sudah rollback otomatis kalau exception --
            // pastikan itu benar-benar terjadi, jurnal timpang TIDAK boleh nyangkut di database.
            $this->assertEquals($jumlahJurnalSebelum, JournalEntry::count());
        }
    }

    public function test_unpost_cuma_boleh_untuk_jurnal_manual_yang_posted(): void
    {
        $journalManual = $this->service->createJournal(
            date('Y-m-d'),
            'Test unpost',
            [
                ['account_id' => $this->akunPersediaan->id, 'description' => 'Debit', 'debit' => 50000, 'credit' => 0],
                ['account_id' => $this->akunHutang->id, 'description' => 'Kredit', 'debit' => 0, 'credit' => 50000],
            ],
            'MANUAL'
        );

        $unposted = $this->service->unpostJournal($journalManual, 'Test alasan unpost');
        $this->assertEquals(JournalEntry::STATUS_DRAFT, $unposted->status);
        $this->assertEquals('Test alasan unpost', $unposted->unpost_reason);

        // Jurnal yang levelnya BUKAN 'MANUAL' (mis. dari referensi lain seperti PurchaseBill/SalesInvoice)
        // tidak boleh bisa di-unpost lewat jalur ini -- itu aturan bisnis di canUnpost().
        $journalOtomatis = JournalEntry::create([
            'journal_number' => 'JRN-TEST-OTOMATIS',
            'date' => date('Y-m-d'),
            'description' => 'Jurnal dari referensi lain',
            'reference_type' => 'PurchaseBill',
            'reference_id' => 1,
            'total_debit' => 50000,
            'total_credit' => 50000,
            'status' => JournalEntry::STATUS_POSTED,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('tidak bisa di-unpost');
        $this->service->unpostJournal($journalOtomatis, 'Coba unpost yang tidak boleh');
    }

    public function test_repost_menolak_jurnal_yang_bukan_draft(): void
    {
        $journal = $this->service->createJournal(
            date('Y-m-d'),
            'Test repost',
            [
                ['account_id' => $this->akunPersediaan->id, 'description' => 'Debit', 'debit' => 25000, 'credit' => 0],
                ['account_id' => $this->akunHutang->id, 'description' => 'Kredit', 'debit' => 0, 'credit' => 25000],
            ]
        );

        // $journal masih berstatus POSTED (baru dibuat) -- repost cuma boleh untuk DRAFT
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Hanya jurnal DRAFT yang bisa di-post');
        $this->service->repostJournal($journal);
    }

    public function test_void_cuma_boleh_untuk_jurnal_posted(): void
    {
        $journalDraft = JournalEntry::create([
            'journal_number' => 'JRN-TEST-DRAFT',
            'date' => date('Y-m-d'),
            'description' => 'Draft, belum posted',
            'reference_type' => 'MANUAL',
            'total_debit' => 10000,
            'total_credit' => 10000,
            'status' => JournalEntry::STATUS_DRAFT,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('tidak bisa di-void');
        $this->service->voidJournal($journalDraft, 'Coba void draft');
    }
}
