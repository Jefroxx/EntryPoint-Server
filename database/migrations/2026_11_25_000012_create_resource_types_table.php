<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * The kinds of resource (Computer, Study Room, Projector...) become a list the librarians manage, so a type is
     * picked, not typed: "Computer", "computer" and "Computers" can no longer be three different types.
     * The types already in use are kept (one per spelling, ignoring capitals) and the resources are pointed at them.
     */
    public function up(): void
    {
        Schema::create('resource_types', function (Blueprint $table) {
            $table->id('typeID');
            $table->uuid('uuid')->unique();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });

        $seen = [];
        foreach (DB::table('resources')->orderBy('resID')->pluck('resourceType') as $existing) {
            $key = mb_strtolower(trim($existing));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            // Typed in lower case ("computer") reads better as a name with a capital; one typed with capitals is kept as it is.
            $name = $existing === mb_strtolower($existing) ? Str::title(trim($existing)) : trim($existing);
            $seen[$key] = $name;

            DB::table('resource_types')->insert(['uuid' => (string) Str::uuid(), 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }

        foreach (DB::table('resources')->get(['resID', 'resourceType']) as $resource) {
            $name = $seen[mb_strtolower(trim($resource->resourceType))] ?? null;
            if ($name !== null && $name !== $resource->resourceType) {
                DB::table('resources')->where('resID', $resource->resID)->update(['resourceType' => $name]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_types');
    }
};
