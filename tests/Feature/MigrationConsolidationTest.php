<?php

namespace Tests\Feature;

use App\Console\Commands\ConsolidateMigrations;
use App\Models\Classroom;
use App\Models\LearningNote;
use App\Models\Material;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MigrationConsolidationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_fresh_baseline_and_rollback_create_the_same_complete_schema(): void
    {
        $expected = $this->schema();
        $this->assertSame(count(ConsolidateMigrations::BASELINE) + count($this->followups()), DB::table('migrations')->count());
        $this->assertTrue(Schema::hasColumns('materials', ['topic_label', 'attachment_mime', 'course_lesson_id']));
        $this->assertTrue(Schema::hasColumns('group_works', ['schema_snapshot', 'module_version']));
        $this->assertTrue(Schema::hasTable('action_followups'));
        $this->assertTrue(Schema::hasTable('reflection_revisions'));
        $this->artisan('migrate:rollback', ['--no-interaction' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('users'));
        $this->assertFalse(Schema::hasTable('research_instruments'));
        $this->assertFalse(Schema::hasTable('individual_reflections'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertSuccessful();
        $this->assertSame($expected, $this->schema());
    }

    public function test_existing_history_is_backed_up_and_reconciled_without_changing_application_data(): void
    {
        Storage::fake('local');
        $class = Classroom::factory()->create();
        $student = User::factory()->create(['school_id' => $class->school_id]);
        $class->users()->attach($student);
        $note = LearningNote::factory()->create(['classroom_id' => $class->id, 'student_id' => $student->id, 'content' => 'Jawaban aktual harus tetap tersimpan.']);
        $material = Material::factory()->create(['classroom_id' => $class->id, 'topic' => null, 'topic_label' => 'Materi umum', 'attachment_path' => 'attachments/asli.pdf']);
        $schemaBefore = $this->schema();
        $dataBefore = $this->data();
        $this->legacyHistory();
        $historyBefore = DB::table('migrations')->orderBy('id')->get()->toArray();

        $this->artisan('svil:consolidate-migrations')->assertSuccessful();
        $this->assertSame(count(ConsolidateMigrations::BASELINE) + count(ConsolidateMigrations::MERGED) + count($this->followups()), DB::table('migrations')->count());
        $this->assertCount(0, Storage::disk('local')->allFiles('migration-history'));
        $this->artisan('svil:consolidate-migrations', ['--apply' => true])->assertSuccessful();

        $this->assertSame(count(ConsolidateMigrations::BASELINE) + count($this->followups()), DB::table('migrations')->count());
        $this->assertSame([1], DB::table('migrations')->whereIn('migration', ConsolidateMigrations::BASELINE)->distinct()->pluck('batch')->all());
        $this->assertSame($schemaBefore, $this->schema());
        $this->assertSame($dataBefore, $this->data());
        $this->assertSame($note->content, $note->fresh()->content);
        $this->assertSame($material->attachment_path, $material->fresh()->attachment_path);
        $this->assertSame($student->getRawOriginal('password'), $student->fresh()->getRawOriginal('password'));
        $files = Storage::disk('local')->allFiles('migration-history');
        $this->assertCount(1, $files);
        $backup = json_decode(Storage::disk('local')->get($files[0]), true, flags: JSON_THROW_ON_ERROR);
        $this->assertEquals(json_decode(json_encode($historyBefore), true), $backup['rows']);

        $this->artisan('svil:consolidate-migrations', ['--apply' => true])->assertSuccessful();
        $this->assertCount(1, Storage::disk('local')->allFiles('migration-history'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertSuccessful();
        $this->assertSame($dataBefore, $this->data());
    }

    public function test_partial_history_and_schema_drift_are_rejected_without_metadata_changes(): void
    {
        Storage::fake('local');
        $this->legacyHistory();
        DB::table('migrations')->where('migration', ConsolidateMigrations::MERGED[0])->delete();
        $before = DB::table('migrations')->orderBy('id')->get()->toArray();
        $this->artisan('svil:consolidate-migrations', ['--apply' => true])->assertFailed();
        $this->assertEquals($before, DB::table('migrations')->orderBy('id')->get()->toArray());

        $this->legacyHistory();
        Schema::table('materials', fn (Blueprint $table) => $table->dropColumn('topic_label'));
        $this->artisan('svil:consolidate-migrations', ['--apply' => true])->assertFailed();
        $this->assertSame(count(ConsolidateMigrations::BASELINE) + count(ConsolidateMigrations::MERGED) + count($this->followups()), DB::table('migrations')->count());
        $this->assertCount(0, Storage::disk('local')->allFiles('migration-history'));
    }

    private function legacyHistory(): void
    {
        DB::table('migrations')->delete();
        foreach ([...ConsolidateMigrations::BASELINE, ...ConsolidateMigrations::MERGED, ...$this->followups()] as $index => $name) {
            DB::table('migrations')->insert(['migration' => $name, 'batch' => $index + 1]);
        }
    }

    private function followups(): array
    {
        return array_values(array_diff(array_keys($this->app['migrator']->getMigrationFiles(database_path('migrations'))), ConsolidateMigrations::BASELINE));
    }

    private function schema(): array
    {
        $result = [];
        foreach (Schema::getTableListing() as $table) {
            $columns = Schema::getColumns($table);
            usort($columns, fn (array $left, array $right): int => $left['name'] <=> $right['name']);
            $result[$table] = ['columns' => $columns, 'indexes' => Schema::getIndexes($table), 'foreign_keys' => Schema::getForeignKeys($table)];
        }
        ksort($result);

        return $result;
    }

    private function data(): array
    {
        $result = [];
        foreach (['users', 'schools', 'classrooms', 'classroom_user', 'learning_notes', 'materials'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        }

        return $result;
    }
}
