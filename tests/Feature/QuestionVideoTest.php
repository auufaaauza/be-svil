<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QuestionVideoTest extends TestCase
{
    use RefreshDatabase;

    private function endpoint(string $url): string
    {
        return '/api/v1/learning-videos/resolve?url='.rawurlencode($url);
    }

    public function test_short_links_resolve_to_a_player_id_and_are_cached_without_changing_the_original_content(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://vt.tiktok.com/ZExample/' => Http::response('', 301, ['Location' => 'https://vm.tiktok.com/Second/']), 'https://vm.tiktok.com/Second/' => Http::response('', 302, ['Location' => 'https://www.tiktok.com/@guru/video/6718335390845095173?share=1'])]);
        $this->actingAs(User::factory()->create());
        $endpoint = $this->endpoint('https://vt.tiktok.com/ZExample/');
        $this->getJson($endpoint)->assertOk()->assertJsonPath('data.provider', 'tiktok')->assertJsonPath('data.id', '6718335390845095173')->assertJsonPath('data.url', 'https://www.tiktok.com/@guru/video/6718335390845095173');
        $this->getJson($endpoint)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'HEAD' && $request->url() === 'https://vt.tiktok.com/ZExample/');
    }

    public function test_tiktok_app_share_path_supports_a_relative_redirect(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://www.tiktok.com/t/ZExample/' => Http::response('', 301, ['Location' => '/@guru/video/6718335390845095173'])]);
        $this->actingAs(User::factory()->create())->getJson($this->endpoint('https://www.tiktok.com/t/ZExample/'))->assertOk()->assertJsonPath('data.id', '6718335390845095173');
        Http::assertSentCount(1);
    }

    public function test_redirects_to_private_or_unrelated_hosts_are_never_followed(): void
    {
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());
        $responses = [];
        foreach (['http://127.0.0.1/admin', 'https://user:secret@www.tiktok.com/@guru/video/6718335390845095173', 'https://www.tiktok.com.attacker.test/@guru/video/6718335390845095173', 'https://localhost/@guru/video/6718335390845095173'] as $index => $redirect) {
            $short = 'https://vt.tiktok.com/Unsafe'.$index.'/';
            $responses[$short] = Http::response('', 302, ['Location' => $redirect]);
        }
        Http::fake($responses);
        foreach (array_keys($responses) as $short) {
            $this->getJson($this->endpoint($short))->assertUnprocessable();
        }
        Http::assertSentCount(4);
    }

    public function test_removed_videos_and_connection_failures_provide_a_readable_error(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://vt.tiktok.com/Removed/' => Http::response('', 404), 'https://vt.tiktok.com/Offline/' => Http::failedConnection()]);
        $this->actingAs(User::factory()->create());
        foreach (['Removed', 'Offline'] as $token) {
            $this->getJson($this->endpoint('https://vt.tiktok.com/'.$token.'/'))->assertUnprocessable()->assertJsonPath('message', 'Video belum bisa diputar di sini. Coba lagi nanti atau minta guru memeriksa tautannya.');
        }
    }

    public function test_known_player_links_need_no_lookup_and_other_sites_are_not_proxied(): void
    {
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());
        $this->getJson($this->endpoint('https://youtu.be/abcdefghijk'))->assertOk()->assertJsonPath('data.provider', 'youtube');
        $this->getJson($this->endpoint('https://www.tiktok.com/@guru/video/6718335390845095173'))->assertOk()->assertJsonPath('data.provider', 'tiktok');
        foreach (['https://example.test/video', 'https://vt.tiktok.com.attacker.test/ZExample/', 'http://vt.tiktok.com/ZExample/', 'https://127.0.0.1/video'] as $url) {
            $this->getJson($this->endpoint($url))->assertUnprocessable();
        }
        Http::assertNothingSent();
    }

    public function test_only_signed_in_users_can_resolve_video_links(): void
    {
        Http::preventStrayRequests();
        $this->getJson($this->endpoint('https://vt.tiktok.com/ZExample/'))->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_three_unique_games_are_assigned_to_the_correct_lkpd_and_require_class_access(): void
    {
        $class = Classroom::factory()->create();
        $student = User::factory()->create(['school_id' => $class->school_id]);
        $endpoint = '/api/v1/learning-games?classroom_id='.$class->id;
        $this->actingAs($student)->getJson($endpoint)->assertForbidden();
        $class->users()->attach($student);
        $data = $this->getJson($endpoint)->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.topic', 1)->assertJsonPath('data.1.topic', 2)->assertJsonPath('data.2.topic', 3)->json('data');
        $this->assertCount(3, array_unique(array_column($data, 'id')));
        foreach ($data as $game) {
            $this->assertMatchesRegularExpression('~^https://wordwall\.net/(?:id/)?embed/[a-f0-9]{32}\?~', $game['embed_url']);
        }
        $this->assertDatabaseCount('assessment_attempts', 0);
    }
}
