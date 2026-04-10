<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products')) {
            return;
        }

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name')->nullable()->index();
            $table->string('image')->nullable();
            $table->string('barcode')->nullable()->index();
            $table->string('upid')->nullable()->index();
            $table->string('main_category')->nullable()->index();
            $table->string('main_category1')->nullable()->index();
            $table->string('category')->nullable()->index();
            $table->text('categories')->nullable();
            $table->string('brand')->nullable()->index();
            $table->string('origin')->nullable()->index();
            $table->text('description')->nullable();
            $table->text('ingredients')->nullable();
            $table->text('notes')->nullable();
            $table->text('allergens')->nullable();
            $table->string('type')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
