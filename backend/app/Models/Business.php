<?php

namespace App\Models;

use App\Services\DocumentNumberService;
use App\Services\RbacService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Business extends Model
{
    /** Days before expiration at which tenants start seeing renewal warnings. */
    public const EXPIRATION_WARNING_DAYS = 7;

    protected $fillable = [
        'id',
        'business_type_id',
        'name',
        'slug',
        'status',
        'logo',
        'settings',
        'plan',
        'contact_phone',
        'city',
        'subscription_starts_at',
        'expires_at',
        'max_pos_registers',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        // Every new business gets the system roles (admin, manager, staff,
        // cashier, accountant) with their default permission matrix, and the
        // default settings prescribed by its business type (e.g. supermarkets
        // auto-enable split payments, credit sales, expiry alerts & barcode).
        static::created(function (Business $business) {
            RbacService::seedForBusiness((string) $business->id);

            $type = $business->businessType;
            if ($type && ! empty($type->default_settings)) {
                $business->settings = array_merge($type->default_settings, $business->settings ?? []);
                $business->save();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'settings' => 'array',
            'subscription_starts_at' => 'date:Y-m-d',
            'expires_at' => 'date:Y-m-d',
            'max_pos_registers' => 'integer',
        ];
    }

    public function businessType(): BelongsTo
    {
        return $this->belongsTo(BusinessType::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /**
     * True when the subscription has a hard expiration date in the past.
     * Null expiration = perpetual license, never expires.
     */
    public function isExpired(): bool
    {
        return ($this->daysUntilExpiration() ?? 1) < 0;
    }

    /** Whole days until expiration (negative once expired; null when perpetual). */
    public function daysUntilExpiration(): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        $target = Carbon::parse($this->expires_at)->startOfDay();
        $today = now()->startOfDay();

        return (int) round($today->diffInDays($target, false));
    }

    /**
     * Subscription state used by the tenant gate:
     * suspended | expired | expiring_soon | active
     */
    public function subscriptionState(): string
    {
        if ($this->isSuspended()) {
            return 'suspended';
        }

        $days = $this->daysUntilExpiration();

        if ($days !== null && $days < 0) {
            return 'expired';
        }

        if ($days !== null && $days <= self::EXPIRATION_WARNING_DAYS) {
            return 'expiring_soon';
        }

        return 'active';
    }

    /**
     * Number of ACTIVE users holding POS access — the "terminals / registers"
     * metric shown in the platform owner portal and capped by max_pos_registers.
     */
    public function posSeatsUsed(): int
    {
        return $this->users()
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user) => in_array('pos.view', $user->permissionKeys(), true))
            ->count();
    }

    public function hasPosSeatAvailable(): bool
    {
        return $this->max_pos_registers === null
            || $this->posSeatsUsed() < $this->max_pos_registers;
    }

    /** Compact subscription block attached to login / bootstrap payloads. */
    public function subscriptionPayload(): array
    {
        $days = $this->daysUntilExpiration();

        return [
            'plan' => $this->plan,
            'status' => $this->status,
            'starts_at' => $this->subscription_starts_at?->format('Y-m-d'),
            'expires_at' => $this->expires_at?->format('Y-m-d'),
            'days_remaining' => $days !== null ? max(0, (int) $days) : null,
            'state' => $this->subscriptionState(),
        ];
    }

    /**
     * Resolve the effective settings for the business by layering, in order:
     * generic platform defaults → business-type default settings → stored
     * business settings (highest priority wins).
     */
    public function mergedSettings(): array
    {
        $generic = [
            'allow_split_payments' => false,
            'allow_credit_sales' => false,
            'expiry_alerts' => false,
            'low_stock_sensitivity' => 'normal',
            'barcode_scanner' => true,
            'rapid_mode' => false,
            'loyalty_enabled' => false,
            'loyalty_earn_rate' => 1,
            'loyalty_redemption_rate' => 100,
            'loyalty_alert_threshold' => 500,
            'promotions_enabled' => false,
            'sales_invoice_prefix' => DocumentNumberService::DEFAULTS['sales_invoice_prefix'],
            'purchase_order_prefix' => DocumentNumberService::DEFAULTS['purchase_order_prefix'],
            'grn_prefix' => DocumentNumberService::DEFAULTS['grn_prefix'],
            'invoice_footer_terms' => null,
            'tax_enabled' => true,
            'default_tax_rate' => 16,
            'tax_calculation_method' => 'exclusive',
            'jofotara_enabled' => false,
            'jofotara_client_id' => null,
            'jofotara_secret_key' => null,
            'tax_number' => null,
            'phone' => null,
            'address' => null,
            'auto_print_receipt' => false,
            'receipt_paper_width' => '80mm',
            'receipt_footer_message' => null,
            'allow_negative_stock' => false,
            'scale_barcode_parsing' => false,
            'scale_barcode_prefix' => '20',
            'expiry_warning_days' => 30,
        ];

        $typeDefaults = $this->businessType?->default_settings ?? [];

        return array_merge($generic, $typeDefaults, $this->settings ?? []);
    }
}
