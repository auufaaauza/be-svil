<?php

namespace App\Http\Resources;

use App\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $question = $this->question;
        if ($question && $request->user()->role === Role::Student) {
            $question = ['type' => $question['type'], 'prompt' => $question['prompt'], 'options' => $question['options'] ?? [], 'visualTopic' => $question['visualTopic'] ?? null, 'hasKey' => $question['type'] === 'choice' && isset($question['correctIndex'])];
        }

        return ['id' => $this->id, 'title' => $this->title, 'content' => $this->content, 'topic' => $this->topic, 'status' => $this->status, 'version' => $this->version, 'classroomId' => $this->classroom_id, 'image' => $this->image_path ? route('materials.image', $this->id, false) : '', 'imageAlt' => $this->image_alt ?? '', 'question' => $question, 'kind' => $this->kind ?? 'material', 'subject' => $this->subject ?? 'IPAS', 'topicLabel' => $this->topic_label, 'courseLessonId' => $this->course_lesson_id, 'courseId' => $this->lesson?->course_id, 'videoId' => $this->youtube_video_id, 'videoCaption' => $this->video_caption, 'audioUrl' => $this->audio_url,
            'uploadedBy' => ['id' => $this->teacher->id, 'name' => $this->teacher->name], 'updatedBy' => $this->editor ? ['id' => $this->editor->id, 'name' => $this->editor->name] : null,
            'canEdit' => $request->user()->can('update', $this->resource), 'attachment' => $this->attachment_path ? ['name' => $this->attachment_name, 'size' => $this->attachment_size, 'url' => route('materials.attachment', $this->id, false), 'mime' => $this->attachment_mime ?? 'application/pdf', 'previewUrl' => ($this->attachment_mime ?? 'application/pdf') === 'application/pdf' ? route('materials.preview', $this->id, false) : null, 'slidesUrl' => $this->attachment_mime === 'application/vnd.openxmlformats-officedocument.presentationml.presentation' ? route('materials.slides', $this->id, false) : null] : null,
            'createdAt' => $this->created_at->toIso8601String(), 'updatedAt' => $this->updated_at->toIso8601String()];
    }
}
