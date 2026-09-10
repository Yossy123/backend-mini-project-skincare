<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddressRequest;
use App\Http\Resources\AddressResource;
use App\Models\Address;
use App\Services\AddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AddressController extends Controller
{
    public function __construct(
        protected AddressService $addressService
    ) {}

    /**
     * Display a listing of the user's addresses.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $addresses = $this->addressService->getAddressesForUser($request->user());

        return AddressResource::collection($addresses);
    }

    /**
     * Store a newly created address for the authenticated user.
     */
    public function store(AddressRequest $request): JsonResponse
    {
        $address = $this->addressService->createAddress(
            $request->user(),
            $request->validated()
        );

        return (new AddressResource($address))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified address.
     */
    public function show(Request $request, int $id): JsonResponse|AddressResource
    {
        $address = Address::find($id);

        if (! $address) {
            return response()->json(['message' => 'Address not found.'], 404);
        }

        if ($address->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return new AddressResource($address);
    }

    /**
     * Update the specified address.
     */
    public function update(AddressRequest $request, int $id): JsonResponse|AddressResource
    {
        $address = Address::find($id);

        if (! $address) {
            return response()->json(['message' => 'Address not found.'], 404);
        }

        if ($address->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $updated = $this->addressService->updateAddress($address, $request->validated());

        return new AddressResource($updated);
    }

    /**
     * Remove the specified address.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $address = Address::find($id);

        if (! $address) {
            return response()->json(['message' => 'Address not found.'], 404);
        }

        if ($address->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $this->addressService->deleteAddress($address);

        return response()->json([
            'message' => 'Address deleted successfully.',
        ], 200);
    }
}
