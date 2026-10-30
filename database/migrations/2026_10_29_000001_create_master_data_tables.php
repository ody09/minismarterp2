<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 180)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('name', 180)->index();
            $table->text('description')->nullable();
            $table->enum('base_unit', ['piece'])->default('piece');
            $table->unsignedInteger('pieces_per_carton');
            $table->enum('purchase_unit', ['piece', 'carton']);
            $table->enum('selling_unit', ['piece', 'carton']);
            $table->decimal('purchase_price', 19, 6);
            $table->decimal('selling_price', 19, 6);
            $table->unsignedInteger('reorder_level')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('batch_number', 100);
            $table->date('production_date')->nullable();
            $table->date('expiration_date')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'batch_number']);
            $table->index(['product_id', 'expiration_date']);
        });
        foreach (['customers', 'suppliers'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('name', 180)->index();
                $table->string('phone', 40)->nullable();
                $table->string('email')->nullable();
                $table->text('address')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 180)->unique();
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_positive_carton CHECK (pieces_per_carton > 0)');
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_nonnegative_prices CHECK (purchase_price >= 0 AND selling_price >= 0)');
            DB::statement('ALTER TABLE product_batches ADD CONSTRAINT batches_ordered_dates CHECK (production_date IS NULL OR expiration_date IS NULL OR production_date <= expiration_date)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('product_batches');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
