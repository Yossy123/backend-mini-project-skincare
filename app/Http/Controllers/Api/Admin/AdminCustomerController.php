<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminCustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCustomerController extends Controller
{
    public function __construct(
        protected AdminCustomerService $customerService
    ) {}

    /**
     * Display a listing of customers with spending aggregations and filters.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'search',
            'is_active',
            'start_date',
            'end_date',
        ]);

        $perPage = (int) $request->get('per_page', 15);
        $customers = $this->customerService->listCustomers($filters, $perPage);

        return response()->json($customers, 200);
    }

    /**
     * Display customer profile, lifetime metrics, order history, and audit logs.
     */
    public function show(int $id): JsonResponse
    {
        $data = $this->customerService->getCustomerDetail($id);

        return response()->json([
            'data' => $data,
        ], 200);
    }

    /**
     * Toggle customer account activation status (Deactivate / Reactivate).
     */
    public function toggle(int $id, Request $request): JsonResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $data = $this->customerService->toggleActivation(
            $id,
            $request->user(),
            $request->input('reason'),
            $request->input('note')
        );

        $statusText = $data['customer']['is_active'] ? 'reactivated' : 'deactivated';

        return response()->json([
            'message' => "Customer '{$data['customer']['name']}' has been {$statusText}.",
            'data' => $data,
        ], 200);
    }
}
