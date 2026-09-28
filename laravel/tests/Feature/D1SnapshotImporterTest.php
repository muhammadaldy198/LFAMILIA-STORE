<?php

namespace Tests\Feature;

use App\Services\D1SnapshotImporter;
use ReflectionMethod;
use Tests\TestCase;

class D1SnapshotImporterTest extends TestCase
{
    public function test_iso_8601_datetime_values_are_normalized_for_mariadb(): void
    {
        $importer = app(D1SnapshotImporter::class);
        $method = new ReflectionMethod(D1SnapshotImporter::class, 'normalizeRow');
        $method->setAccessible(true);

        $row = $method->invoke($importer, [
            'expires_at' => '2026-10-23T13:43:34.897Z',
            'created_at' => '2026-09-23T13:43:34.123Z',
            'name' => 'unchanged',
        ]);

        $this->assertSame('2026-10-23 13:43:34', $row['expires_at']);
        $this->assertSame('2026-09-23 13:43:34', $row['created_at']);
        $this->assertSame('unchanged', $row['name']);
    }
}
