<?php

use Illuminate\Console\Scheduling\Schedule;

function scheduledAt(string $command): ?string
{
    return collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', $command))
        ?->expression;
}

it('backs up every night and cleans old backups first', function () {
    expect(scheduledAt('backup:clean'))->toBe('0 1 * * *')
        ->and(scheduledAt('backup:run'))->toBe('30 1 * * *')
        ->and(scheduledAt('backup:monitor'))->toBe('0 2 * * *');
});
