<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\CourseChapter;
use App\Models\CourseLesson;
use App\Models\GroupWork;
use App\Models\LearningGroup;
use App\Models\LearningModule;
use App\Models\Material;
use App\Models\School;
use App\Models\User;
use App\Models\WordwallGrade;
use App\Role;
use App\StudentAccounts;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TestingSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $school = School::firstOrCreate(['code' => 'SVIL-DEMO'], ['name' => 'Madrasah Demo SVIL (Pengujian)']);
            $class = Classroom::firstOrNew(['school_id' => $school->id, 'name' => 'Kelas V Demo', 'academic_year' => '2026/2027']);
            if (! $class->exists) {
                if (Classroom::where('join_code', 'SVILTEST2026')->exists()) {
                    throw new RuntimeException('Kode SVILTEST2026 dipakai kelas lain. Seeder dihentikan tanpa mengubah data.');
                }
                $class->join_code = 'SVILTEST2026';
                $class->save();
            }

            $admin = $this->account('admin', 'admin@gmail.com', 'Administrator SVIL', Role::Admin, 'admin123', null);
            $teacher = $this->account('guru1', 'guru@gmail.com', 'Guru Demo', Role::Teacher, 'guru123', $school->id);
            $students = collect(range(1, 5))->map(fn (int $n): User => $this->account('siswa'.$n, 'siswa'.$n.'@gmail.com', 'Siswa '.$n, Role::Student, StudentAccounts::DEFAULT_PASSWORD, $school->id));
            $class->users()->syncWithoutDetaching([$teacher->id, ...$students->pluck('id')->all()]);
            $groups = collect([1, 2])->map(fn (int $n): LearningGroup => LearningGroup::firstOrCreate(['classroom_id' => $class->id, 'name' => 'Kelompok Demo '.$n]));
            foreach ($students as $index => $student) {
                $target = $groups[$index < 3 ? 0 : 1];
                $alreadyAssigned = LearningGroup::where('classroom_id', $class->id)->whereHas('users', fn ($q) => $q->whereKey($student->id))->exists();
                if (! $alreadyAssigned) {
                    $target->users()->syncWithoutDetaching([$student->id]);
                }
            }

            if (Artisan::call('svil:import-lkpd', ['--by' => $admin->email, '--no-interaction' => true]) !== 0) {
                throw new RuntimeException('Impor LKPD awal gagal.');
            }
            $module = LearningModule::where('topic', 0)->where('status', 'published')->firstOrFail();
            if (! $class->active_lkpd_id) {
                $class->forceFill(['active_lkpd_id' => $module->id])->save();
            }

            $course = Course::firstOrCreate(['classroom_id' => $class->id, 'title' => 'IPAS Demo'], ['subject' => 'IPAS', 'semester' => 'genap', 'method' => 'svil', 'description' => 'Data contoh untuk menguji fitur. Bukan hasil penelitian.', 'status' => 'published', 'created_by' => $teacher->id, 'updated_by' => $teacher->id]);
            $chapter = CourseChapter::firstOrCreate(['course_id' => $course->id, 'title' => 'Mengenal Indonesia'], ['position' => 1]);
            $lesson = CourseLesson::firstOrCreate(['course_id' => $course->id, 'course_chapter_id' => $chapter->id, 'title' => 'Pertemuan Demo: Peta dan Laut'], ['objective' => 'Menguji akses materi, tugas, absen, dan LKPD.', 'position' => 1, 'scheduled_on' => now()->toDateString(), 'mode' => 'blended', 'status' => 'open']);
            Material::firstOrCreate(['classroom_id' => $class->id, 'title' => 'Materi Demo: Indonesia Negara Kepulauan'], ['teacher_id' => $teacher->id, 'updated_by' => $teacher->id, 'course_lesson_id' => $lesson->id, 'content' => 'Indonesia terdiri dari banyak pulau yang dihubungkan oleh laut. Amati lingkunganmu dan diskusikan cara merawatnya. Materi ini adalah contoh pengujian; sumber LKPD utama tersedia pada menu LKPD.', 'topic' => 0, 'subject' => 'IPAS', 'kind' => 'material', 'status' => 'published']);
            Assignment::firstOrCreate(['classroom_id' => $class->id, 'title' => 'Tugas Demo: Pengamatan Lingkungan'], ['course_lesson_id' => $lesson->id, 'created_by' => $teacher->id, 'instructions' => 'Tuliskan tiga hal yang kamu amati di lingkungan sekitar dan satu tindakan untuk merawatnya. Ini tugas contoh pengujian.', 'kind' => 'task', 'submission_mode' => 'individual', 'status' => 'published', 'allow_late' => true, 'max_score' => 100]);
            foreach ($students as $index => $student) {
                if (! DB::table('lesson_attendances')->where('course_lesson_id', $lesson->id)->where('student_id', $student->id)->exists()) {
                    DB::table('lesson_attendances')->insert(['course_lesson_id' => $lesson->id, 'student_id' => $student->id, 'status' => $index === 4 ? 'excused' : 'present', 'evidence' => 'Contoh absen pengujian, bukan data penelitian.', 'recorded_by' => $teacher->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            WordwallGrade::firstOrCreate(['classroom_id' => $class->id, 'student_id' => $students[3]->id, 'game_id' => 'wordwall-123874298'], ['teacher_id' => $teacher->id, 'game_title' => 'LKPD Zamrud Khatulistiwa – IPAS Kelas V', 'topic' => 1, 'score' => 80, 'feedback' => 'Contoh nilai Wordwall pengujian, bukan hasil penelitian.', 'version' => 1]);
            $gradedGroup = $groups[1];
            if (! GroupWork::where('learning_group_id', $gradedGroup->id)->where('topic', $module->topic)->exists()) {
                $fields = $module->contentFor($class->id)['fields'];
                $pages = [];
                foreach (array_chunk($fields, $module->page_size) as $index => $unused) {
                    $pages[$index] = ['submitted_at' => now()->toIso8601String(), 'submitted_by' => $students[3]->id, 'review_status' => 'reviewed', 'feedback' => 'Contoh umpan balik pengujian.', 'reviewed_at' => now()->toIso8601String(), 'reviewed_by' => $teacher->id];
                }
                $work = GroupWork::create(['learning_group_id' => $gradedGroup->id, 'topic' => $module->topic, 'answers' => collect($fields)->mapWithKeys(fn (array $field): array => [$field['key'] => '[Contoh pengujian] Jawaban kelompok untuk '.$field['label']])->all(), 'status' => 'reviewed', 'version' => 1, 'feedback' => 'Contoh nilai 85 untuk menguji tampilan. Bukan hasil penelitian.', 'score' => 85, 'reviewed_by' => $teacher->id, 'submitted_at' => now(), 'reviewed_at' => now(), 'schema_snapshot' => $fields, 'module_version' => $module->version, 'page_size' => $module->page_size, 'member_ids' => $gradedGroup->users()->pluck('users.id')->all(), 'page_submissions' => $pages]);
                $work->recordRevision($teacher);
            }
            $this->command?->info('Data demo siap. Kode kelas: '.$class->join_code.'. Akun admin, guru1, siswa1–siswa5 memakai kredensial pengujian.');
        });
    }

    private function account(string $username, string $email, string $name, Role $role, string $password, ?int $school): User
    {
        $matches = User::where('username', $username)->orWhere('email', $email)->get();
        if ($matches->count() > 1 || $matches->contains(fn (User $user): bool => $user->role !== $role)) {
            throw new RuntimeException('Identitas '.$username.' bertabrakan dengan akun lain. Seeder dibatalkan.');
        }
        $user = $matches->first() ?? new User;
        $user->forceFill(['name' => $name, 'username' => $username, 'email' => $email, 'password' => $password, 'role' => $role, 'school_id' => $school, 'is_active' => true, 'must_change_password' => false])->save();

        return $user;
    }
}
