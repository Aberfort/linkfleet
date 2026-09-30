<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clicks', function (Blueprint $table) {
            // ISO 3166-1 alpha-2, looked up from the visitor's address while
            // the request is handled - the address itself is never kept (see
            // App\Support\ClientIp). Null when there was no database, or no
            // answer, and for every click recorded before this existed.
            $table->char('country', 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clicks', function (Blueprint $table) {
            $table->dropColumn('country');
        });
    }
};
