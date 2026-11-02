<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'username' => $this->username, 'email' => $this->email, 'role' => $this->role->value, 'schoolId' => $this->school_id, 'schoolName' => $this->school?->name, 'isActive' => $this->is_active, 'version' => $this->profile_version, 'mustChangePassword' => $this->must_change_password, 'membershipActive' => $this->when(isset($this->pivot), fn () => (bool) $this->pivot->is_active), 'canUseSvil' => $this->when($request->user()?->id === $this->id, fn () => $this->resource->canUseSvil())];
    }
}
