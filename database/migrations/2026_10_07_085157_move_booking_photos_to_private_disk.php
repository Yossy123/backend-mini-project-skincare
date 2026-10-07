<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    private const LEGACY_PREFIX = '/storage/bookings/';

    /**
     * Move clinical photos off the publicly served disk and store their private disk path.
     */
    public function up(): void
    {
        DB::table('appointments')
            ->where('photo_url', 'like', self::LEGACY_PREFIX.'%')
            ->orderBy('id')
            ->each(function (object $appointment): void {
                $path = substr($appointment->photo_url, strlen('/storage/'));

                if (Storage::disk('public')->exists($path)) {
                    Storage::disk('local')->put($path, Storage::disk('public')->get($path));
                    Storage::disk('public')->delete($path);
                }

                DB::table('appointments')->where('id', $appointment->id)->update(['photo_url' => $path]);
            });
    }

    /**
     * Restore photos to the public disk and their legacy public path.
     */
    public function down(): void
    {
        DB::table('appointments')
            ->where('photo_url', 'like', 'bookings/%')
            ->orderBy('id')
            ->each(function (object $appointment): void {
                $path = $appointment->photo_url;

                if (Storage::disk('local')->exists($path)) {
                    Storage::disk('public')->put($path, Storage::disk('local')->get($path));
                    Storage::disk('local')->delete($path);
                }

                DB::table('appointments')->where('id', $appointment->id)->update(['photo_url' => '/storage/'.$path]);
            });
    }
};
