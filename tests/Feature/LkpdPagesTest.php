<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\LearningGroup;
use App\Models\LearningModule;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LkpdPagesTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $class = Classroom::factory()->create();
        $student = User::factory()->create(['school_id' => $class->school_id]);
        $teacher = User::factory()->create(['school_id' => $class->school_id, 'role' => Role::Teacher]);
        $class->users()->attach([$student->id, $teacher->id]);
        $group = LearningGroup::factory()->create(['classroom_id' => $class->id]);
        $group->users()->attach($student);
        $fields = array_map(fn (int $i): array => ['key' => 'field_'.$i, 'label' => 'Pertanyaan '.($i + 1), 'hint' => 'Ceritakan hasil pengamatan.', 'phase' => $i % 5], range(0, 7));
        LearningModule::factory()->create(['topic' => 0, 'fields' => $fields]);

        return [$class, $student, $teacher, $group, '/api/v1/groups/'.$group->id.'/work/0'];
    }

    public function test_each_sheet_can_be_sent_and_reviewed_without_marking_the_whole_lkpd_complete(): void
    {
        [$class,$student,$teacher,$group,$url] = $this->context();
        $first = array_fill_keys(['field_0', 'field_1', 'field_2', 'field_3'], 'Jawaban lembar satu.');
        $row = $this->actingAs($student)->putJson($url, ['version' => 0, 'status' => 'submitted', 'page' => 0, 'answers' => $first])->assertOk()->assertJsonPath('data.status', 'partially_submitted')->assertJsonPath('data.pages_submitted', 1)->assertJsonPath('data.pages_total', 2)->json('data');
        $review = '/api/v1/group-work/'.$row['id'].'/review';
        $this->actingAs($teacher)->putJson($review, ['version' => 1, 'status' => 'reviewed', 'page' => 1, 'feedback' => 'Baik.'])->assertUnprocessable();
        $this->putJson($review, ['version' => 1, 'status' => 'reviewed', 'page' => 0, 'feedback' => 'Lembar pertama sudah baik.'])->assertOk()->assertJsonPath('data.status', 'partially_submitted')->assertJsonPath('data.page_submissions.0.review_status', 'reviewed');
        $second = array_fill_keys(['field_4', 'field_5', 'field_6', 'field_7'], 'Jawaban lembar dua.');
        $this->actingAs($student)->putJson($url, ['version' => 2, 'status' => 'draft', 'page' => 1, 'answers' => $second])->assertOk()->assertJsonPath('data.answers.field_0', 'Jawaban lembar satu.')->assertJsonPath('data.page_submissions.0.review_status', 'reviewed');
        $this->putJson($url, ['version' => 3, 'status' => 'submitted', 'page' => 1, 'answers' => $second])->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.pages_submitted', 2);
        $this->actingAs($teacher)->putJson($review, ['version' => 4, 'status' => 'reviewed', 'page' => 1, 'feedback' => 'Lembar kedua sudah baik.'])->assertOk()->assertJsonPath('data.status', 'reviewed');
        $first['field_0'] = 'Jawaban diperbarui.';
        $this->actingAs($student)->putJson($url, ['version' => 5, 'status' => 'draft', 'page' => 0, 'answers' => $first])->assertOk()->assertJsonPath('data.pages_submitted', 1)->assertJsonPath('data.page_submissions.1.review_status', 'reviewed')->assertJsonPath('data.answers.field_4', 'Jawaban lembar dua.');
        $this->putJson($url, ['version' => 5, 'status' => 'draft', 'page' => 0, 'answers' => $first])->assertConflict();
    }

    public function test_a_page_cannot_modify_another_page_and_legacy_full_submission_remains_validated(): void
    {
        [$class,$student,$teacher,$group,$url] = $this->context();
        $answers = array_fill_keys(['field_0', 'field_1', 'field_2', 'field_3'], 'Jawaban.');
        $this->actingAs($student)->putJson($url, ['version' => 0, 'status' => 'submitted', 'page' => 0, 'answers' => [...$answers, 'field_4' => 'Tidak boleh menyisipkan lembar lain.']])->assertUnprocessable();
        $this->putJson($url, ['version' => 0, 'status' => 'submitted', 'answers' => $answers])->assertUnprocessable();
        $all = array_fill_keys(array_map(fn (int $i): string => 'field_'.$i, range(0, 7)), 'Jawaban seluruh lembar.');
        $this->putJson($url, ['version' => 0, 'status' => 'submitted', 'answers' => $all])->assertOk()->assertJsonPath('data.pages_submitted', 2)->assertJsonPath('data.status', 'submitted');
    }
}
