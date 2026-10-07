<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerPhoneController
{
    public function edit(): Response
    {
        return Inertia::render('Auth/CompletePhone');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\\+?[0-9]{8,16}$/'],
        ]);

        $request->user()->forceFill(['phone' => $data['phone']])->save();

        return redirect()->route('account');
    }
}
