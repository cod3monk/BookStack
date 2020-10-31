<?php namespace Tests\Uploads;

use BookStack\Uploads\Attachment;
use BookStack\Entities\Page;
use BookStack\Auth\Permissions\PermissionService;
use BookStack\Uploads\AttachmentService;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use Tests\TestResponse;

class AttachmentTest extends TestCase
{
    /**
     * Get a test file that can be uploaded
     */
    protected function getTestFile(string $fileName): UploadedFile
    {
        return new UploadedFile(base_path('tests/test-data/test-file.txt'), $fileName, 'text/plain', 55, null, true);
    }

    /**
     * Uploads a file with the given name.
     */
    protected function uploadFile(string $name, int $uploadedTo = 0): \Illuminate\Foundation\Testing\TestResponse
    {
        $file = $this->getTestFile($name);
        return $this->call('POST', '/attachments/upload', ['uploaded_to' => $uploadedTo], [], ['file' => $file], []);
    }

    /**
     * Create a new attachment
     */
    protected function createAttachment(Page $page): Attachment
    {
        $this->post('attachments/link', [
            'attachment_link_url' => 'https://example.com',
            'attachment_link_name' => 'Example Attachment Link',
            'attachment_link_uploaded_to' => $page->id,
        ]);

        return Attachment::query()->latest()->first();
    }

    /**
     * Delete all uploaded files.
     * To assist with cleanup.
     */
    protected function deleteUploads()
    {
        $fileService = $this->app->make(AttachmentService::class);
        foreach (Attachment::all() as $file) {
            $fileService->deleteFile($file);
        }
    }

    public function test_file_upload()
    {
        $page = Page::first();
        $this->asAdmin();
        $admin = $this->getAdmin();
        $fileName = 'upload_test_file.txt';

        $expectedResp = [
            'name' => $fileName,
            'uploaded_to'=> $page->id,
            'extension' => 'txt',
            'order' => 1,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ];

        $upload = $this->uploadFile($fileName, $page->id);
        $upload->assertStatus(200);

        $attachment = Attachment::query()->orderBy('id', 'desc')->first();
        $expectedResp['path'] = $attachment->path;

        $upload->assertJson($expectedResp);
        $this->assertDatabaseHas('attachments', $expectedResp);

        $this->deleteUploads();
    }

    public function test_file_upload_does_not_use_filename()
    {
        $page = Page::first();
        $fileName = 'upload_test_file.txt';


        $upload = $this->asAdmin()->uploadFile($fileName, $page->id);
        $upload->assertStatus(200);

        $attachment = Attachment::query()->orderBy('id', 'desc')->first();
        $this->assertStringNotContainsString($fileName, $attachment->path);
        $this->assertStringEndsWith('.txt', $attachment->path);
    }

    public function test_file_display_and_access()
    {
        $page = Page::first();
        $this->asAdmin();
        $fileName = 'upload_test_file.txt';

        $upload = $this->uploadFile($fileName, $page->id);
        $upload->assertStatus(200);
        $attachment = Attachment::orderBy('id', 'desc')->take(1)->first();

        $pageGet = $this->get($page->getUrl());
        $pageGet->assertSeeText($fileName);
        $pageGet->assertSee($attachment->getUrl());

        $attachmentGet = $this->get($attachment->getUrl());
        $attachmentGet->assertSee('Hi, This is a test file for testing the upload process.');

        $this->deleteUploads();
    }

    public function test_attaching_link_to_page()
    {
        $page = Page::first();
        $admin = $this->getAdmin();
        $this->asAdmin();

        $linkReq = $this->call('POST', 'attachments/link', [
            'attachment_link_url' => 'https://example.com',
            'attachment_link_name' => 'Example Attachment Link',
            'attachment_link_uploaded_to' => $page->id,
        ]);

        $expectedData = [
            'path' => 'https://example.com',
            'name' => 'Example Attachment Link',
            'uploaded_to' => $page->id,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'external' => true,
            'order' => 1,
            'extension' => ''
        ];

        $linkReq->assertStatus(200);
        $this->assertDatabaseHas('attachments', $expectedData);
        $attachment = Attachment::orderBy('id', 'desc')->take(1)->first();

        $pageGet = $this->get($page->getUrl());
        $pageGet->assertSeeText('Example Attachment Link');
        $pageGet->assertSee($attachment->getUrl());

        $attachmentGet = $this->get($attachment->getUrl());
        $attachmentGet->assertRedirect('https://example.com');

        $this->deleteUploads();
    }

    public function test_attachment_updating()
    {
        $page = Page::first();
        $this->asAdmin();

        $attachment = $this->createAttachment($page);
        $update = $this->call('PUT', 'attachments/' . $attachment->id, [
            'attachment_edit_name' => 'My new attachment name',
            'attachment_edit_url' => 'https://test.example.com'
        ]);

        $expectedData = [
            'id' => $attachment->id,
            'path' => 'https://test.example.com',
            'name' => 'My new attachment name',
            'uploaded_to' => $page->id
        ];

        $update->assertStatus(200);
        $this->assertDatabaseHas('attachments', $expectedData);

        $this->deleteUploads();
    }

    public function test_file_deletion()
    {
        $page = Page::first();
        $this->asAdmin();
        $fileName = 'deletion_test.txt';
        $this->uploadFile($fileName, $page->id);

        $attachment = Attachment::query()->orderBy('id', 'desc')->first();
        $filePath = storage_path($attachment->path);
        $this->assertTrue(file_exists($filePath), 'File at path ' . $filePath . ' does not exist');

        $attachment = Attachment::first();
        $this->delete($attachment->getUrl());

        $this->assertDatabaseMissing('attachments', [
            'name' => $fileName
        ]);
        $this->assertFalse(file_exists($filePath), 'File at path ' . $filePath . ' was not deleted as expected');

        $this->deleteUploads();
    }

    public function test_attachment_deletion_on_page_deletion()
    {
        $page = Page::first();
        $this->asAdmin();
        $fileName = 'deletion_test.txt';
        $this->uploadFile($fileName, $page->id);

        $attachment = Attachment::query()->orderBy('id', 'desc')->first();
        $filePath = storage_path($attachment->path);

        $this->assertTrue(file_exists($filePath), 'File at path ' . $filePath . ' does not exist');
        $this->assertDatabaseHas('attachments', [
            'name' => $fileName
        ]);

        $this->call('DELETE', $page->getUrl());

        $this->assertDatabaseMissing('attachments', [
            'name' => $fileName
        ]);
        $this->assertFalse(file_exists($filePath), 'File at path ' . $filePath . ' was not deleted as expected');

        $this->deleteUploads();
    }

    public function test_attachment_access_without_permission_shows_404()
    {
        $admin = $this->getAdmin();
        $viewer = $this->getViewer();
        $page = Page::first(); /** @var Page $page */

        $this->actingAs($admin);
        $fileName = 'permission_test.txt';
        $this->uploadFile($fileName, $page->id);
        $attachment = Attachment::orderBy('id', 'desc')->take(1)->first();

        $page->restricted = true;
        $page->permissions()->delete();
        $page->save();
        $page->rebuildPermissions();
        $page->load('jointPermissions');

        $this->actingAs($viewer);
        $attachmentGet = $this->get($attachment->getUrl());
        $attachmentGet->assertStatus(404);
        $attachmentGet->assertSee("Attachment not found");

        $this->deleteUploads();
    }

    public function test_data_and_js_links_cannot_be_attached_to_a_page()
    {
        $page = Page::first();
        $this->asAdmin();

        $badLinks = [
            'javascript:alert("bunny")',
            ' javascript:alert("bunny")',
            'JavaScript:alert("bunny")',
            "\t\n\t\nJavaScript:alert(\"bunny\")",
            "data:text/html;<a></a>",
            "Data:text/html;<a></a>",
            "Data:text/html;<a></a>",
        ];

        foreach ($badLinks as $badLink) {
            $linkReq = $this->post('attachments/link', [
                'attachment_link_url' => $badLink,
                'attachment_link_name' => 'Example Attachment Link',
                'attachment_link_uploaded_to' => $page->id,
            ]);
            $linkReq->assertStatus(422);
            $this->assertDatabaseMissing('attachments', [
                'path' => $badLink,
            ]);
        }

        $attachment = $this->createAttachment($page);

        foreach ($badLinks as $badLink) {
            $linkReq = $this->put('attachments/' . $attachment->id, [
                'attachment_edit_url' => $badLink,
                'attachment_edit_name' => 'Example Attachment Link',
            ]);
            $linkReq->assertStatus(422);
            $this->assertDatabaseMissing('attachments', [
                'path' => $badLink,
            ]);
        }
    }
}
