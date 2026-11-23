<?php

namespace App\Http\Controllers;

use App\Models\Claim;
use App\Models\Notification;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClaimController extends Controller
{
    /**
     * Submit a claim for a post (using OOP ItemReport polymorphism for notification messaging).
     */
    public function store(Request $request, Post $post): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($post->user_id === $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak dapat mengajukan klaim pada laporan milik sendiri.',
            ], 422);
        }

        if ($post->status !== 'open') {
            return response()->json([
                'success' => false,
                'message' => 'Laporan ini sudah selesai dan tidak dapat diklaim lagi.',
            ], 422);
        }

        $existingPending = Claim::query()
            ->where('post_id', $post->id)
            ->where('claimer_id', $user->id)
            ->whereIn('status', ['pending', 'accepted'])
            ->first();

        if ($existingPending !== null) {
            return response()->json([
                'success' => false,
                'message' => $existingPending->status === 'accepted'
                    ? 'Klaim Anda pada laporan ini sudah diterima.'
                    : 'Anda sudah mengajukan klaim pada laporan ini dan sedang menunggu konfirmasi.',
            ], 422);
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'min:5', 'max:1000'],
            'contact_whatsapp' => ['nullable', 'string', 'max:25'],
        ]);

        $claim = Claim::create([
            'post_id' => $post->id,
            'claimer_id' => $user->id,
            'owner_id' => $post->user_id,
            'message' => $validated['message'],
            'contact_whatsapp' => $validated['contact_whatsapp'] ?? $user->whatsapp,
            'status' => 'pending',
        ]);

        $report = $post->asReport();

        Notification::create([
            'user_id' => $post->user_id,
            'actor_id' => $user->id,
            'post_id' => $post->id,
            'type' => 'claim_submitted',
            'message' => $report->getClaimNotificationMessage($user),
            'is_read' => false,
        ]);

        $claim->load(['post.images', 'claimer', 'owner']);

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan klaim berhasil dikirim. Menunggu konfirmasi pemilik laporan.',
            'data' => $claim->toApiArray($user),
        ], 201);
    }

    /**
     * List claims submitted by and received by the authenticated user.
     */
    public function myClaims(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $submitted = Claim::query()
            ->where('claimer_id', $user->id)
            ->with(['post.images', 'claimer', 'owner'])
            ->latest('id')
            ->get()
            ->map(fn (Claim $claim): array => $claim->toApiArray($user))
            ->values()
            ->all();

        $received = Claim::query()
            ->where('owner_id', $user->id)
            ->with(['post.images', 'claimer', 'owner'])
            ->latest('id')
            ->get()
            ->map(fn (Claim $claim): array => $claim->toApiArray($user))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Berhasil mengambil daftar pengajuan klaim',
            'data' => [
                'submitted' => $submitted,
                'received' => $received,
            ],
        ]);
    }

    /**
     * List claims for a specific post (owner or admin only).
     */
    public function postClaims(Request $request, Post $post): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($post->user_id !== $user->id && $user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk melihat klaim pada laporan ini.',
            ], 403);
        }

        $claims = Claim::query()
            ->where('post_id', $post->id)
            ->with(['post.images', 'claimer', 'owner'])
            ->latest('id')
            ->get()
            ->map(fn (Claim $claim): array => $claim->toApiArray($user))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Berhasil mengambil daftar klaim laporan',
            'data' => $claims,
        ]);
    }

    /**
     * Accept or reject a claim (post owner or admin).
     */
    public function respond(Request $request, Claim $claim): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($claim->owner_id !== $user->id && $user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk merespons klaim ini.',
            ], 403);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:accepted,rejected'],
            'owner_note' => ['nullable', 'string', 'max:500'],
        ]);

        $claim->status = $validated['status'];
        $claim->owner_note = $validated['owner_note'] ?? null;
        $claim->responded_at = now();
        $claim->save();

        $claim->load(['post.images', 'claimer', 'owner']);
        $post = $claim->post;

        if ($post !== null) {
            if ($claim->status === 'accepted') {
                $post->status = 'selesai';
                $post->save();

                // Reject other pending claims on the same post
                $otherPending = Claim::query()
                    ->where('post_id', $post->id)
                    ->where('id', '!=', $claim->id)
                    ->where('status', 'pending')
                    ->get();

                foreach ($otherPending as $other) {
                    $other->update([
                        'status' => 'rejected',
                        'owner_note' => 'Klaim lain telah diterima oleh pemilik laporan.',
                        'responded_at' => now(),
                    ]);

                    Notification::create([
                        'user_id' => $other->claimer_id,
                        'actor_id' => $user->id,
                        'post_id' => $post->id,
                        'type' => 'claim_rejected',
                        'message' => "Pengajuan klaim Anda pada laporan \"{$post->title}\" ditolak karena klaim lain telah diterima.",
                        'is_read' => false,
                    ]);
                }

                Notification::create([
                    'user_id' => $claim->claimer_id,
                    'actor_id' => $user->id,
                    'post_id' => $post->id,
                    'type' => 'claim_accepted',
                    'message' => "Selamat! Pengajuan klaim Anda pada laporan \"{$post->title}\" telah diterima oleh {$user->name}.",
                    'is_read' => false,
                ]);
            } else {
                Notification::create([
                    'user_id' => $claim->claimer_id,
                    'actor_id' => $user->id,
                    'post_id' => $post->id,
                    'type' => 'claim_rejected',
                    'message' => "Pengajuan klaim Anda pada laporan \"{$post->title}\" ditolak oleh {$user->name}.",
                    'is_read' => false,
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => $claim->status === 'accepted'
                ? 'Klaim diterima dan laporan ditandai selesai.'
                : 'Klaim ditolak.',
            'data' => $claim->toApiArray($user),
        ]);
    }
}
