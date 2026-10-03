<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesMerchant;
use App\Http\Controllers\Controller;
use App\Overview\OverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OverviewController extends Controller
{
    use ResolvesMerchant;

    public function __invoke(Request $request, OverviewService $overview): JsonResponse
    {
        return response()->json(['data' => $overview->metrics($this->merchant($request))]);
    }
}
