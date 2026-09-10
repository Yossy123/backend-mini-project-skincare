<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /**
     * Health check endpoint.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
        ], 200);
    }
}
