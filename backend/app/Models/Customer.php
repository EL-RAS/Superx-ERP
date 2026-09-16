<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Customer extends Model
{
    use BelongsToBusiness;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::bootBelongsToBusiness();

        static::creating(function (Customer $customer) {
            if (empty($customer->name)) {
                $customer->name = self::nextAutoName((string) $customer->business_id);
            }

            // CRM decoupling (V1): loyalty card numbers / cards are only
            // provisioned when the CRM module is enabled. Disabled CRM falls
            // back to plain customer records; relationships stay intact for
            // a later re-enable (config('features.crm_enabled')).
            if (config('features.crm_enabled') && empty($customer->loyalty_card_number)) {
                $customer->loyalty_card_number = self::uniqueLoyaltyCardNumber((string) $customer->business_id);
            }
        });

        static::created(function (Customer $customer) {
            if (! config('features.crm_enabled')) {
                return;
            }

            LoyaltyCard::firstOrCreate(
                [
                    'business_id' => $customer->business_id,
                    'customer_id' => $customer->id,
                ],
                [
                    'card_number' => $customer->loyalty_card_number,
                    'points_balance' => 0,
                    'total_points_earned' => 0,
                    'total_points_redeemed' => 0,
                    'total_spend' => 0,
                    'tier' => 'bronze',
                    'is_active' => true,
                ]
            );
        });
    }

    protected $fillable = [
        'business_id',
        'type',
        'name',
        'email',
        'phone',
        'address',
        'delivery_notes',
        'notes',
        'loyalty_card_number',
        'loyalty_points_balance',
        'tier_level',
        'is_vip',
        'total_spend',
        'total_visits',
        'last_visit_date',
        'size_top',
        'size_bottom',
        'shoe_size',
        'fit_preference',
        'preferred_brands',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'loyalty_points_balance' => 'integer',
            'is_vip' => 'boolean',
            'total_spend' => 'decimal:2',
            'total_visits' => 'integer',
            'last_visit_date' => 'datetime',
            'preferred_brands' => 'array',
            'metadata' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function dentalCharts(): HasMany
    {
        return $this->hasMany(DentalChart::class);
    }

    public function treatmentPlans(): HasMany
    {
        return $this->hasMany(TreatmentPlan::class);
    }

    public function medicalRecords(): HasMany
    {
        return $this->hasMany(MedicalRecord::class);
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    public function insuranceClaims(): HasMany
    {
        return $this->hasMany(InsuranceClaim::class);
    }

    public function repairTickets(): HasMany
    {
        return $this->hasMany(RepairTicket::class);
    }

    public function serialNumbers(): HasMany
    {
        return $this->hasMany(SerialNumber::class);
    }

    public function loyaltyCards(): HasMany
    {
        return $this->hasMany(LoyaltyCard::class);
    }

    public function loyaltyCard(): HasOne
    {
        return $this->hasOne(LoyaltyCard::class)->orderBy('id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Auto-generate the next "Client-N" name for phone-only POS customers.
     */
    public static function nextAutoName(string $businessId): string
    {
        $maxNum = static::query()
            ->where('business_id', $businessId)
            ->where('name', 'ilike', 'Client-%')
            ->pluck('name')
            ->reduce(function (?int $max, string $name) {
                $num = (int) preg_replace('/^Client-/i', '', $name);

                return $num > ($max ?? 0) ? $num : $max;
            }, 0);

        return 'Client-'.($maxNum + 1);
    }

    public static function uniqueLoyaltyCardNumber(?string $businessId): string
    {
        do {
            $number = 'LOY-'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
        } while (
            static::query()->where('business_id', $businessId)->where('loyalty_card_number', $number)->exists()
            || LoyaltyCard::query()->where('business_id', $businessId)->where('card_number', $number)->exists()
        );

        return $number;
    }

    public function recalcTierLevel(): string
    {
        // The loyalty card is the single source of truth for tiers; mirror it
        // so card upgrades are never clobbered by an independent ladder.
        $card = $this->loyaltyCard()->first();

        if ($card) {
            $tier = (string) $card->tier;
        } else {
            $spend = (float) $this->total_spend;

            if ($spend >= 500) {
                $tier = 'gold';
            } elseif ($spend >= 100) {
                $tier = 'silver';
            } else {
                $tier = 'bronze';
            }
        }

        if ($this->tier_level !== $tier) {
            $this->updateQuietly(['tier_level' => $tier]);
        }

        return $tier;
    }

    public function recalcVip(): void
    {
        $spend = (float) $this->total_spend;

        if ($spend >= 500) {
            if (! $this->is_vip) {
                $this->updateQuietly(['is_vip' => true]);
            }

            return;
        }

        $businessId = $this->business_id;
        $threshold = static::query()
            ->where('business_id', $businessId)
            ->where('total_spend', '>', 0)
            ->value(DB::raw('PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY total_spend)'));

        if ($threshold !== null && $spend >= (float) $threshold) {
            if (! $this->is_vip) {
                $this->updateQuietly(['is_vip' => true]);
            }
        } else {
            if ($this->is_vip) {
                $this->updateQuietly(['is_vip' => false]);
            }
        }
    }
}
