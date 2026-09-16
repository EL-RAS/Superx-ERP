<?php

namespace App\Traits;

use App\Scopes\BusinessScope;
use App\Services\BusinessContext;

trait BelongsToBusiness
{
    protected static function bootBelongsToBusiness(): void
    {
        static::addGlobalScope(new BusinessScope);

        static::creating(function ($model) {
            $businessId = app(BusinessContext::class)->getBusinessId();

            if ($businessId && !$model->business_id) {
                $model->business_id = $businessId;
            }
        });
    }

    public function getQualifiedBusinessIdColumn(): string
    {
        return $this->qualifyColumn('business_id');
    }
}
