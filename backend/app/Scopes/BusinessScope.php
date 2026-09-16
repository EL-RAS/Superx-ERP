<?php

namespace App\Scopes;

use App\Services\BusinessContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class BusinessScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $businessId = app(BusinessContext::class)->getBusinessId();

        if ($businessId) {
            $builder->where($model->getQualifiedBusinessIdColumn(), $businessId);
        }
    }

    public function extend(Builder $builder): void
    {
        $builder->macro('withoutBusiness', function (Builder $builder) {
            return $builder->withoutGlobalScope(BusinessScope::class);
        });

        $builder->macro('withBusiness', function (Builder $builder, string $businessId) {
            return $builder->withoutGlobalScope(BusinessScope::class)
                ->where($builder->getModel()->getQualifiedBusinessIdColumn(), $businessId);
        });
    }
}
