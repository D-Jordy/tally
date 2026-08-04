<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deleting an imported row has to stick. The (account_id, dedupe_hash) unique
     * index covers trashed rows too, so a soft-deleted transaction keeps its hash
     * claimed and the importer can recognise it as "deliberately removed" instead
     * of inserting it again on the next export.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
