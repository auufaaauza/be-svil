<?php

namespace Database\Seeders;

use App\Models\Classroom;
use App\Models\LearningGroup;
use App\Models\LearningModule;
use App\Models\School;
use App\Models\User;
use App\Role;
use App\StudentAccounts;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StudentSeeder extends Seeder
{
    public function run(): void
    {
        $classroom = DB::transaction(function (): Classroom {
            $teachers = User::where('role', Role::Teacher)
                ->where(fn ($query) => $query->where('username', 'guru1')->orWhere('email', 'guru@gmail.com'))
                ->get();
            if ($teachers->count() > 1) {
                throw new RuntimeException('Identitas guru demo bertabrakan dengan dua akun. Seeder dihentikan tanpa mengubah data.');
            }
            $teacher = $teachers->first();
            if ($teacher && ! $teacher->school_id) {
                throw new RuntimeException('Tetapkan sekolah untuk guru demo sebelum menjalankan StudentSeeder.');
            }
            $school = $teacher?->school ?? School::firstOrCreate(['code' => 'SVIL-DEMO'], ['name' => 'Madrasah Demo SVIL (Pengujian)']);
            $classroom = Classroom::firstOrNew(['school_id' => $school->id, 'name' => 'Kelas V Demo', 'academic_year' => '2026/2027']);
            if (! $classroom->exists) {
                if (Classroom::where('join_code', 'SVILTEST2026')->exists()) {
                    throw new RuntimeException('Kode SVILTEST2026 dipakai kelas lain. Seeder dihentikan tanpa mengubah data.');
                }
                $classroom->join_code = 'SVILTEST2026';
                $classroom->save();
            }
            if ($classroom->archived_at) {
                throw new RuntimeException('Kelas V Demo sudah diarsipkan. Seeder tidak membuka kembali kelas tersebut.');
            }

            $groups = collect([1, 2])->map(function (int $number) use ($classroom): LearningGroup {
                $group = LearningGroup::firstOrCreate(['classroom_id' => $classroom->id, 'name' => 'Kelompok Demo '.$number]);
                if ($group->archived_at) {
                    throw new RuntimeException('Kelompok demo sudah diarsipkan. Seeder tidak membuka kembali kelompok tersebut.');
                }

                return $group;
            });
            foreach (range(1, 5) as $number) {
                $student = $this->student($number, $school);
                $this->attachToClassroom($classroom, $student);
                $hasGroup = DB::table('learning_group_user')
                    ->join('learning_groups', 'learning_groups.id', '=', 'learning_group_user.learning_group_id')
                    ->where('learning_groups.classroom_id', $classroom->id)
                    ->where('learning_group_user.user_id', $student->id)
                    ->exists();
                if (! $hasGroup) {
                    $groups[$number <= 3 ? 0 : 1]->users()->attach($student->id);
                }
            }
            if ($teacher) {
                $this->attachToClassroom($classroom, $teacher);
            }
            if (! $classroom->active_lkpd_id) {
                $module = LearningModule::whereNull('classroom_id')->where('topic', 0)->where('status', 'published')->first();
                if ($module) {
                    $classroom->forceFill(['active_lkpd_id' => $module->id])->save();
                }
            }

            return $classroom;
        });

        $this->command?->info('Lima akun siswa tersedia di '.$classroom->name.'. Kode kelas: '.$classroom->join_code);
        $this->command?->info('Akun baru: siswa1–siswa5 / siswa1@gmail.com–siswa5@gmail.com / '.StudentAccounts::DEFAULT_PASSWORD);
        $this->command?->info('Password, profil dan status akun yang sudah ada tetap dipertahankan.');
    }

    private function student(int $number, School $school): User
    {
        $username = 'siswa'.$number;
        $email = $username.'@gmail.com';
        $matches = User::where('username', $username)->orWhere('email', $email)->get();
        if ($matches->count() > 1 || $matches->contains(fn (User $user): bool => $user->role !== Role::Student || (int) $user->school_id !== $school->id)) {
            throw new RuntimeException('Identitas '.$username.' bertabrakan dengan akun lain atau sekolah berbeda. Seeder dihentikan tanpa mengubah data.');
        }
        if ($matches->isNotEmpty()) {
            return $matches->first();
        }

        $student = new User;
        $student->forceFill(['name' => 'Siswa '.$number, 'username' => $username, 'email' => $email, 'password' => StudentAccounts::DEFAULT_PASSWORD, 'role' => Role::Student, 'school_id' => $school->id, 'is_active' => true, 'must_change_password' => false])->save();

        return $student;
    }

    private function attachToClassroom(Classroom $classroom, User $user): void
    {
        if (! DB::table('classroom_user')->where('classroom_id', $classroom->id)->where('user_id', $user->id)->exists()) {
            $classroom->users()->attach($user->id);
        }
    }
}
