<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrdersTable extends Migration
{
    public function up()
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('recipient_phone_number');
            $table->string('mobile_money_number');
            $table->string('service_purchased');
            $table->decimal('amount_paid', 10, 2);
            $table->unsignedBigInteger('vendor_id');
            $table->enum('status', ['Processing', 'Completed', 'Failed'])->default('Processing');
            $table->timestamps();

            $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('orders');
    }
}
