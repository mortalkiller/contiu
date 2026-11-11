<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

pest()->group('migration', 'tenancy');

/** A database notification as Filament stores it, linking to a URL from before accounts. */
function oldNotification(?string $readAt = null): string
{
    $id = (string) Str::uuid();

    DB::table('notifications')->insert([
        'id' => $id,
        'type' => 'Filament\\Notifications\\DatabaseNotification',
        'notifiable_type' => 'App\\Models\\User',
        'notifiable_id' => 1,
        'data' => json_encode(['title' => 'Despesa por pagar', 'actions' => [['url' => '/expenses/5/edit']]]),
        'read_at' => $readAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function markOldNotificationsReadMigration(): object
{
    return require database_path('migrations/2026_11_08_100400_mark_old_notifications_read.php');
}

it('marks the notifications from before accounts as read', function () {
    Carbon::setTestNow('2026-11-08 12:00');
    $unread = oldNotification();
    $read = oldNotification('2026-11-01 09:00:00');

    markOldNotificationsReadMigration()->up();

    expect(Carbon::parse(DB::table('notifications')->where('id', $unread)->value('read_at'))->toDateTimeString())->toBe('2026-11-08 12:00:00')
        ->and(Carbon::parse(DB::table('notifications')->where('id', $read)->value('read_at'))->toDateTimeString())->toBe('2026-11-01 09:00:00');
});

it('does nothing on an empty table and when rolled back', function () {
    $migration = markOldNotificationsReadMigration();

    $migration->up();
    expect(DB::table('notifications')->count())->toBe(0);

    $id = oldNotification('2026-11-01 09:00:00');
    $migration->down();

    expect(DB::table('notifications')->where('id', $id)->value('read_at'))->not->toBeNull();
});
