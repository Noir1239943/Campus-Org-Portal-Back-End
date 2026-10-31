<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Moves the already-seeded demo accounts to their new login IDs.
 * A change is skipped when the old account is missing or the new ID is taken.
 */
return new class extends Migration
{
    private const CHANGES = [
        'FAC-0012' => 'Admin',
        '2023-00089' => '0002',
    ];

    public function up(): void
    {
        foreach (self::CHANGES as $old => $new) {
            $this->rename($old, $new);
        }
    }

    public function down(): void
    {
        foreach (self::CHANGES as $old => $new) {
            $this->rename($new, $old);
        }
    }

    private function rename(string $from, string $to): void
    {
        $user = DB::table('users')->where('id_number', $from)->first();

        if (! $user) {
            return;
        }

        $taken = DB::table('users')
            ->whereRaw('UPPER(id_number) = ?', [strtoupper($to)])
            ->where('id', '!=', $user->id)
            ->exists();

        if ($taken) {
            return;
        }

        DB::table('users')->where('id', $user->id)->update(['id_number' => $to]);
    }
};
