<?php

namespace App\Http\Resources;

use App\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LearningModuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->resource->mediaFor($request->integer('classroom_id') ?: null), 'id' => $this->id, 'topic' => $this->topic, 'title' => $this->title, 'description' => $this->description, 'instructions' => $this->instructions, 'fields' => $this->fields, 'sourceDocument' => $this->source_document, 'sourceHash' => $this->source_hash, 'sourceText' => $this->when($request->route('module') !== null && $request->user()->role !== Role::Student, $this->source_text), 'status' => $this->status, 'version' => $this->version, 'uploadedBy' => ['id' => $this->uploader->id, 'name' => $this->uploader->name], 'updatedBy' => ['id' => $this->editor->id, 'name' => $this->editor->name], 'createdAt' => $this->created_at->toIso8601String(), 'updatedAt' => $this->updated_at->toIso8601String()];
    }
}
