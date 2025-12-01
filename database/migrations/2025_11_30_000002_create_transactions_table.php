<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTransactionsTable extends Migration
{
    public function up()
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('vendor_id');
            $table->string('recipient_phone');
            $table->decimal('amount', 10, 2);
            $table->decimal('commission_amount', 10, 2);
            $table->decimal('vendor_earning', 10, 2);
            $table->string('payment_status')->default('pending');
            $table->timestamp('timestamp')->useCurrent();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
            $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('transactions');
    }
}
