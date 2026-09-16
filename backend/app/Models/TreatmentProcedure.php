<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TreatmentProcedure extends Model
{
    use BelongsToBusiness;

    protected static function booted(): void
    {
        static::bootBelongsToBusiness();
    }

    protected $fillable = [
        'business_id',
        'treatment_plan_id',
        'tooth_number',
        'procedure_code',
        'procedure_name',
        'description',
        'cost',
        'status',
        'completed_at',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2',
            'completed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function treatmentPlan(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlan::class);
    }
}
