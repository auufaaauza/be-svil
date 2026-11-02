<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseLesson;
use App\Models\User;
use App\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CalendarController extends Controller
{
    private function range(Request $r): array
    {
        $r->validate(['classroom_id' => ['required', 'integer', Rule::in($r->user()->accessibleClassrooms()->pluck('id')->all())], 'from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d']]);
        $from = $r->filled('from') ? Carbon::parse($r->input('from'))->startOfDay() : now()->startOfMonth();
        $until = $r->filled('until') ? Carbon::parse($r->input('until'))->endOfDay() : now()->endOfMonth();
        abort_if($from->gt($until) || $from->diffInDays($until) > 370, 422, 'Pilih rentang maksimal satu tahun.');

        return [$r->integer('classroom_id'), $from, $until];
    }

    public function index(Request $r): JsonResponse
    {
        [$class,$from,$until] = $this->range($r);
        $events = [];
        $courses = Course::visibleTo($r->user())->where('classroom_id', $class)->pluck('id');
        foreach (CourseLesson::whereIn('course_id', $courses)->when($r->user()->role === Role::Student, fn ($q) => $q->where('status', '!=', 'draft'))->whereBetween('scheduled_on', [$from->toDateString(), $until->toDateString()])->get() as $lesson) {
            $events[] = ['id' => 'lesson-'.$lesson->id, 'kind' => 'lesson', 'title' => $lesson->title, 'date' => $lesson->scheduled_on->format('Y-m-d'), 'mode' => $lesson->mode, 'course_id' => $lesson->course_id, 'status' => $lesson->status];
        }
        foreach (Assignment::visibleTo($r->user())->where('classroom_id', $class)->whereBetween('due_at', [$from, $until])->get() as $task) {
            $events[] = ['id' => 'task-'.$task->id, 'kind' => 'task', 'title' => $task->title, 'date' => $task->due_at->toIso8601String(), 'mode' => null, 'status' => $task->status];
        }
        foreach (Assessment::visibleTo($r->user())->where('classroom_id', $class)->get() as $a) {
            foreach (['opens_at' => 'assessment_open', 'closes_at' => 'assessment_close'] as $field => $kind) {
                if ($a->$field && $a->$field->between($from, $until)) {
                    $events[] = ['id' => $kind.'-'.$a->id, 'kind' => $kind, 'title' => $a->title, 'date' => $a->$field->toIso8601String(), 'mode' => null, 'status' => $a->status];
                }
            }
        }usort($events, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return response()->json(['data' => $events]);
    }

    private function attendanceData(Request $r): array
    {
        [$class,$from,$until] = $this->range($r);
        $staff = $r->user()->role !== Role::Student;
        $lessons = CourseLesson::whereIn('course_id', Course::visibleTo($r->user())->where('classroom_id', $class)->select('id'))->where('status', '!=', 'draft')->whereBetween('scheduled_on', [$from->toDateString(), $until->toDateString()])->orderBy('scheduled_on')->get();
        $students = User::where('role', Role::Student)->whereIn('id', DB::table('classroom_user')->where('classroom_id', $class)->select('user_id'))->when(! $staff, fn ($q) => $q->whereKey($r->user()->id))->orderBy('name')->get(['id', 'name']);
        $records = DB::table('lesson_attendances')->whereIn('course_lesson_id', $lessons->pluck('id'))->get();
        $rows = $students->map(function ($student) use ($lessons, $records) {
            $own = $records->where('student_id', $student->id);

            return ['student_id' => $student->id, 'name' => $student->name, 'present' => $own->where('status', 'present')->count(), 'absent' => $own->where('status', 'absent')->count(), 'excused' => $own->where('status', 'excused')->count(), 'unrecorded' => $lessons->count() - $own->count(), 'records' => $own->values()];
        });

        return ['classroom_id' => $class, 'lessons' => $lessons, 'students' => $rows];
    }

    public function attendance(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->attendanceData($r)]);
    }

    public function csv(Request $r): StreamedResponse
    {
        abort_if($r->user()->role === Role::Student, 403);
        $data = $this->attendanceData($r);

        return response()->streamDownload(function () use ($data): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['peserta', 'kelas', 'hadir', 'tidak_hadir', 'izin', 'belum_dicatat'], escape: '');
            foreach ($data['students'] as $row) {
                fputcsv($out, ['P-'.$row['student_id'], $data['classroom_id'], $row['present'], $row['absent'], $row['excused'], $row['unrecorded']], escape: '');
            }fclose($out);
        }, 'svil-kehadiran.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
