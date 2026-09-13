<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isSelf = $viewer?->id === $this->id;
        $isStaff = (bool) $viewer?->isStaff();

        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'email' => $this->when($isSelf || $isStaff, fn () => $this->email),
            'phone' => $this->when($isSelf || $isStaff, fn () => $this->phone),
            'country' => $this->country,
            'city' => $this->city,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'risk_score' => (int) $this->risk_score,
            'email_verified' => $this->email_verified_at !== null,
            'phone_verified' => $this->phone_verified_at !== null,
            'mfa_enabled' => $this->hasMfaEnabled(),
            'is_staff' => $this->isStaff(),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')),
            'permissions' => $this->when($isSelf && $isStaff, fn () => $this->permissionNames()),
            'tickets_count' => $this->when(isset($this->tickets_count), fn () => (int) $this->tickets_count),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
