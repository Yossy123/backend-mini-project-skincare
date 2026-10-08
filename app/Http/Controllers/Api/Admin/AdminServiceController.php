<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminServiceRequest;
use App\Models\Service;
use App\Services\AdminTreatmentService;
use Illuminate\Http\JsonResponse;

class AdminServiceController extends Controller
{
    public function __construct(
        protected AdminTreatmentService $treatmentService
    ) {}

    /**
     * Display all treatments, including inactive ones.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->treatmentService->listServices(),
        ], 200);
    }

    /**
     * Display a single treatment.
     */
    public function show(int $id): JsonResponse
    {
        return response()->json([
            'data' => Service::withCount('appointments')->findOrFail($id),
        ], 200);
    }

    /**
     * Store a newly created treatment.
     */
    public function store(AdminServiceRequest $request): JsonResponse
    {
        $service = $this->treatmentService->createService($request->validated());

        return response()->json([
            'message' => "Layanan '{$service->name}' berhasil dibuat.",
            'data' => $service,
        ], 201);
    }

    /**
     * Update an existing treatment.
     */
    public function update(int $id, AdminServiceRequest $request): JsonResponse
    {
        $service = Service::findOrFail($id);

        $updated = $this->treatmentService->updateService($service, $request->validated());

        return response()->json([
            'message' => "Layanan '{$updated->name}' berhasil diperbarui.",
            'data' => $updated,
        ], 200);
    }

    /**
     * Toggle whether the treatment can be booked.
     */
    public function toggle(int $id): JsonResponse
    {
        $updated = $this->treatmentService->toggleActivation(Service::findOrFail($id));

        $statusText = $updated->is_active ? 'diaktifkan' : 'dinonaktifkan';

        return response()->json([
            'message' => "Layanan '{$updated->name}' berhasil {$statusText}.",
            'data' => $updated,
        ], 200);
    }

    /**
     * Delete a treatment that has never been booked.
     */
    public function destroy(int $id): JsonResponse
    {
        $service = Service::findOrFail($id);
        $name = $service->name;

        $this->treatmentService->deleteService($service);

        return response()->json([
            'message' => "Layanan '{$name}' berhasil dihapus.",
        ], 200);
    }
}
