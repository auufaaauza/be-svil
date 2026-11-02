<?php

namespace App\Http\Middleware;

use App\Models\Classroom;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureWritableClassroom
{
    public function handle(Request $r, Closure $next): Response
    {
        if (in_array($r->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($r);
        }
        if ($r->is('api/v1/classrooms/*') && $r->method() === 'PUT' && count(explode('/', $r->path())) === 4) {
            return $next($r);
        }
        $ids = [];
        if ($r->filled('classroom_id') && is_numeric($r->input('classroom_id'))) {
            $ids[] = (int) $r->input('classroom_id');
        }
        foreach ($r->route()?->parameters() ?? [] as $parameter) {
            if (! $parameter instanceof Model || $r->is('api/v1/courses/*/copy')) {
                continue;
            }
            if ($parameter instanceof Classroom) {
                $ids[] = $parameter->id;
            } elseif ($parameter->getAttribute('classroom_id')) {
                $ids[] = $parameter->classroom_id;
            } elseif (method_exists($parameter, 'group')) {
                $ids[] = $parameter->group?->classroom_id;
            } elseif (method_exists($parameter, 'course')) {
                $ids[] = $parameter->course?->classroom_id;
            } elseif (method_exists($parameter, 'assignment')) {
                $ids[] = $parameter->assignment?->classroom_id;
            } elseif (method_exists($parameter, 'assessment')) {
                $ids[] = $parameter->assessment?->classroom_id;
            } elseif (method_exists($parameter, 'plan')) {
                $ids[] = $parameter->plan?->group?->classroom_id;
            } elseif (method_exists($parameter, 'material')) {
                $ids[] = $parameter->material?->classroom_id;
            }
        }
        if ($r->route('reflection') && is_numeric($r->route('reflection'))) {
            $ids[] = DB::table('individual_reflections')->where('id', $r->route('reflection'))->value('classroom_id');
        }
        if ($r->filled('course_lesson_id')) {
            $ids[] = DB::table('course_lessons as l')->join('courses as c', 'c.id', '=', 'l.course_id')->where('l.id', $r->integer('course_lesson_id'))->value('c.classroom_id');
        }
        abort_if(Classroom::whereIn('id', array_filter($ids))->whereNotNull('archived_at')->exists(), 422, 'Kelas ini sudah diarsipkan. Riwayat dapat dibaca; kegiatan baru dilakukan pada kelas aktif.');

        return $next($r);
    }
}
