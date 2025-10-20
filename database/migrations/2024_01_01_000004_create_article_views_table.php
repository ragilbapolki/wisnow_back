<?php
// database/migrations/2024_01_01_000004_create_article_views_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('article_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('ip_address');
            $table->timestamp('viewed_at');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('article_views');
    }
};
