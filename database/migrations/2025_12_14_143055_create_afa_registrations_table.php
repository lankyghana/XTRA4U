<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('afa_registrations', function (Blueprint $table) {
            $table->id();
            
            // Vendor relationship
            $table->foreignId('vendor_id')->constrained()->onDelete('cascade');
            $table->foreignId('reseller_vendor_id')->nullable()->constrained('vendors')->onDelete('set null');
            
            // Customer details from form
            $table->string('full_name');
            $table->string('id_number'); // Ghana Card, Driver's License, or Voters ID
            $table->string('id_type')->default('ghana_card'); // ghana_card, drivers_license, voters_id
            $table->date('date_of_birth');
            $table->string('phone_number');
            $table->string('location');
            $table->string('region');
            $table->string('occupation')->nullable();
            
            // Payment details
            $table->decimal('amount', 10, 2);
            $table->decimal('vendor_price', 10, 2)->nullable(); // Vendor's set price
            $table->decimal('platform_commission', 10, 2)->default(0);
            $table->decimal('vendor_earning', 10, 2)->default(0);
            $table->decimal('reseller_earning', 10, 2)->default(0);
            $table->boolean('is_reseller_order')->default(false);
            
            // Payment tracking
            $table->string('payment_reference')->nullable();
            $table->string('payment_gateway')->nullable();
            $table->enum('payment_status', ['pending', 'completed', 'failed', 'refunded'])->default('pending');
            $table->timestamp('payment_completed_at')->nullable();
            
            // Order status
            $table->enum('status', ['pending', 'processing', 'approved', 'completed', 'rejected', 'cancelled'])->default('pending');
            $table->text('admin_notes')->nullable(); // Vendor notes on the registration
            $table->text('rejection_reason')->nullable();
            
            // Reference number for tracking
            $table->string('reference')->unique();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['vendor_id', 'status']);
            $table->index('phone_number');
            $table->index('reference');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('afa_registrations');
    }
};
