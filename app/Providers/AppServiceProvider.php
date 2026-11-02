<?php

namespace App\Providers;

use App\Learning\LearningNotifier;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\CourseLesson;
use App\Models\Discussion;
use App\Models\LearningNote;
use App\Models\Material;
use App\Models\MaterialAttempt;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        foreach ([Material::class, MaterialAttempt::class, Assignment::class, Assessment::class, AssignmentSubmission::class, AssessmentAttempt::class, CourseLesson::class, Discussion::class, LearningNote::class] as $model) {
            $model::saved(fn ($record) => LearningNotifier::observe($record));
        }
        RateLimiter::for('login', function (Request $request) {
            $identifier = $request->input('login', $request->input('email', ''));
            $identifier = is_string($identifier) ? Str::lower(trim($identifier)) : '';

            return [
                Limit::perMinute(5)->by('login:'.$identifier.'|'.$request->ip()),
                Limit::perMinute(30)->by('login-ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('credentials', fn (Request $request) => Limit::perMinute(5)->by('credentials:'.$request->user()->id.'|'.$request->ip()));
        RateLimiter::for('registration', fn (Request $request) => Limit::perMinute(5)->by('registration:'.$request->ip()));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(180)->by((string) ($request->user()?->id ?? $request->ip())));
        RateLimiter::for('attempts', fn (Request $request) => Limit::perMinute(30)->by('attempt:'.$request->user()->id));
    }
}
