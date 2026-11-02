<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class DocumentPreviewTest extends TestCase
{
    use RefreshDatabase;

    private Classroom $classroom;

    private User $teacher;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->classroom = Classroom::factory()->create();
        $this->teacher = User::factory()->create(['role' => Role::Teacher, 'school_id' => $this->classroom->school_id]);
        $this->student = User::factory()->create(['school_id' => $this->classroom->school_id]);
        $this->classroom->users()->attach([$this->teacher->id, $this->student->id]);
    }

    private function payload(): array
    {
        return ['classroom_id' => $this->classroom->id, 'title' => 'Bahan pembelajaran', 'content' => 'Baca bersama guru.', 'topic' => null, 'topic_label' => 'Pertemuan pecahan', 'subject' => 'Matematika', 'kind' => 'material', 'status' => 'published'];
    }

    private function pptx(bool $evil = false): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'svil-pptx-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/></Types>');
        $zip->addFromString('ppt/presentation.xml', ($evil ? '<!DOCTYPE p:presentation [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>' : '').'<p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><p:sldIdLst><p:sldId id="256" r:id="rId1"/></p:sldIdLst><p:sldSz cx="9144000" cy="6858000"/></p:presentation>');
        $zip->addFromString('ppt/_rels/presentation.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/></Relationships>');
        $zip->addFromString('ppt/slides/slide1.xml', '<p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><p:cSld><p:spTree><p:sp><p:spPr><a:xfrm><a:off x="200000" y="200000"/><a:ext cx="8000000" cy="1500000"/></a:xfrm><a:solidFill><a:srgbClr val="EEF5ED"/></a:solidFill></p:spPr><p:txBody><a:bodyPr/><a:p><a:r><a:rPr sz="3000" b="1"/><a:t>Belajar pecahan bersama</a:t></a:r></a:p></p:txBody></p:sp><p:pic><p:blipFill><a:blip r:embed="rIdImage"/></p:blipFill><p:spPr><a:xfrm><a:off x="1000000" y="2000000"/><a:ext cx="1000000" cy="1000000"/></a:xfrm></p:spPr></p:pic></p:spTree></p:cSld></p:sld>');
        $zip->addFromString('ppt/slides/_rels/slide1.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdImage" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/pixel.png"/></Relationships>');
        $zip->addFromString('ppt/media/pixel.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent('bahan.pptx', $bytes);
    }

    public function test_pdf_is_embedded_inline_and_still_requires_class_access(): void
    {
        $result = $this->actingAs($this->teacher)->post('/api/v1/materials', [...$this->payload(), 'attachment' => UploadedFile::fake()->createWithContent('bahan.pdf', "%PDF-1.4\n%%EOF")], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.topic', null)->assertJsonPath('data.topicLabel', 'Pertemuan pecahan');
        $url = $result->json('data.attachment.previewUrl');
        $this->assertNotNull($url);
        $response = $this->actingAs($this->student)->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
        $this->actingAs(User::factory()->create())->getJson($url)->assertForbidden();
    }

    public function test_pptx_text_and_images_are_read_inside_a_private_slide_viewer(): void
    {
        $result = $this->actingAs($this->teacher)->post('/api/v1/materials', [...$this->payload(), 'attachment' => $this->pptx()], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.attachment.mime', 'application/vnd.openxmlformats-officedocument.presentationml.presentation');
        $url = $result->json('data.attachment.slidesUrl');
        $this->assertNotNull($url);
        $slides = $this->actingAs($this->student)->getJson($url)->assertOk()->assertJsonCount(1, 'data.slides')->assertJsonPath('data.slides.0.elements.1.paragraphs.0.runs.0.text', 'Belajar pecahan bersama');
        $asset = $slides->json('data.slides.0.elements.2.url');
        $this->get($asset)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs(User::factory()->create())->getJson($url)->assertForbidden();
        $this->getJson($asset)->assertForbidden();
    }

    public function test_unsafe_or_non_presentation_archives_are_rejected_before_storage(): void
    {
        $this->actingAs($this->teacher)->post('/api/v1/materials', [...$this->payload(), 'attachment' => $this->pptx(true)], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/v1/materials', [...$this->payload(), 'attachment' => UploadedFile::fake()->createWithContent('bad.pptx', 'not a presentation')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('materials',0);
    }
}
