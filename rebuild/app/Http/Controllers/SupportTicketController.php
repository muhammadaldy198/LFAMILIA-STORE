<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Services\AdminNotificationService;
use App\Services\TransactionalEmailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SupportTicketController
{
    public function index(Request $request): Response
    {
        return Inertia::render('Customer/Tickets', [
            'tickets' => SupportTicket::query()->where('user_id', $request->user()->id)
                ->orderByDesc('id')->paginate(10, ['id', 'order_id', 'subject', 'status', 'created_at']),
            'orders' => DB::table('orders')->where('user_id', $request->user()->id)
                ->orderByDesc('id')->limit(30)->get(['id', 'order_number']),
        ]);
    }

    public function store(
        Request $request,
        AdminNotificationService $notifications,
        TransactionalEmailService $emails,
    ): RedirectResponse {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:5000'],
            'order_id' => ['nullable', 'integer', Rule::exists('orders', 'id')
                ->where('user_id', $request->user()->id)],
        ]);

        $ticket = SupportTicket::create([
            'user_id' => $request->user()->id,
            'order_id' => $data['order_id'] ?? null,
            'subject' => $data['subject'],
            'message' => $data['message'],
            'kind' => isset($data['order_id']) ? 'ORDER' : 'GENERAL',
        ]);

        $notifications->record(
            'support.ticket.created',
            'Tiket pelanggan baru',
            'Tiket #'.$ticket->id.' · '.$ticket->subject,
            'INFO',
            'support_ticket',
            $ticket->id,
            ['order_id' => $ticket->order_id]
        );
        if (is_string($request->user()->email)) {
            $emails->queue(
                $request->user()->email,
                'Tiket LFAMILIA diterima',
                'Tiket #'.$ticket->id.' "'.$ticket->subject.'" telah diterima tim LFAMILIA.'
            );
        }

        return redirect()->route('account.tickets.show', $ticket->id);
    }

    public function show(Request $request, int $ticket): Response
    {
        $record = SupportTicket::where('user_id', $request->user()->id)
            ->whereKey($ticket)->firstOrFail(['id', 'order_id', 'subject', 'message', 'status', 'created_at']);

        return Inertia::render('Customer/TicketDetail', [
            'ticket' => $record,
            'messages' => DB::table('support_ticket_messages as messages')
                ->leftJoin('users', 'users.id', '=', 'messages.user_id')
                ->leftJoin('admin_users', 'admin_users.id', '=', 'messages.admin_user_id')
                ->where('messages.support_ticket_id', $record->id)
                ->orderBy('messages.id')
                ->get([
                    'messages.id', 'messages.sender_type', 'messages.message', 'messages.created_at',
                    'users.name as customer_name', 'admin_users.name as admin_name',
                ]),
        ]);
    }

    public function reply(
        Request $request,
        int $ticket,
        AdminNotificationService $notifications,
    ): RedirectResponse {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
        ]);
        $record = DB::transaction(function () use ($request, $ticket, $data): SupportTicket {
            $record = SupportTicket::where('user_id', $request->user()->id)
                ->whereKey($ticket)->lockForUpdate()->firstOrFail();
            abort_if($record->status === 'CLOSED', 422, 'Tiket sudah ditutup.');

            DB::table('support_ticket_messages')->insert([
                'support_ticket_id' => $record->id,
                'sender_type' => 'CUSTOMER',
                'user_id' => $request->user()->id,
                'admin_user_id' => null,
                'message' => $data['message'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $record->forceFill(['status' => 'OPEN'])->save();

            return $record;
        }, 3);

        $notifications->record(
            'support.ticket.replied',
            'Balasan pelanggan pada tiket',
            'Tiket #'.$record->id.' · '.$record->subject,
            'INFO',
            'support_ticket',
            $record->id,
            ['order_id' => $record->order_id]
        );

        return back();
    }
}
