<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminWorkspaceController
{
    public function audit(): Response
    {
        return Inertia::render('Admin/Workspace', [
            'kind' => 'audit',
            'title' => 'Audit Log',
            'rows' => DB::table('audit_logs')->orderByDesc('id')->paginate(50),
        ]);
    }
}
