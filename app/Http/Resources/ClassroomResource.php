<?php

namespace App\Http\Resources;

use App\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClassroomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'schoolId' => $this->school_id, 'schoolName' => $this->school?->name, 'academicYear' => $this->academic_year, 'version' => $this->version, 'archived' => ($this->archived_at !== null), 'joinCode' => $this->when($request->user()->role !== Role::Student, $this->join_code)];
    }
}
