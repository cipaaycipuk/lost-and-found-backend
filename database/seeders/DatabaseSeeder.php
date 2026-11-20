<?php

namespace Database\Seeders;

use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use App\Models\PostImage;
use App\Models\SavedPost;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database with dummy data matching dummyData.js.
     */
    public function run(): void
    {
        $syifa = User::updateOrCreate(
            ['email' => 'syifa.aini@widyatama.ac.id'],
            [
                'name' => 'Syifa Nur Aini',
                'password' => 'password123',
                'whatsapp' => '0813 1212 3476',
                'avatar_url' => '/users/emily.jpg',
                'role' => 'user',
            ]
        );

        $rony = User::updateOrCreate(
            ['email' => 'rony.parulian@widyatama.ac.id'],
            [
                'name' => 'Rony Parulian',
                'password' => 'password123',
                'whatsapp' => '0813 1212 3476',
                'avatar_url' => '/users/rony.jpg',
                'role' => 'user',
            ]
        );

        $alyssa = User::updateOrCreate(
            ['email' => 'alyssa.putri@widyatama.ac.id'],
            [
                'name' => 'Alyssa Putri',
                'password' => 'password123',
                'whatsapp' => '0821 9876 5432',
                'avatar_url' => '/users/alyssa.jpg',
                'role' => 'user',
            ]
        );

        $agatha = User::updateOrCreate(
            ['email' => 'agatha.chelsea@widyatama.ac.id'],
            [
                'name' => 'Agatha Chelsea',
                'password' => 'password123',
                'whatsapp' => '0812 3456 7890',
                'avatar_url' => '/users/emily.jpg',
                'role' => 'user',
            ]
        );

        // Post 1: Tumbler Minum Motif Leopard (Kehilangan - Rony Parulian)
        $post1 = Post::updateOrCreate(
            [
                'user_id' => $rony->id,
                'title' => 'Tumbler Minum Motif Leopard',
            ],
            [
                'type' => 'kehilangan',
                'status' => 'open',
                'location' => 'Foodcourt / Kantin',
                'incident_date' => '2026-09-10',
                'category' => 'Lainnya',
                'description' => 'Dicari tumbler dengan motif leopard warna cokelat hitam. Terakhir diletakkan di meja dekat tangga Gedung B lt. 406 setelah kelas pagi.',
                'created_at' => now()->subMinutes(14),
                'updated_at' => now()->subMinutes(14),
            ]
        );

        PostImage::updateOrCreate(
            [
                'post_id' => $post1->id,
                'sort_order' => 0,
            ],
            [
                'image_url' => '/posts-img/tumbler.jpg',
            ]
        );

        // Post 2: Gitar Akustik Cokelat + Softcase Hitam (Ditemukan - Alyssa Putri)
        $post2 = Post::updateOrCreate(
            [
                'user_id' => $alyssa->id,
                'title' => 'Gitar Akustik Cokelat + Softcase Hitam',
            ],
            [
                'type' => 'ditemukan',
                'status' => 'open',
                'location' => 'Gedung A',
                'incident_date' => '2026-09-09',
                'category' => 'Lainnya',
                'description' => 'Ditemukan gitar akustik di ruang tunggu lobi Gedung A sekitar pukul 16.30 WIB. Saat ini dititipkan ke satpam pos utama.',
                'created_at' => now()->subHours(3),
                'updated_at' => now()->subHours(3),
            ]
        );

        PostImage::updateOrCreate(
            [
                'post_id' => $post2->id,
                'sort_order' => 0,
            ],
            [
                'image_url' => '/posts-img/guitar.jpg',
            ]
        );

        // Post 3: iPhone / Pemutar Musik Hitam (Kehilangan - Syifa Nur Aini)
        $post3 = Post::updateOrCreate(
            [
                'user_id' => $syifa->id,
                'title' => 'iPhone / Pemutar Musik Hitam',
            ],
            [
                'type' => 'kehilangan',
                'status' => 'open',
                'location' => 'Perpustakaan',
                'incident_date' => '2026-09-08',
                'category' => 'Elektronik',
                'description' => 'Tertinggal di meja baca lantai 2 Perpustakaan area dekat colokan listrik. Casing bening dengan stiker kecil di bagian belakang.',
                'created_at' => now()->subHours(22),
                'updated_at' => now()->subHours(22),
            ]
        );

        PostImage::updateOrCreate(
            [
                'post_id' => $post3->id,
                'sort_order' => 0,
            ],
            [
                'image_url' => '/posts-img/imusic.jpg',
            ]
        );

        // Comments for Post 1
        $comment101 = Comment::updateOrCreate(
            [
                'post_id' => $post1->id,
                'user_id' => $agatha->id,
                'parent_id' => null,
            ],
            [
                'content' => 'Tadi sempat lihat di dekat dispenser lantai 4 kak, coba cek ke satpam lantai 1.',
                'created_at' => now()->subMinutes(10),
                'updated_at' => now()->subMinutes(10),
            ]
        );

        Comment::updateOrCreate(
            [
                'post_id' => $post1->id,
                'user_id' => $rony->id,
                'parent_id' => $comment101->id,
            ],
            [
                'content' => 'Siap makasih infonya kak! Otw cek ke pos satpam.',
                'created_at' => now()->subMinutes(5),
                'updated_at' => now()->subMinutes(5),
            ]
        );

        // Comments for Post 2
        Comment::updateOrCreate(
            [
                'post_id' => $post2->id,
                'user_id' => $syifa->id,
                'parent_id' => null,
            ],
            [
                'content' => 'Keren banget langsung diamankan ke pos satpam. Semoga cepat ketemu pemiliknya.',
                'created_at' => now()->subMinutes(45),
                'updated_at' => now()->subMinutes(45),
            ]
        );

        // Saved Post: Syifa saves Post 2 (is_saved: true in dummyData.js)
        SavedPost::updateOrCreate([
            'user_id' => $syifa->id,
            'post_id' => $post2->id,
        ]);

        // Initial Notifications (matching INITIAL_NOTIFICATIONS in dummyData.js)
        Notification::updateOrCreate(
            [
                'user_id' => $syifa->id,
                'post_id' => $post1->id,
                'type' => 'comment',
            ],
            [
                'actor_id' => $agatha->id,
                'message' => 'Agatha Chelsea mengomentari laporan "Tumbler Minum Motif Leopard"',
                'is_read' => false,
                'created_at' => now()->subMinutes(10),
                'updated_at' => now()->subMinutes(10),
            ]
        );

        Notification::updateOrCreate(
            [
                'user_id' => $syifa->id,
                'post_id' => $post2->id,
                'type' => 'status_update',
            ],
            [
                'actor_id' => $alyssa->id,
                'message' => 'Laporan baru ditemukan di Gedung A yang mungkin sesuai.',
                'is_read' => true,
                'created_at' => now()->subHours(3),
                'updated_at' => now()->subHours(3),
            ]
        );
    }
}
