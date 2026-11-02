<?php

namespace Database\Seeders;

use App\Models\Classroom;
use App\Models\LearningGroup;
use App\Models\Material;
use App\Models\School;
use App\Models\User;
use App\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('DemoSeeder hanya untuk local/testing.');
        }
        $password = config('svil.demo_password');
        if (! is_string($password) || strlen($password) < 12) {
            throw new \RuntimeException('Set SVIL_DEMO_PASSWORD (minimal 12 karakter) pada .env lokal.');
        }
        DB::transaction(function () use ($password) {
            for ($number = 1; $number <= 4; $number++) {
                School::firstOrCreate(['code' => 'MIN-'.$number.'-GARUT'], ['name' => 'MIN '.$number.' Garut']);
            }
            $school = School::where('code', 'MIN-2-GARUT')->firstOrFail();
            $admin = User::firstOrCreate(['email' => 'admin@svil.test'], ['name' => 'Admin Contoh', 'role' => Role::Admin, 'password' => $password]);
            $teacher = User::firstOrCreate(['email' => 'guru@svil.test'], ['name' => 'Guru Contoh', 'role' => Role::Teacher, 'school_id' => $school->id, 'password' => $password]);
            $student = User::firstOrCreate(['email' => 'siswa@svil.test'], ['name' => 'Nara Contoh', 'role' => Role::Student, 'school_id' => $school->id, 'password' => $password]);
            $classroom = Classroom::firstOrCreate(['school_id' => $school->id, 'name' => 'V Contoh', 'academic_year' => '2026/2027']);
            $classroom->users()->syncWithoutDetaching([$teacher->id, $student->id]);
            $group = LearningGroup::firstOrCreate(['classroom_id' => $classroom->id, 'name' => 'Nusantara Contoh']);
            $group->users()->syncWithoutDetaching([$student->id]);
            Material::firstOrCreate(['classroom_id' => $classroom->id, 'teacher_id' => $teacher->id, 'title' => 'Contoh: Laut bersih, kehidupan sehat'], [
                'content' => "Laut menghubungkan pulau dan membantu kehidupan warga.\nBersama guru, amati sampah di sekitar sekolah. Pilah sampah yang aman dan letakkan di tempatnya.\nTidak perlu turun ke sungai atau laut untuk membantu menjaga air.",
                'topic' => 0, 'status' => 'published',
                'question' => ['type' => 'choice', 'prompt' => 'Apa yang dapat kita lakukan untuk menjaga laut?', 'options' => ['Membuang sampah di tempatnya', 'Melempar plastik ke sungai', 'Membiarkan sampah berserakan', 'Menuangkan minyak ke air'], 'correctIndex' => 0, 'modelAnswer' => '', 'explanation' => 'Sampah dari daratan dapat terbawa aliran air. Mulai dari menjaga lingkungan sekitar.'],
            ]);
        });
        $this->command?->info('Akun contoh dibuat tanpa mengganti akun lama. Password: SVIL_DEMO_PASSWORD pada .env lokal.');
    }
}
