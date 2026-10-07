<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Services\AdminNotificationService;
use App\Services\GuestOrderAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GuestSupportController
{
    public function index(Request $request): Response
    {
        $order = DB::table('orders')->whereNull('user_id')
            ->where('id', $request->session()->get('guest_order_id'))
            ->first(['id', 'order_number']);
        $tickets = $order ? DB::table('support_tickets')
            ->whereNull('user_id')->where('order_id', $order->id)->orderByDesc('id')
            ->get(['id', 'subject', 'message', 'status', 'created_at']) : collect();
        if ($tickets->isNotEmpty()) {
            $messages = DB::table('support_ticket_messages')
                ->whereIn('support_ticket_id', $tickets->pluck('id'))
                ->orderBy('support_ticket_id')->orderBy('id')
                ->get(['id', 'support_ticket_id', 'sender_type', 'message', 'created_at'])
                ->groupBy('support_ticket_id');
            $tickets->each(function (object $ticket) use ($messages): void {
                $ticket->messages = $messages->get($ticket->id, collect())->values();
            });
        }

        return Inertia::render('Guest/Support', [
            'order' => $order ? ['order_number' => $order->order_number] : null,
            'tickets' => $tickets,
        ]);
    }

    public function verify(Request $request, GuestOrderAccess $access): RedirectResponse
    {
        $this->verifiedOrder($request, $access);

        return redirect()->route('guest.support');
    }

    public function store(Request $request, GuestOrderAccess $access, AdminNotificationService $notifications): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:5000'],
        ]);
        $order = $this->verifiedOrder($request, $access);
        $ticket = SupportTicket::create([
            'user_id' => null,
            'order_id' => $order->id,
            'subject' => $data['subject'],
            'message' => $data['message'],
            'kind' => 'ORDER',
        ]);
        $notifications->record('support.ticket.created', 'Tiket guest baru',
            'Tiket #'.$ticket->id.' · '.$ticket->subject, 'INFO',
            'support_ticket', $ticket->id, ['order_id' => $order->id]);

        return redirect()->route('guest.support');
    }

    public function reply(
        Request $request,
        int $ticket,
        AdminNotificationService $notifications,
    ): RedirectResponse {
        $data = $request->validate(['message' => ['required', 'string', 'max:5000']]);
        $order = DB::table('orders')->whereNull('user_id')
            ->where('id', $request->session()->get('guest_order_id'))->first(['id']);
        abort_unless($order, 404);
        $record = DB::transaction(function () use ($order, $ticket, $data): SupportTicket {
            $record = SupportTicket::whereNull('user_id')->where('order_id', $order->id)
                ->whereKey($ticket)->lockForUpdate()->firstOrFail();
            abort_if($record->status === 'CLOSED', 422, 'Tiket sudah ditutup.');
            DB::table('support_ticket_messages')->insert([
                'support_ticket_id' => $record->id, 'sender_type' => 'CUSTOMER',
                'user_id' => null, 'admin_user_id' => null,
                'message' => $data['message'], 'created_at' => now(), 'updated_at' => now(),
            ]);
            $record->forceFill(['status' => 'OPEN'])->save();

            return $record;
        });

        $notifications->record(
            'support.ticket.replied',
            'Balasan guest pada tiket',
            'Tiket #'.$record->id.' · '.$record->subject,
            'INFO',
            'support_ticket',
            $record->id,
            ['order_id' => $record->order_id]
        );

        return redirect()->route('guest.support');
    }

    private function verifiedOrder(Request $request, GuestOrderAccess $access): object
    {
        $data = $request->validate([
            'order_number' => ['required', 'string', 'max:80'],
            'access_code' => ['nullable', 'string', 'size:64'],
        ]);
        $order = DB::table('orders')->whereNull('user_id')
            ->where('order_number', $data['order_number'])->first(['id']);
        $authorized = $order && (
            (int) $request->session()->get('guest_order_id') === (int) $order->id
            || $access->matches((int) $order->id, (string) ($data['access_code'] ?? ''))
        );
        if (! $authorized) {
            throw ValidationException::withMessages([
                'order_number' => 'Nomor invoice atau kode akses tidak cocok.',
            ]);
        }
        $request->session()->regenerate();
        $request->session()->put('guest_order_id', $order->id);

        return $order;
    }
}
