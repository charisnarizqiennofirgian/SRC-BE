<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Asset extends Model
{
    use SoftDeletes;

    const CATEGORY_MESIN_PRODUKSI = 'mesin_produksi';
    const CATEGORY_KENDARAAN = 'kendaraan';
    const CATEGORY_ALAT_BERAT = 'alat_berat';
    const CATEGORY_PERALATAN = 'peralatan';
    const CATEGORY_ELEKTRONIK = 'elektronik';
    const CATEGORY_LAINNYA = 'lainnya';

    const CONDITION_BAIK = 'baik';
    const CONDITION_RUSAK_RINGAN = 'rusak_ringan';
    const CONDITION_RUSAK_BERAT = 'rusak_berat';

    const STATUS_AKTIF = 'aktif';
    const STATUS_PERBAIKAN = 'perbaikan';
    const STATUS_TIDAK_DIPAKAI = 'tidak_dipakai';
    const STATUS_DIJUAL = 'dijual';

    const DUE_SOON_DAYS = 30;

    protected $fillable = [
        'code',
        'name',
        'category',
        'brand',
        'model_type',
        'serial_number',
        'location',
        'pic',
        'purchase_date',
        'purchase_price',
        'useful_life_years',
        'supplier_name',
        'condition',
        'status',
        'plate_number',
        'chassis_number',
        'engine_number',
        'tax_due_date',
        'kir_due_date',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'purchase_date' => 'date:Y-m-d',
        'tax_due_date' => 'date:Y-m-d',
        'kir_due_date' => 'date:Y-m-d',
        'purchase_price' => 'decimal:2',
        'useful_life_years' => 'integer',
    ];

    public static function getCategories(): array
    {
        return [
            self::CATEGORY_MESIN_PRODUKSI => 'Mesin Produksi',
            self::CATEGORY_KENDARAAN => 'Kendaraan',
            self::CATEGORY_ALAT_BERAT => 'Alat Berat',
            self::CATEGORY_PERALATAN => 'Peralatan & Perkakas',
            self::CATEGORY_ELEKTRONIK => 'Elektronik & Kantor',
            self::CATEGORY_LAINNYA => 'Lainnya',
        ];
    }

    public static function getCategoryPrefixes(): array
    {
        return [
            self::CATEGORY_MESIN_PRODUKSI => 'MP',
            self::CATEGORY_KENDARAAN => 'KND',
            self::CATEGORY_ALAT_BERAT => 'AB',
            self::CATEGORY_PERALATAN => 'PRL',
            self::CATEGORY_ELEKTRONIK => 'ELK',
            self::CATEGORY_LAINNYA => 'LN',
        ];
    }

    public static function getConditions(): array
    {
        return [
            self::CONDITION_BAIK => 'Baik',
            self::CONDITION_RUSAK_RINGAN => 'Rusak Ringan',
            self::CONDITION_RUSAK_BERAT => 'Rusak Berat',
        ];
    }

    public static function getStatuses(): array
    {
        return [
            self::STATUS_AKTIF => 'Aktif',
            self::STATUS_PERBAIKAN => 'Dalam Perbaikan',
            self::STATUS_TIDAK_DIPAKAI => 'Tidak Dipakai',
            self::STATUS_DIJUAL => 'Dijual / Afkir',
        ];
    }

    public static function generateCode(string $category): string
    {
        $prefix = 'INV-' . (self::getCategoryPrefixes()[$category] ?? 'LN') . '-';

        $last = self::withTrashed()
            ->where('code', 'like', $prefix . '%')
            ->pluck('code')
            ->map(fn ($code) => (int) substr($code, strlen($prefix)))
            ->max() ?? 0;

        return $prefix . str_pad($last + 1, 3, '0', STR_PAD_LEFT);
    }

    public function serviceRecords()
    {
        return $this->hasMany(AssetServiceRecord::class)->orderByDesc('service_date')->orderByDesc('id');
    }

    public function latestServiceRecord()
    {
        return $this->hasOne(AssetServiceRecord::class)->ofMany(['service_date' => 'max', 'id' => 'max']);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isVehicle(): bool
    {
        return in_array($this->category, [self::CATEGORY_KENDARAAN, self::CATEGORY_ALAT_BERAT], true);
    }

    public function getBookValue(?Carbon $at = null): ?float
    {
        $price = (float) $this->purchase_price;
        if (!$this->purchase_date || !$this->useful_life_years || $price <= 0) {
            return null;
        }

        $at = $at ?? now();
        $totalMonths = $this->useful_life_years * 12;
        $usedMonths = max(0, (int) floor($this->purchase_date->diffInMonths($at)));
        $remaining = $price - ($price * min($usedMonths, $totalMonths) / $totalMonths);

        return round(max(0, $remaining), 2);
    }

    public function getDueAlerts(?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $limit = $today->copy()->addDays(self::DUE_SOON_DAYS);
        $alerts = [];

        $check = function (string $type, string $label, $date) use (&$alerts, $today, $limit) {
            if (!$date) {
                return;
            }
            $date = Carbon::parse($date)->startOfDay();
            if ($date->gt($limit)) {
                return;
            }
            $alerts[] = [
                'type' => $type,
                'label' => $label,
                'date' => $date->toDateString(),
                'overdue' => $date->lt($today),
                'days' => (int) $today->diffInDays($date, false),
            ];
        };

        if ($this->status !== self::STATUS_DIJUAL) {
            $check('tax', 'Pajak STNK', $this->tax_due_date);
            $check('kir', 'KIR', $this->kir_due_date);
            $check('service', 'Servis', $this->latestServiceRecord?->next_service_date);
        }

        return $alerts;
    }
}
