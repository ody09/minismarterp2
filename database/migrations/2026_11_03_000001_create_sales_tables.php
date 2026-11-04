<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->enum('unit', ['piece', 'carton']);
            $table->unsignedInteger('min_quantity')->default(1);
            $table->decimal('unit_price', 15, 6);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['customer_id', 'product_id', 'unit', 'min_quantity'], 'customer_price_lookup');
        });
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('customer_name', 180);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('order_date')->index();
            $table->enum('status', ['draft', 'confirmed', 'cancelled'])->default('draft')->index();
            $table->unsignedInteger('version')->default(1);
            $table->text('notes')->nullable();
            $table->decimal('total_amount', 18, 6);
            $table->uuid('creation_key')->unique();
            $table->char('creation_hash', 64);
            $table->uuid('confirmation_key')->nullable()->unique();
            $table->char('confirmation_hash', 64)->nullable();
            $table->foreignId('stock_operation_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
        Schema::create('sales_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name', 180);
            $table->unsignedInteger('quantity');
            $table->enum('unit', ['piece', 'carton']);
            $table->unsignedInteger('conversion_factor');
            $table->decimal('unit_price', 15, 6);
            $table->decimal('subtotal', 18, 6);
            $table->enum('price_source', ['catalog', 'customer']);
            $table->foreignId('customer_price_id')->nullable()->constrained('customer_product_prices')->restrictOnDelete();
            $table->unsignedInteger('price_rule_version')->nullable();
            $table->unique(['sales_order_id', 'line_number']);
            $table->unique(['sales_order_id', 'product_id', 'unit'], 'sale_product_unit_unique');
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE customer_product_prices ADD CONSTRAINT customer_price_valid CHECK (min_quantity > 0 AND unit_price >= 0 AND version > 0 AND (valid_from IS NULL OR valid_to IS NULL OR valid_from <= valid_to))');
            DB::statement('ALTER TABLE sales_orders ADD CONSTRAINT sales_total_valid CHECK (total_amount >= 0 AND version > 0)');
            DB::statement("ALTER TABLE sales_orders ADD CONSTRAINT sales_posted_state CHECK ((status = 'confirmed' AND stock_operation_id IS NOT NULL AND confirmed_by IS NOT NULL AND confirmed_at IS NOT NULL AND confirmation_key IS NOT NULL AND confirmation_hash IS NOT NULL) OR (status <> 'confirmed' AND stock_operation_id IS NULL AND confirmed_by IS NULL AND confirmed_at IS NULL AND confirmation_key IS NULL AND confirmation_hash IS NULL))");
            DB::statement("ALTER TABLE sales_order_items ADD CONSTRAINT sales_item_valid CHECK (quantity > 0 AND conversion_factor > 0 AND unit_price >= 0 AND subtotal >= 0 AND ((price_source = 'catalog' AND customer_price_id IS NULL AND price_rule_version IS NULL) OR (price_source = 'customer' AND customer_price_id IS NOT NULL AND price_rule_version IS NOT NULL)))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_items');
        Schema::dropIfExists('sales_orders');
        Schema::dropIfExists('customer_product_prices');
    }
};
