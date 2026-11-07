<?php

use Spatie\Backup\Tasks\Backup\DbDumperFactory;

it('dumps only the Contiu tables from PostgreSQL, ready to restore elsewhere', function () {
    $command = DbDumperFactory::createFromConnection('pgsql')->getDumpCommand('/tmp/dump.sql');

    // Supabase keeps its own schemas (auth, storage, realtime, vault) in the same database.
    expect($command)
        ->toContain('--schema=public')
        ->toContain('--no-owner')
        ->toContain('--no-privileges');
});
