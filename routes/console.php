<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;

Artisan::command('campus:about', function () {
    $this->info(config('campus.school_name').' · Campus Organizations API');
    $this->line('Academic year: '.config('campus.academic_year').' · '.config('campus.semester'));
})->purpose('Show the configured school and term');

// Used by docker/start.sh on Render: seeds sample data only on an empty database.
Artisan::command('campus:seed-if-empty', function () {
    if (User::query()->exists()) {
        $this->info('Database already has users. Skipping sample data.');

        return;
    }

    $this->call('db:seed', ['--force' => true]);
    $this->info('Sample data loaded. Demo password for every account: password123');
})->purpose('Load the demo data if the database is empty');

// Sample dates are relative to "today". Run this to refresh the demo (deletes all data!).
Artisan::command('campus:reset-demo {--force}', function () {
    if (! $this->option('force') && ! $this->confirm('This deletes ALL data and reloads the sample data. Continue?')) {
        return;
    }

    $this->call('migrate:fresh', ['--seed' => true, '--force' => true]);
})->purpose('Wipe the database and reload the sample data');
