<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessType;

class BusinessTypeController extends Controller
{
    public function __invoke()
    {
        return response()->json(
            BusinessType::all(['id', 'slug', 'name_en', 'name_ar', 'allowed_modules'])
        );
    }
}
