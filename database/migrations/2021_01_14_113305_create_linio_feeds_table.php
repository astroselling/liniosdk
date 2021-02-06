<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLinioFeedsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('linio_feeds', function (Blueprint $table) {
            $table->id();
            $table->string('feed_id', 64)->unique();
            $table->string('status', 32);
            $table->string('source', 16);
            $table->string('action', 32);
            $table->dateTime('creation_date');
            $table->dateTime('updated_date');
            $table->integer('total_records');
            $table->integer('processed_records')->nullable();
            $table->integer('failed_records')->nullable();
            $table->json('errors')->nullable();
            $table->json('warnings')->nullable();
            $table->json('failure_reports')->nullable();
            $table->timestamps();
            $table->softdeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('linio_feeds');
    }
}
