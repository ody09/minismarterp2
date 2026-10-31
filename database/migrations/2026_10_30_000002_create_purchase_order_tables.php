<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('supplier_name', 180);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('order_date')->index();
            $table->enum('status', ['draft', 'received', 'cancelled'])->default('draft')->index();
            $table->unsignedInteger('version')->default(1);
            $table->text('notes')->nullable();
            $table->decimal('total_amount', 18, 6);
            $table->uuid('creation_key')->unique();
            $table->char('creation_hash', 64);
            $table->uuid('receipt_key')->nullable()->unique();
            $table->char('receipt_hash', 64)->nullable();
            $table->foreignId('stock_operation_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name', 180);
            $table->foreignId('batch_id')->nullable()->constrained('product_batches')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->enum('unit', ['piece', 'carton']);
            $table->unsignedInteger('conversion_factor');
            $table->decimal('unit_price', 15, 6);
            $table->decimal('subtotal', 18, 6);
            $table->unique(['purchase_order_id', 'line_number']);
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE purchase_orders ADD CONSTRAINT purchase_total_positive CHECK (total_amount >= 0 AND version > 0)');
            DB::statement('ALTER TABLE purchase_orders ADD CONSTRAINT purchase_receipt_state CHECK ((status = \'received\' AND stock_operation_id IS NOT NULL AND received_by IS NOT NULL AND received_at IS NOT NULL AND receipt_key IS NOT NULL AND receipt_hash IS NOT NULL) OR (status <> \'received\' AND stock_operation_id IS NULL AND received_by IS NULL AND received_at IS NULL AND receipt_key IS NULL AND receipt_hash IS NULL))');
            DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT purchase_item_positive CHECK (quantity > 0 AND conversion_factor > 0 AND unit_price >= 0 AND subtotal >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
