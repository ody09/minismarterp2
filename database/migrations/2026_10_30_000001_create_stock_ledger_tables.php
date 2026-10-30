<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->timestamp('stock_started_at')->nullable());
        Schema::table('product_batches', fn (Blueprint $table) => $table->timestamp('stock_started_at')->nullable());
        Schema::create('inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('batch_id')->constrained('product_batches')->restrictOnDelete();
            $table->bigInteger('quantity')->default(0);
            $table->timestamps();
            $table->unique(['warehouse_id', 'batch_id']);
        });
        Schema::create('stock_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->enum('type', ['receipt', 'issue', 'reversal']);
            $table->uuid('idempotency_key')->unique();
            $table->string('payload_hash', 64);
            $table->string('reference', 100);
            $table->text('reason')->nullable();
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('stock_operations')->restrictOnDelete();
            $table->timestamp('posted_at')->index();
            $table->unique(['type', 'reference']);
        });
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_operation_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_id')->constrained('inventory')->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->unsignedInteger('allocation_number');
            $table->bigInteger('quantity');
            $table->unsignedBigInteger('input_quantity');
            $table->enum('unit', ['piece', 'carton']);
            $table->unsignedInteger('conversion_factor');
            $table->foreignId('reversal_of_transaction_id')->nullable()->unique()->constrained('inventory_transactions')->restrictOnDelete();
            $table->unique(['stock_operation_id', 'line_number', 'allocation_number'], 'stock_movement_allocation_unique');
            $table->index(['inventory_id', 'stock_operation_id']);
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE inventory ADD CONSTRAINT inventory_nonnegative CHECK (quantity >= 0)');
            DB::statement('ALTER TABLE inventory_transactions ADD CONSTRAINT stock_delta_nonzero CHECK (quantity <> 0)');
            DB::statement('ALTER TABLE inventory_transactions ADD CONSTRAINT stock_conversion_positive CHECK (conversion_factor > 0 AND input_quantity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('stock_operations');
        Schema::dropIfExists('inventory');
        Schema::table('product_batches', fn (Blueprint $table) => $table->dropColumn('stock_started_at'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('stock_started_at'));
    }
};
