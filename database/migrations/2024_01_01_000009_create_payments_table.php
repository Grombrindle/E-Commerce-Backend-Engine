<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('restrict');
            $table->foreignId('user_id')->constrained()->onDelete('restrict');
            $table->string('payment_number', 50)->unique();
            $table->enum('method', ['card','bank_transfer','cash_on_delivery','wallet']);
            $table->enum('status', ['pending','paid','failed','refunded'])->default('pending');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('gateway')->nullable()->comment('stripe, paypal, etc.');
            $table->string('gateway_transaction_id')->nullable();
            $table->json('gateway_response')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('failed_reason')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index('payment_number');
            $table->index('gateway_transaction_id');
        });
    }

    public function down(): void { Schema::dropIfExists('payments'); }
};
