// database/migrations/xxxx_xx_xx_add_attachment_to_articles_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->string('attachment_path')->nullable()->after('gallery_count');
            $table->string('attachment_name')->nullable()->after('attachment_path');
            $table->bigInteger('attachment_size')->nullable()->after('attachment_name');
        });
    }

    public function down()
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn(['attachment_path', 'attachment_name', 'attachment_size']);
        });
    }
};
