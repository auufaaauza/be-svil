<?php

namespace Tests\Feature;

use App\Models\ActionJournal;
use App\Models\ActionPlan;
use App\Models\Classroom;
use App\Models\GroupWork;
use App\Models\LearningGroup;
use App\Models\LearningNote;
use App\Models\Material;
use App\Models\MaterialAttempt;
use App\Models\Observation;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_report_exports_real_scoped_data_and_separate_scores(): void
    {
        $classroom = Classroom::factory()->create();
        $teacher = User::factory()->create(['role' => Role::Teacher, 'school_id' => $classroom->school_id]);
        $student = User::factory()->create(['school_id' => $classroom->school_id]);
        $classroom->users()->attach([$teacher->id, $student->id]);
        $group = LearningGroup::factory()->create(['classroom_id' => $classroom->id]);
        $group->users()->attach($student);
        $work = GroupWork::factory()->create(['learning_group_id' => $group->id, 'topic' => 3, 'status' => 'reviewed', 'answers' => ['facts' => 'FAKTA_TERIZIN'], 'product_scores' => ['accuracy' => 4, 'analysis' => 3, 'creativity' => 4, 'message' => 3], 'presentation_scores' => ['mastery' => 3, 'communication' => 4]]);
        $work->recordRevision($teacher);
        $plan = ActionPlan::factory()->create(['learning_group_id' => $group->id]);
        ActionJournal::factory()->create(['action_plan_id' => $plan->id, 'student_id' => $student->id, 'plan_snapshot' => $plan->toArray()]);
        $material = Material::factory()->create(['classroom_id' => $classroom->id, 'teacher_id' => $teacher->id]);
        MaterialAttempt::factory()->create(['material_id' => $material->id, 'student_id' => $student->id, 'correct' => null]);
        Observation::factory()->create(['classroom_id' => $classroom->id, 'student_id' => $student->id, 'teacher_id' => $teacher->id]);
        GroupWork::factory()->create(['answers' => ['facts' => 'RAHASIA_KELAS_LAIN']]);
        $this->actingAs($teacher)->getJson('/api/v1/reports/summary')->assertOk()
            ->assertJsonPath('data.submittedWorks', 1)->assertJsonPath('data.actionJournals', 1)->assertJsonPath('data.pendingPracticeReviews', 1)->assertJsonPath('data.observations', 1)->assertJsonPath('data.researchInstrumentsReady', false);
        $csv = $this->get('/api/v1/reports/learning.csv')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->streamedContent();
        foreach (['FAKTA_TERIZIN', 'LKPD_RIWAYAT', 'RENCANA_AKSI', 'JURNAL_AKSI', 'LATIHAN', 'OBSERVASI', 'product_scores', 'presentation_scores', 'P-'.$student->id] as $value) {
            $this->assertStringContainsString($value, $csv);
        }
        $this->assertStringNotContainsString('RAHASIA_KELAS_LAIN', $csv);
        $this->assertStringNotContainsString($student->email, $csv);
        $this->actingAs($student)->get('/api/v1/reports/learning.csv')->assertForbidden();
    }

    public function test_notes_export_neutralizes_spreadsheet_formulas(): void
    {
        $classroom = Classroom::factory()->create();
        $teacher = User::factory()->create(['role' => Role::Teacher, 'school_id' => $classroom->school_id]);
        $classroom->users()->attach($teacher);
        LearningNote::factory()->create(['classroom_id' => $classroom->id, 'content' => '=HYPERLINK("test")', 'feedback' => '+malicious']);
        $csv = $this->actingAs($teacher)->get('/api/v1/reports/notes.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'+malicious", $csv);
    }
}
