<?php

namespace App\Services;

use Illuminate\Http\Request;

class CatalogAudit
{
    public function __construct(private readonly AdminAuditService $audit) {}

    public function record(Request $request, string $action, string $type, int $id, ?array $before, array $after): void
    {
        $this->audit->record($request, $action, $type, $id, $before, $after);
    }
}
