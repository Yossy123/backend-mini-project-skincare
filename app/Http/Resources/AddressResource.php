<?php

namespace App\Http\Resources;

use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Address
 */
class AddressResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'label' => $this->label,
            'recipient_name' => $this->recipient_name,
            'name' => $this->name,
            'phone' => $this->phone,
            'province' => $this->province,
            'city' => $this->city,
            'district' => $this->district,
            'postal_code' => $this->postal_code,
            'address' => $this->address,
            'address_line' => $this->address_line,
            'address_detail' => $this->address_detail,
            'biteship_area_id' => $this->biteship_area_id,
            'is_default' => (bool) $this->is_default,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
