<?php

namespace App\Learning;

use App\Models\GroupWork;
use App\Models\LearningModule;

final class LkpdPages
{
    public const SIZE = 4;

    public static function size(GroupWork|LearningModule $record): int
    {
        return max(1, (int) ($record->page_size ?? self::SIZE));
    }

    public static function fields(LearningModule $module, ?GroupWork $work, int $classroom): array
    {
        if ($work?->schema_snapshot) {
            return $work->schema_snapshot;
        }

        return $module->contentFor($classroom)['fields'];
    }

    public static function entries(GroupWork $work): array
    {
        if ($work->page_submissions !== null) {
            return $work->page_submissions;
        }
        if ($work->status === 'draft') {
            return [];
        }
        $pages = [];
        foreach (array_chunk($work->schema_snapshot ?? [], self::size($work)) as $index => $unused) {
            $pages[$index] = ['submitted_at' => $work->submitted_at?->toIso8601String(), 'submitted_by' => null, 'review_status' => in_array($work->status, ['reviewed', 'needs_revision'], true) ? $work->status : 'pending', 'feedback' => $work->feedback, 'reviewed_at' => $work->reviewed_at?->toIso8601String(), 'reviewed_by' => $work->reviewed_by];
        }

        return $pages;
    }

    public static function status(array $pages, int $total): string
    {
        if (! $pages) {
            return 'draft';
        }
        if (collect($pages)->contains(fn (array $page): bool => ($page['review_status'] ?? '') === 'needs_revision')) {
            return 'needs_revision';
        }
        if (count($pages) < $total) {
            return 'partially_submitted';
        }

        return collect($pages)->every(fn (array $page): bool => ($page['review_status'] ?? '') === 'reviewed') ? 'reviewed' : 'submitted';
    }
}
