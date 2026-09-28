<?php

namespace App\Modules\Subscription\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Subscription\Models\SaasPlan;
use Illuminate\Http\JsonResponse;

class SaasPlanController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = SaasPlan::where('active', true)
            ->where('is_public', true)
            ->orderBy('display_order', 'asc')
            ->get();

        return response()->json($plans);
    }
}