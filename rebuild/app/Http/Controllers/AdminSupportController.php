<?php

namespace App\Http\Controllers;

use App\Services\AdminAuditService;
use App\Services\TransactionalEmailService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdminSupportController
{
    private const STATUSES = ['OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED'];

    private const KINDS = [
        'GENERAL' => 'Umum',
        'ORDER' => 'Pesanan',
        'PAYMENT' => 'Pembayaran',
        'REFUND' => 'Refund',
        'COMPLAINT' => 'Komplain',
        'ACCOUNT' => 'Akun pelanggan',
        'OTHER' => 'Lainnya',
    ];

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'kind' => ['nullable', Rule::in(array_keys(self::KINDS))],
            'source' => ['nullable', Rule::in(['ACCOUNT', 'GUEST'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
            'ticket' => ['nullable', 'integer', 'min:1'],
        ]);

        $filters = [
            'q' => mb_substr(trim((string) ($filters['q'] ?? '')), 0, 100),
            'status' => (string) ($filters['status'] ?? ''),
            'kind' => (string) ($filters['kind'] ?? ''),
            'source' => (string) ($filters['source'] ?? ''),
            'per_page' => (int) ($filters['per_page'] ?? 25),
            'ticket' => isset($filters['ticket']) ? (int) $filters['ticket'] : null,
        ];

        $messageStats = DB::table('support_ticket_messages')
            ->select('support_ticket_id')
            ->selectRaw('COUNT(*) as message_count')
            ->selectRaw('MAX(created_at) as last_message_at')
            ->groupBy('support_ticket_id');

        $tickets = DB::table('support_tickets as tickets')
            ->leftJoin('users', 'users.id', '=', 'tickets.user_id')
            ->leftJoin('orders', 'orders.id', '=', 'tickets.order_id')
            ->leftJoin('admin_users as handler', 'handler.id', '=', 'tickets.handled_by_admin_id')
            ->leftJoinSub($messageStats, 'message_stats', 'message_stats.support_ticket_id', '=', 'tickets.id')
            ->when($filters['q'] !== '', function (Builder $query) use ($filters): void {
                $like = '%'.$filters['q'].'%';
                $query->where(function (Builder $query) use ($filters, $like): void {
                    if (ctype_digit($filters['q'])) {
                        $query->orWhere('tickets.id', (int) $filters['q']);
                    }
                    $query->orWhere('tickets.subject', 'like', $like)
                        ->orWhere('users.name', 'like', $like)
                        ->orWhere('users.email', 'like', $like)
                        ->orWhere('orders.order_number', 'like', $like)
                        ->orWhere('orders.guest_email', 'like', $like);
                });
            })
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('tickets.status', $filters['status']))
            ->when($filters['kind'] !== '', fn (Builder $query) => $query->where('tickets.kind', $filters['kind']))
            ->when($filters['source'] === 'ACCOUNT', fn (Builder $query) => $query->whereNotNull('tickets.user_id'))
            ->when($filters['source'] === 'GUEST', fn (Builder $query) => $query->whereNull('tickets.user_id'))
            ->orderByRaw("CASE tickets.status WHEN 'OPEN' THEN 0 WHEN 'IN_PROGRESS' THEN 1 WHEN 'RESOLVED' THEN 2 ELSE 3 END")
            ->orderByDesc('tickets.updated_at')
            ->orderByDesc('tickets.id')
            ->select([
                'tickets.id',
                'tickets.user_id',
                'tickets.order_id',
                'tickets.subject',
                'tickets.kind',
                'tickets.status',
                'tickets.created_at',
                'tickets.updated_at',
                'tickets.handled_at',
                'users.name as customer_name',
                'users.email as customer_email',
                'orders.order_number',
                'orders.guest_email',
                'handler.name as handler_name',
                DB::raw('COALESCE(message_stats.message_count, 0) as message_count'),
                DB::raw('COALESCE(message_stats.last_message_at, tickets.updated_at) as last_message_at'),
            ])
            ->paginate($filters['per_page'])
            ->withQueryString()
            ->through(fn (object $ticket): array => $this->ticketRow($ticket));

        $statusCounts = DB::table('support_tickets')
            ->select('status')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $quickReplies = json_decode(
            (string) DB::table('system_settings')->where('key', 'support.quick_replies')->value('value'),
            true
        );
        if (! is_array($quickReplies)) {
            $quickReplies = [];
        }

        return Inertia::render('Admin/Support', [
            'filters' => $filters,
            'tickets' => $tickets,
            'selectedTicket' => $filters['ticket'] ? $this->ticketDetail($filters['ticket']) : null,
            'quickReplies' => array_values(array_filter(
                array_map(fn ($reply): string => trim((string) $reply), $quickReplies),
                fn (string $reply): bool => $reply !== ''
            )),
            'kinds' => collect(self::KINDS)
                ->map(fn (string $label, string $code): array => ['code' => $code, 'label' => $label])
                ->values(),
            'summary' => [
                'total' => (int) $statusCounts->sum(),
                'open' => (int) ($statusCounts['OPEN'] ?? 0),
                'in_progress' => (int) ($statusCounts['IN_PROGRESS'] ?? 0),
                'resolved' => (int) ($statusCounts['RESOLVED'] ?? 0),
                'closed' => (int) ($statusCounts['CLOSED'] ?? 0),
                'guest' => DB::table('support_tickets')->whereNull('user_id')->count(),
                'account' => DB::table('support_tickets')->whereNotNull('user_id')->count(),
            ],
        ]);
    }

    public function quickReplies(Request $request, AdminAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'replies' => ['present', 'array', 'max:30'],
            'replies.*' => ['nullable', 'string', 'max:1000'],
        ]);

        $replies = array_values(array_filter(
            array_map(fn ($reply): string => trim((string) $reply), $data['replies']),
            fn (string $reply): bool => $reply !== ''
        ));

        $beforeRaw = DB::table('system_settings')->where('key', 'support.quick_replies')->value('value');
        $before = is_string($beforeRaw) ? json_decode($beforeRaw, true) : null;

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'support.quick_replies'],
            [
                'value' => json_encode($replies, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
                'updated_by_admin_id' => $request->user('admin')->id,
            ]
        );

        $audit->record(
            $request,
            'support.quick_replies.updated',
            'system_setting',
            'support.quick_replies',
            is_array($before) ? $before : null,
            $replies,
        );

        return back()->with('status', 'Balasan cepat berhasil disimpan.');
    }

    public function update(
        Request $request,
        int $id,
        AdminAuditService $audit,
        TransactionalEmailService $emails,
    ): RedirectResponse {
        $data = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
            'kind' => ['required', Rule::in(array_keys(self::KINDS))],
            'reply' => ['nullable', 'string', 'max:5000'],
        ]);
        $reply = trim((string) ($data['reply'] ?? ''));
        $adminId = (int) $request->user('admin')->id;

        [$before, $after] = DB::transaction(function () use ($id, $data, $reply, $adminId): array {
            $ticket = DB::table('support_tickets')->where('id', $id)->lockForUpdate()->first();
            abort_unless($ticket, 404);

            $before = (array) $ticket;
            DB::table('support_tickets')->where('id', $id)->update([
                'status' => $data['status'],
                'kind' => $data['kind'],
                'handled_by_admin_id' => $adminId,
                'handled_at' => now(),
                'updated_at' => now(),
            ]);

            if ($reply !== '') {
                DB::table('support_ticket_messages')->insert([
                    'support_ticket_id' => $id,
                    'sender_type' => 'ADMIN',
                    'user_id' => null,
                    'admin_user_id' => $adminId,
                    'message' => $reply,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return [$before, [
                'status' => $data['status'],
                'kind' => $data['kind'],
                'handled_by_admin_id' => $adminId,
                'replied' => $reply !== '',
            ]];
        }, 3);

        if ($reply !== '') {
            $email = DB::table('support_tickets as tickets')
                ->leftJoin('users', 'users.id', '=', 'tickets.user_id')
                ->leftJoin('orders', 'orders.id', '=', 'tickets.order_id')
                ->where('tickets.id', $id)
                ->value(DB::raw('COALESCE(users.email, orders.guest_email)'));

            if (is_string($email) && $email !== '') {
                $emails->queue(
                    $email,
                    'Balasan tiket LFAMILIA #'.$id,
                    'Tim LFAMILIA membalas tiket #'.$id.': '.$reply
                );
            }
        }

        $audit->record($request, 'support.updated', 'support_ticket', $id, $before, $after);

        return back()->with('status', $reply !== ''
            ? 'Status tiket dan balasan berhasil disimpan.'
            : 'Status tiket berhasil diperbarui.');
    }

    private function ticketDetail(int $id): ?array
    {
        $ticket = DB::table('support_tickets as tickets')
            ->leftJoin('users', 'users.id', '=', 'tickets.user_id')
            ->leftJoin('orders', 'orders.id', '=', 'tickets.order_id')
            ->leftJoin('admin_users as handler', 'handler.id', '=', 'tickets.handled_by_admin_id')
            ->where('tickets.id', $id)
            ->first([
                'tickets.id',
                'tickets.user_id',
                'tickets.order_id',
                'tickets.subject',
                'tickets.message',
                'tickets.kind',
                'tickets.status',
                'tickets.created_at',
                'tickets.updated_at',
                'tickets.handled_at',
                'users.name as customer_name',
                'users.email as customer_email',
                'orders.order_number',
                'orders.guest_email',
                'handler.name as handler_name',
            ]);

        if (! $ticket) {
            return null;
        }

        $messages = DB::table('support_ticket_messages as messages')
            ->leftJoin('users', 'users.id', '=', 'messages.user_id')
            ->leftJoin('admin_users', 'admin_users.id', '=', 'messages.admin_user_id')
            ->where('messages.support_ticket_id', $id)
            ->orderBy('messages.id')
            ->get([
                'messages.id',
                'messages.sender_type',
                'messages.message',
                'messages.created_at',
                'users.name as customer_name',
                'admin_users.name as admin_name',
            ])
            ->map(fn (object $message): array => [
                'id' => (int) $message->id,
                'sender_type' => (string) $message->sender_type,
                'sender_name' => $message->sender_type === 'ADMIN'
                    ? ($message->admin_name ?: 'Admin LFAMILIA')
                    : ($message->customer_name ?: ($ticket->customer_name ?: 'Guest')),
                'message' => (string) $message->message,
                'created_at' => $message->created_at,
            ])->values();

        return [
            ...$this->ticketRow($ticket),
            'message' => (string) $ticket->message,
            'messages' => $messages,
        ];
    }

    private function ticketRow(object $ticket): array
    {
        $isGuest = $ticket->user_id === null;

        return [
            'id' => (int) $ticket->id,
            'user_id' => $ticket->user_id === null ? null : (int) $ticket->user_id,
            'order_id' => $ticket->order_id === null ? null : (int) $ticket->order_id,
            'subject' => (string) $ticket->subject,
            'kind' => (string) ($ticket->kind ?? 'GENERAL'),
            'status' => (string) $ticket->status,
            'source' => $isGuest ? 'GUEST' : 'ACCOUNT',
            'customer_name' => $ticket->customer_name ?: ($isGuest ? 'Guest' : 'Pelanggan'),
            'customer_email' => $ticket->customer_email ?: $ticket->guest_email,
            'order_number' => $ticket->order_number,
            'handler_name' => $ticket->handler_name,
            'handled_at' => $ticket->handled_at,
            'message_count' => isset($ticket->message_count) ? 1 + (int) $ticket->message_count : null,
            'last_message_at' => $ticket->last_message_at ?? $ticket->updated_at,
            'created_at' => $ticket->created_at,
            'updated_at' => $ticket->updated_at,
        ];
    }
}
