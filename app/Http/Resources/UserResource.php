<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /*
         * users.business_id is nullable, so this can legitimately be null. It used to
         * be dereferenced straight away, which meant one orphaned user took down the
         * whole admin users list for every admin.
         */
        $business = $this->business;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'created_at' => $this->created_at,
            'business' => $business,
            'templates' => $business?->templates ?? [],
            'suppliers' => $business?->suppliers ?? [],
        ];
    }
}
