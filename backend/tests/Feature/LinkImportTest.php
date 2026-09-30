<?php

namespace Tests\Feature;

use App\Models\Link;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class LinkImportTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('links.csv', $contents);
    }

    public function test_it_imports_rows_with_and_without_short_codes(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();

        $response = $this->actingAs($user, 'sanctum')->post(
            "/api/sites/{$site->id}/links/import",
            ['file' => $this->csv(
                "target_url,short_code\n".
                "https://example.com/one,one\n".
                "https://example.com/two,\n"
            )]
        );

        $response->assertOk()->assertJsonPath('imported', 2);
        $this->assertDatabaseHas('links', ['short_code' => 'one', 'site_id' => $site->id]);
        // The blank code should have been auto-generated, not left empty.
        $this->assertNotEmpty(Link::where('target_url', 'https://example.com/two')->first()->short_code);
    }

    public function test_it_skips_invalid_rows_and_reports_why(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();

        $response = $this->actingAs($user, 'sanctum')->post(
            "/api/sites/{$site->id}/links/import",
            ['file' => $this->csv(
                "target_url,short_code\n".
                "https://example.com/ok,\n".
                "not-a-url,\n"
            )]
        );

        $response->assertOk()
            ->assertJsonPath('imported', 1)
            ->assertJsonPath('skipped.0.row', 3);
    }

    public function test_it_rejects_a_file_without_the_target_url_header(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();

        $response = $this->actingAs($user, 'sanctum')->post(
            "/api/sites/{$site->id}/links/import",
            ['file' => $this->csv("url,code\nhttps://example.com,x\n")]
        );

        $response->assertOk()->assertJsonPath('imported', 0);
        $this->assertDatabaseCount('links', 0);
    }

    public function test_it_skips_a_code_already_taken_in_the_database(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();
        Link::factory()->for($site)->create(['short_code' => 'taken']);

        $response = $this->actingAs($user, 'sanctum')->post(
            "/api/sites/{$site->id}/links/import",
            ['file' => $this->csv("target_url,short_code\nhttps://example.com/x,taken\n")]
        );

        $response->assertOk()->assertJsonPath('imported', 0);
    }

    public function test_it_skips_a_code_duplicated_within_the_same_file(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();

        $response = $this->actingAs($user, 'sanctum')->post(
            "/api/sites/{$site->id}/links/import",
            ['file' => $this->csv(
                "target_url,short_code\n".
                "https://example.com/a,dupe\n".
                "https://example.com/b,dupe\n"
            )]
        );

        $response->assertOk()->assertJsonPath('imported', 1);
        $this->assertDatabaseCount('links', 1);
    }

    public function test_demo_user_cannot_import(): void
    {
        $demo = User::factory()->create(['is_demo' => true]);
        $site = Site::factory()->ownedBy($demo)->create();

        $this->actingAs($demo, 'sanctum')
            ->post("/api/sites/{$site->id}/links/import", [
                'file' => $this->csv("target_url\nhttps://example.com/x\n"),
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('links', 0);
    }

    public function test_user_cannot_import_into_another_users_site(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $site = Site::factory()->ownedBy($owner)->create();

        $this->actingAs($other, 'sanctum')
            ->post("/api/sites/{$site->id}/links/import", [
                'file' => $this->csv("target_url\nhttps://example.com/x\n"),
            ])
            ->assertForbidden();
    }
}
