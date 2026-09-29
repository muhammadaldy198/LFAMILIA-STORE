<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Validation\Rule;

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

    public function store(Request $request): RedirectResponse
    {
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
        ]);

        return redirect()->route('account.tickets.show', $ticket->id);
    }

    public function show(Request $request, int $ticket): Response
    {
        $record = SupportTicket::where('user_id', $request->user()->id)
            ->whereKey($ticket)->firstOrFail(['id', 'order_id', 'subject', 'message', 'status', 'created_at']);

        return Inertia::render('Customer/TicketDetail', ['ticket' => $record]);
    }
}
