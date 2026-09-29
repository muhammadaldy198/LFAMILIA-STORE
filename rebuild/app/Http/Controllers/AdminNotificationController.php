<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminNotificationController
{
    public function index(Request $request): Response
    {
        $adminId = $request->user('admin')->id;

        return Inertia::render('Admin/Notifications', [
            'notifications' => DB::table('admin_notifications as notifications')
                ->leftJoin('admin_notification_reads as reads', function ($join) use ($adminId): void {
                    $join->on('reads.admin_notification_id', '=', 'notifications.id')
                        ->where('reads.admin_user_id', '=', $adminId);
                })
                ->orderByDesc('notifications.id')
                ->select('notifications.*', 'reads.read_at')
                ->paginate(30),
        ]);
    }

    public function read(Request $request, int $id): RedirectResponse
    {
        abort_unless(DB::table('admin_notifications')->where('id', $id)->exists(), 404);

        DB::table('admin_notification_reads')->insertOrIgnore([
            'admin_notification_id' => $id,
            'admin_user_id' => $request->user('admin')->id,
            'read_at' => now(),
        ]);

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $adminId = $request->user('admin')->id;
        DB::table('admin_notifications')->orderBy('id')->chunkById(500, function ($notifications) use ($adminId): void {
            DB::table('admin_notification_reads')->insertOrIgnore(
                $notifications->map(fn (object $notification): array => [
                    'admin_notification_id' => $notification->id,
                    'admin_user_id' => $adminId,
                    'read_at' => now(),
                ])->all()
            );
        });

        return back();
    }
}
