<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteTwoUser;
use Mockery;
use Tests\TestCase;

class LostAndFoundApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_login_enforces_widyatama_domain_and_logout(): void
    {
        User::factory()->create([
            'name' => 'Syifa Nur Aini',
            'email' => 'syifa.aini@widyatama.ac.id',
            'password' => 'password123',
            'whatsapp' => '081312123476',
        ]);

        // Register endpoint is removed (404)
        $this->postJson('/api/auth/register', [
            'name' => 'Test',
            'email' => 'test@widyatama.ac.id',
            'password' => 'password123',
            'whatsapp' => '08123456789',
        ])->assertStatus(404);

        // Non-widyatama email is rejected with 403
        $this->postJson('/api/auth/login', [
            'email' => 'syifa@gmail.com',
            'password' => 'password123',
        ])->assertStatus(403)
            ->assertJsonPath('success', false);

        // Valid @widyatama.ac.id email succeeds
        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => 'syifa.aini@widyatama.ac.id',
            'password' => 'password123',
        ]);

        $loginResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'syifa.aini@widyatama.ac.id');

        $token = $loginResponse->json('data.token');

        $logoutResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout');

        $logoutResponse->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_profile_show_and_update_with_avatar(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'name' => 'Old Name',
            'whatsapp' => '081111111111',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.name', 'Old Name');

        $avatar = UploadedFile::fake()->create('avatar.jpg', 100, 'image/jpeg');

        $updateResponse = $this->post('/api/auth/profile', [
            'name' => 'New Name',
            'whatsapp' => '089999999999',
            'avatar' => $avatar,
        ], ['Accept' => 'application/json']);

        $updateResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.whatsapp', '089999999999');

        $this->assertNotNull($updateResponse->json('data.avatar_url'));
    }

    public function test_post_crud_search_filter_and_status_update(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Sanctum::actingAs($user);

        $image1 = UploadedFile::fake()->create('tumbler-1.jpg', 120, 'image/jpeg');
        $image2 = UploadedFile::fake()->create('tumbler-2.png', 150, 'image/png');

        $storeResponse = $this->post('/api/posts', [
            'type' => 'kehilangan',
            'title' => 'Tumbler minum leopard',
            'category' => 'Lainnya',
            'location' => 'Foodcourt',
            'incident_date' => '2026-09-10',
            'description' => 'Dicari tumbler dengan motif leopard di gedung B',
            'images' => [$image1, $image2],
        ], ['Accept' => 'application/json']);

        $storeResponse->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Tumbler minum leopard')
            ->assertJsonCount(2, 'data.images');

        $postId = $storeResponse->json('data.id');

        // Search and filter in index
        $this->getJson('/api/posts?search=leopard&type=kehilangan&category=Lainnya')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $postId);

        $this->getJson('/api/posts?type=ditemukan')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        // Show post detail
        $this->getJson("/api/posts/{$postId}")
            ->assertOk()
            ->assertJsonPath('data.id', $postId);

        // Unauthorized user cannot update or delete
        Sanctum::actingAs($otherUser);
        $this->patchJson("/api/posts/{$postId}/status", ['status' => 'selesai'])
            ->assertStatus(403);
        $this->deleteJson("/api/posts/{$postId}")
            ->assertStatus(403);

        // Owner can update status to resolved or selesai
        Sanctum::actingAs($user);
        $this->patchJson("/api/posts/{$postId}", ['status' => 'resolved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');

        $this->patchJson("/api/posts/{$postId}/status", ['status' => 'selesai'])
            ->assertOk()
            ->assertJsonPath('data.status', 'selesai');

        // Owner can delete post
        $this->deleteJson("/api/posts/{$postId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('posts', ['id' => $postId]);
    }

    public function test_comment_store_reply_and_destroy(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $post = Post::create([
            'user_id' => $user->id,
            'type' => 'kehilangan',
            'status' => 'open',
            'title' => 'Kunci Motor Honda',
            'category' => 'Kunci',
            'location' => 'Parkiran Gedung A',
            'incident_date' => '2026-09-11',
            'description' => 'Kunci motor gantungan merah',
        ]);

        Sanctum::actingAs($user);

        // Store top-level comment
        $commentResponse = $this->postJson("/api/posts/{$post->id}/comments", [
            'content' => 'Di ujung sebelah mana nya kak?',
            'parent_id' => null,
        ]);

        $commentResponse->assertStatus(201)
            ->assertJsonPath('data.content', 'Di ujung sebelah mana nya kak?');

        $parentId = $commentResponse->json('data.id');

        // Store reply comment
        Sanctum::actingAs($otherUser);
        $replyResponse = $this->postJson("/api/posts/{$post->id}/comments", [
            'content' => 'Dekat pos satpam depan kak',
            'parent_id' => $parentId,
        ]);

        $replyResponse->assertStatus(201)
            ->assertJsonPath('data.parent_id', $parentId);

        $replyId = $replyResponse->json('data.id');

        // List comments on post includes root comment and flattened reply (plus nested replies)
        $this->getJson("/api/posts/{$post->id}/comments")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonCount(1, 'data.0.replies')
            ->assertJsonPath('data.1.isReply', true);

        // Post owner received notification for the reply from otherUser
        Sanctum::actingAs($user);
        $notifResponse = $this->getJson('/api/notifications');
        $notifResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.isRead', false);

        $notifId = $notifResponse->json('data.0.id');
        $this->patchJson("/api/notifications/{$notifId}/read")
            ->assertOk()
            ->assertJsonPath('data.isRead', true);

        $this->patchJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('success', true);

        // Non-owner cannot delete comment
        $this->deleteJson("/api/comments/{$replyId}")
            ->assertStatus(403);

        // Owner can delete comment
        Sanctum::actingAs($otherUser);
        $this->deleteJson("/api/comments/{$replyId}")
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_saved_post_toggle_and_index(): void
    {
        $user = User::factory()->create();

        $post = Post::create([
            'user_id' => $user->id,
            'type' => 'ditemukan',
            'status' => 'open',
            'title' => 'KTM Widyatama',
            'category' => 'Dokumen & Kartu',
            'location' => 'Perpustakaan',
            'incident_date' => '2026-09-12',
            'description' => 'Ditemukan KTM di meja baca lantai 2',
        ]);

        Sanctum::actingAs($user);

        // Toggle save (first call saves)
        $this->postJson("/api/posts/{$post->id}/bookmark")
            ->assertOk()
            ->assertJsonPath('data.is_saved', true);

        // Index saved posts
        $this->getJson('/api/posts/saved')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $post->id)
            ->assertJsonPath('data.0.is_saved', true);

        // Toggle save again (second call unsaves)
        $this->postJson("/api/posts/{$post->id}/bookmark")
            ->assertOk()
            ->assertJsonPath('data.is_saved', false);

        $this->getJson('/api/posts/saved')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_database_seeder_populates_dummy_data(): void
    {
        $this->seed();

        $this->assertDatabaseHas('users', ['email' => 'syifa.aini@widyatama.ac.id']);
        $this->assertDatabaseHas('users', ['email' => 'rony.parulian@widyatama.ac.id']);
        $this->assertDatabaseHas('users', ['email' => 'alyssa.putri@widyatama.ac.id']);
        $this->assertDatabaseHas('users', ['email' => 'agatha.chelsea@widyatama.ac.id']);

        $this->assertDatabaseCount('posts', 3);
        $this->assertDatabaseCount('post_images', 3);
        $this->assertDatabaseCount('comments', 3);
        $this->assertDatabaseCount('saved_posts', 1);
        $this->assertDatabaseCount('notifications', 2);

        $this->getJson('/api/posts')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    }

    public function test_manual_and_google_auth_share_the_same_widyatama_email_and_reject_non_widyatama(): void
    {
        // Case 1: Existing manual user (@widyatama.ac.id) logs in via Google with the same email
        $manualUser = User::factory()->create([
            'name' => 'Mahasiswa Manual',
            'email' => 'same.email@widyatama.ac.id',
            'password' => 'Password123!',
            'whatsapp' => '081234567890',
            'auth_provider' => 'manual',
        ]);

        $socialiteUser1 = (new SocialiteTwoUser)->map([
            'id' => 'google-sub-12345',
            'name' => 'Mahasiswa Manual Google',
            'email' => 'same.email@widyatama.ac.id',
            'avatar' => 'https://lh3.googleusercontent.com/a/default-user',
        ]);

        $providerMock1 = Mockery::mock(GoogleProvider::class);
        $providerMock1->shouldReceive('stateless')->andReturnSelf();
        $providerMock1->shouldReceive('userFromToken')
            ->with('real-google-jwt-or-access-token-1')
            ->andReturn($socialiteUser1);
        Socialite::shouldReceive('driver')->with('google')->once()->andReturn($providerMock1);

        $googleLogin = $this->postJson('/api/auth/google', [
            'credential' => 'real-google-jwt-or-access-token-1',
        ]);

        $googleLogin->assertOk()
            ->assertJsonPath('data.user.id', $manualUser->id)
            ->assertJsonPath('data.user.email', 'same.email@widyatama.ac.id')
            ->assertJsonPath('data.user.auth_provider', 'manual,google')
            ->assertJsonPath('data.user.has_password', true);

        // Still able to login manually with the original password
        $this->postJson('/api/auth/login', [
            'email' => 'same.email@widyatama.ac.id',
            'password' => 'Password123!',
        ])->assertOk()->assertJsonPath('data.user.id', $manualUser->id);

        // Case 2: Non-@widyatama.ac.id Google account is rejected with 403
        $nonCampusGoogleUser = (new SocialiteTwoUser)->map([
            'id' => 'google-sub-gmail',
            'name' => 'Outsider Gmail',
            'email' => 'outsider@gmail.com',
            'avatar' => 'https://lh3.googleusercontent.com/a/outsider',
        ]);

        $providerMock2 = Mockery::mock(GoogleProvider::class);
        $providerMock2->shouldReceive('stateless')->andReturnSelf();
        $providerMock2->shouldReceive('userFromToken')
            ->with('gmail-google-token')
            ->andReturn($nonCampusGoogleUser);
        Socialite::shouldReceive('driver')->with('google')->once()->andReturn($providerMock2);

        $this->postJson('/api/auth/google', [
            'credential' => 'gmail-google-token',
        ])->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('users', ['email' => 'outsider@gmail.com']);
        $this->assertSame(1, User::where('email', 'same.email@widyatama.ac.id')->count());
    }
}
