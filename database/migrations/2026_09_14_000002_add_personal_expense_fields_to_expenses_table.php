<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            if (! Schema::hasColumn('expenses', 'expense_type')) {
                $table->string('expense_type')->default('project')->after('project_id')->index();
            }

            if (! Schema::hasColumn('expenses', 'owner_user_id')) {
                $table->foreignId('owner_user_id')->nullable()->after('expense_type')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['owner_user_id']);
            $table->dropColumn(['expense_type', 'owner_user_id']);
        });
    }
};
