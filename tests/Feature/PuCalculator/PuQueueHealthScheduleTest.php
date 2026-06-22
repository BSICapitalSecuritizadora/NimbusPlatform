<?php

use Illuminate\Console\Scheduling\Schedule;

it('schedules the pu:queue-health command', function () {
    $events = app(Schedule::class)->events();

    $scheduled = collect($events)->contains(
        fn ($event): bool => str_contains((string) $event->command, 'pu:queue-health'),
    );

    expect($scheduled)->toBeTrue();
});
