<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;

class ConsolidateMigrations extends Command
{
    protected $signature = 'svil:consolidate-migrations {--apply : Simpan backup riwayat lalu sejajarkan metadata ke baseline gabungan}';

    protected $description = 'Align a fully migrated pre-release database with the consolidated baseline without changing application tables';

    public const BASELINE = [
        '0001_01_01_000000_create_users_table',
        '0001_01_01_000001_create_cache_table',
        '0001_01_01_000002_create_jobs_table',
        '2026_11_01_144643_create_personal_access_tokens_table',
        '2026_11_01_144748_create_svil_foundation_tables',
        '2026_11_01_161343_create_group_work_and_action_tables',
        '2026_11_01_162459_create_observations_table',
        '2026_11_01_164838_create_learning_modules_table',
        '2026_11_01_165207_create_discussions_tables',
        '2026_11_01_172915_create_school_lms_tables',
    ];

    public const MERGED = [
        '2026_11_01_160644_add_username_to_users_table',
        '2026_11_01_160911_add_join_code_to_classrooms_table',
        '2026_11_01_162457_add_teacher_review_to_material_attempts',
        '2026_11_01_165205_add_lms_content_fields_to_materials',
        '2026_11_01_180150_extend_research_and_reflection_metadata',
        '2026_11_01_191615_add_custom_topic_label_to_materials',
    ];

    public function handle(): int
    {
        try {
            $files = array_keys($this->laravel['migrator']->getMigrationFiles(database_path('migrations')));
            if (! $this->sameNames($files, self::BASELINE) || ! Schema::hasTable('migrations')) {
                throw new LogicException('Perintah ini hanya untuk baseline prarilis 10 migrasi. Instalasi kosong cukup menjalankan migrate.');
            }

            $this->assertSchema();
            $rows = DB::table('migrations')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
            $this->assertHistory($rows);

            if ($this->sameNames(array_column($rows, 'migration'), self::BASELINE)) {
                $this->info('Riwayat sudah menggunakan baseline gabungan; tidak ada perubahan.');

                return self::SUCCESS;
            }

            if (! $this->option('apply')) {
                $this->info('Skema lengkap dan 16 riwayat lama sesuai. Dengan --apply, hanya metadata migrations diselaraskan menjadi 10 baseline. Tabel/data aplikasi tidak diubah.');

                return self::SUCCESS;
            }

            $backup = DB::transaction(function (): string {
                $rows = DB::table('migrations')->orderBy('id')->lockForUpdate()->get()->map(fn (object $row): array => (array) $row)->all();
                $this->assertHistory($rows);
                $path = 'migration-history/'.Str::uuid().'.json';
                $snapshot = json_encode([
                    'created_at' => now()->toIso8601String(),
                    'database' => DB::connection()->getDatabaseName(),
                    'rows' => $rows,
                    'baseline' => self::BASELINE,
                ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
                if (! Storage::disk('local')->put($path, $snapshot)) {
                    throw new LogicException('Backup riwayat belum tersimpan; metadata tidak diubah.');
                }

                DB::table('migrations')->whereIn('migration', self::MERGED)->delete();
                DB::table('migrations')->whereIn('migration', self::BASELINE)->update(['batch' => 1]);

                return $path;
            });

            $this->info('Baseline gabungan tersimpan. Data aplikasi tetap utuh.');
            $this->line('Backup riwayat privat: '.$backup);

            return self::SUCCESS;
        } catch (LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function assertHistory(array $rows): void
    {
        $names = array_column($rows, 'migration');
        if (! $this->sameNames($names, self::BASELINE) && ! $this->sameNames($names, [...self::BASELINE, ...self::MERGED])) {
            throw new LogicException('Riwayat belum lengkap atau memuat migrasi lain. Konsolidasi dibatalkan; jangan menghapus atau menandai migrasi yang belum dijalankan.');
        }
    }

    private function sameNames(array $left, array $right): bool
    {
        sort($left);
        sort($right);

        return $left === $right;
    }

    private function assertSchema(): void
    {
        $required = [
            'users' => ['username', 'school_id', 'role', 'is_active'],
            'classrooms' => ['join_code'],
            'materials' => ['kind', 'subject', 'attachment_path', 'attachment_name', 'attachment_size', 'attachment_mime', 'updated_by', 'topic', 'topic_label', 'course_lesson_id'],
            'material_attempts' => ['teacher_feedback', 'reviewed_at', 'reviewed_by', 'review_version'],
            'attempt_reviews' => ['material_attempt_id', 'teacher_id', 'version', 'feedback'],
            'group_works' => ['schema_snapshot', 'module_version'],
            'research_instruments' => ['previous_instrument_id', 'target_role', 'period', 'opens_at', 'closes_at'],
            'individual_reflections' => ['classroom_id', 'student_id', 'topic', 'answers', 'schema_snapshot', 'version'],
            'reflection_revisions' => ['individual_reflection_id', 'actor_id', 'version', 'snapshot'],
            'action_followups' => ['action_plan_id', 'kind', 'occurred_on', 'content', 'source_reference', 'recorded_by'],
        ];
        foreach ($required as $table => $columns) {
            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                throw new LogicException('Skema '.$table.' belum lengkap. Konsolidasi dibatalkan tanpa mengubah riwayat.');
            }
        }

        foreach (['users' => ['username' => false], 'classrooms' => ['join_code' => false], 'materials' => ['topic' => true]] as $table => $expected) {
            $columns = collect(Schema::getColumns($table))->keyBy('name');
            foreach ($expected as $name => $nullable) {
                if ($columns[$name]['nullable'] !== $nullable) {
                    throw new LogicException('Nullability '.$table.'.'.$name.' belum sesuai baseline.');
                }
            }
        }
    }
}
