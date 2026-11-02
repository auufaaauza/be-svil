<?php

namespace Tests\Feature;

use App\Learning\LkpdSchema;
use App\Models\ActionPlan;
use App\Models\Classroom;
use App\Models\LearningGroup;
use App\Models\LearningModule;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class GroupLearningTest extends TestCase
{
    use RefreshDatabase;

    private Classroom $classroom;

    private LearningGroup $group;

    private User $student;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (range(0, 3) as $topic) {
            LearningModule::factory()->create(['topic' => $topic, 'fields' => LkpdSchema::legacyFields($topic)]);
        }
        $this->classroom = Classroom::factory()->create();
        $this->group = LearningGroup::factory()->create(['classroom_id' => $this->classroom->id]);
        $this->student = User::factory()->create(['school_id' => $this->classroom->school_id]);
        $this->teacher = User::factory()->create(['school_id' => $this->classroom->school_id, 'role' => Role::Teacher]);
        $this->classroom->users()->attach([$this->student->id, $this->teacher->id]);
        $this->group->users()->attach($this->student);
    }

    private function answers(int $topic): array
    {
        return array_fill_keys(array_column(LkpdSchema::legacyFields($topic), 'key'), 'Fakta, sumber, dan keputusan kelompok.');
    }

    private function planPayload(int $version = 0): array
    {
        return ['version' => $version, 'title' => 'Merawat tanaman', 'start_date' => today()->format('Y-m-d'), 'location' => 'Halaman sekolah', 'indicator' => 'Tanaman disiram secukupnya selama satu minggu', 'steps' => 'Siapkan air secukupnya, siram dengan pendampingan', 'responsibilities' => 'Anggota bergantian mencatat dan menyiram', 'evidence_method' => 'Jurnal harian dan checklist'];
    }

    public function test_only_members_and_assigned_teachers_can_read_group_work(): void
    {
        $outsider = User::factory()->create(['school_id' => $this->classroom->school_id]);
        $this->classroom->users()->attach($outsider);
        $url = '/api/v1/groups/'.$this->group->id.'/work/0';
        $this->actingAs($outsider)->getJson($url)->assertForbidden();
        $this->getJson('/api/v1/groups')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->student)->getJson('/api/v1/groups')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->student)->getJson($url)->assertOk()->assertJsonPath('data', null);
        $this->actingAs(User::factory()->create(['role' => Role::Teacher]))->getJson($url)->assertForbidden();
    }

    public function test_draft_submission_review_and_revision_keep_an_audit_trail(): void
    {
        $url = '/api/v1/groups/'.$this->group->id.'/work/0';
        $draft = $this->actingAs($this->student)->putJson($url, ['version' => 0, 'status' => 'draft', 'answers' => ['facts' => 'Pulau dan lautan']])->assertOk();
        $id = $draft->json('data.id');
        $this->putJson($url, ['version' => 1, 'status' => 'submitted', 'answers' => ['facts' => 'Belum lengkap']])->assertUnprocessable();
        $this->putJson($url, ['version' => 1, 'status' => 'submitted', 'answers' => $this->answers(0)])->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson($url, ['version' => 1, 'status' => 'draft', 'answers' => ['facts' => 'Stale']])->assertConflict();
        $this->actingAs($this->teacher)->putJson('/api/v1/group-work/'.$id.'/review', ['version' => 2, 'status' => 'needs_revision', 'feedback' => 'Tambahkan sumber petamu.'])->assertOk()->assertJsonPath('data.version', 3);
        $this->actingAs($this->student)->putJson($url, ['version' => 3, 'status' => 'submitted', 'answers' => $this->answers(0)])->assertOk()->assertJsonPath('data.feedback', null);
        $this->assertSame(4, DB::table('group_work_revisions')->where('group_work_id', $id)->count());
        $snapshot = json_decode(DB::table('group_work_revisions')->where('group_work_id', $id)->where('version', 3)->value('snapshot'), true);
        $this->assertSame('Tambahkan sumber petamu.', $snapshot['feedback']);
        $this->assertSame([$this->student->id], $snapshot['member_ids']);
    }

    public function test_project_and_presentation_rubrics_are_separate_and_bounded(): void
    {
        $url = '/api/v1/groups/'.$this->group->id.'/work/3';
        $id = $this->actingAs($this->student)->putJson($url, ['version' => 0, 'status' => 'submitted', 'answers' => $this->answers(3)])->assertOk()->json('data.id');
        $scores = ['version' => 1, 'status' => 'reviewed', 'feedback' => 'Fakta dan ajakan sudah jelas.', 'product_scores' => ['accuracy' => 4, 'analysis' => 3, 'creativity' => 4, 'message' => 3], 'presentation_scores' => ['mastery' => 3, 'communication' => 4]];
        $this->actingAs($this->teacher)->putJson('/api/v1/group-work/'.$id.'/review', $scores)->assertOk()->assertJsonPath('data.product_value', 87.5)->assertJsonPath('data.presentation_value', 87.5);
        $scores['version'] = 2;
        $scores['product_scores']['accuracy'] = 5;
        $this->putJson('/api/v1/group-work/'.$id.'/review', $scores)->assertUnprocessable();
        $this->putJson('/api/v1/group-work/'.$id.'/review', [...$scores, 'product_scores' => null, 'presentation_scores' => null])
            ->assertOk()->assertJsonPath('data.product_value', null)->assertJsonPath('data.presentation_value', null);
        $this->actingAs($this->student)->putJson('/api/v1/group-work/'.$id.'/review', $scores)->assertForbidden();
    }

    public function test_actions_require_real_journals_and_keep_snapshots_on_later_changes(): void
    {
        $url = '/api/v1/groups/'.$this->group->id.'/action';
        $id = $this->actingAs($this->student)->putJson($url, $this->planPayload())->assertOk()->json('data.id');
        $verify = '/api/v1/actions/'.$id.'/verify';
        $this->actingAs($this->teacher)->putJson($verify, ['version' => 1, 'feedback' => 'Belum ada bukti'])->assertUnprocessable();
        $journal = ['request_id' => (string) Str::uuid(), 'occurred_on' => today()->format('Y-m-d'), 'activity' => 'Menyiram tanaman', 'evidence' => 'Checklist penyiraman satu hari', 'obstacle' => ''];
        $this->actingAs($this->student)->postJson('/api/v1/actions/'.$id.'/journals', $journal)->assertOk();
        $this->postJson('/api/v1/actions/'.$id.'/journals', $journal)->assertOk();
        $this->assertSame(1, DB::table('action_journals')->count());
        $this->actingAs($this->teacher)->putJson($verify, ['version' => 1, 'feedback' => 'Stale'])->assertConflict();
        $this->putJson($verify, ['version' => 2, 'feedback' => 'Pelaksanaan diperiksa bersama.'])->assertOk();
        $this->assertNotNull(ActionPlan::find($id)->verified_at);
        $this->actingAs($this->student)->putJson($url, [...$this->planPayload(3), 'title' => 'Rencana perbaikan'])->assertOk()->assertJsonPath('data.verified_at', null);
        $snapshot = json_decode(DB::table('action_journals')->value('plan_snapshot'), true);
        $this->assertSame('Merawat tanaman', $snapshot['title']);
        $this->putJson($url, [...$this->planPayload(4), 'start_date' => today()->addDay()->format('Y-m-d')])->assertOk();
        $this->postJson('/api/v1/actions/'.$id.'/journals', $journal)->assertOk();
        $this->postJson('/api/v1/actions/'.$id.'/journals', [...$journal, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_other_groups_cannot_change_or_verify_an_action(): void
    {
        $id = $this->actingAs($this->student)->putJson('/api/v1/groups/'.$this->group->id.'/action', $this->planPayload())->assertOk()->json('data.id');
        $outsider = User::factory()->create(['school_id' => $this->classroom->school_id]);
        $this->classroom->users()->attach($outsider);
        $this->actingAs($outsider)->getJson('/api/v1/groups/'.$this->group->id.'/action')->assertForbidden();
        $this->putJson('/api/v1/actions/'.$id.'/verify', ['version' => 1, 'feedback' => 'Tidak boleh'])->assertForbidden();
        $this->putJson('/api/v1/groups/'.$this->group->id.'/action', $this->planPayload(1))->assertForbidden();
    }
}
