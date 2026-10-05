<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Starter data only. Rows are ordinary editable records: admins can change,
 * deactivate or reorder them, and no code depends on the seeded wording.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $categories = [
            ['order', 'Order / Delivery Issue', ['order', 'transaction'], [
                'My order is still processing',
                'Customer has not received the data',
                'My order failed',
                'I was charged but the order did not complete',
                'I entered the wrong recipient number',
                'Other order issue',
            ]],
            ['payment', 'Payment Issue', ['transaction', 'wallet_topup', 'order'], [
                'I made a payment but my wallet was not credited',
                'A payment was deducted twice',
                'My payment failed',
                'Other payment issue',
            ]],
            ['wallet', 'Wallet / Balance Issue', ['wallet_topup', 'withdrawal'], [
                'My wallet balance is incorrect',
                'I made a payment but my wallet was not credited',
                'Other wallet issue',
            ]],
            ['withdrawal', 'Withdrawal Issue', ['withdrawal'], [
                'My withdrawal is still pending',
                'My withdrawal failed',
                'I have not received my withdrawal',
                'My withdrawal balance looks incorrect',
                'Other withdrawal issue',
            ]],
            ['product', 'Product / Pricing Issue', [], ['A product price looks wrong', 'A product is missing', 'Other product issue']],
            ['account', 'Account Issue', [], ['I cannot log in', 'I want to change my account details', 'Other account issue']],
            ['reseller', 'Reseller Issue', ['order'], ['My reseller product is not working', 'Reseller earnings look wrong', 'Other reseller issue']],
            ['result_checker', 'Result Checker Issue', ['result_checker_order'], ['A customer did not receive their PIN', 'A PIN is not working', 'Other result checker issue']],
            ['afa', 'AFA Registration Issue', ['afa_registration'], ['An AFA registration is still pending', 'An AFA registration failed', 'Other AFA issue']],
            ['technical', 'Technical Problem', [], ['A page is not loading', 'Something is not working correctly', 'Other technical problem']],
            ['other', 'Other', [], []],
        ];

        foreach ($categories as $i => [$slug, $name, $related, $issues]) {
            DB::table('support_categories')->insert([
                'slug' => $slug,
                'name' => $name,
                'related_types' => json_encode($related),
                'common_issues' => json_encode($issues),
                'is_active' => true,
                'sort_order' => ($i + 1) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $orderCategory = DB::table('support_categories')->where('slug', 'order')->value('id');
        $withdrawalCategory = DB::table('support_categories')->where('slug', 'withdrawal')->value('id');

        $replies = [
            [null, 'Ask for Order ID', 'Please provide the Order ID for the transaction you are referring to.'],
            [$orderCategory, 'Order is processing', 'Your order is currently being processed. Please allow some time for the provider to complete delivery.'],
            [$withdrawalCategory, 'Withdrawal pending', 'Your withdrawal is being processed. Payouts can take some time to reach your Mobile Money account. Please check again shortly.'],
            [null, 'Resolved - closing', 'We have resolved this issue. If you still need help, please reply to this conversation.'],
        ];

        foreach ($replies as $i => [$categoryId, $title, $body]) {
            DB::table('support_quick_replies')->insert([
                'category_id' => $categoryId,
                'title' => $title,
                'body' => $body,
                'is_active' => true,
                'sort_order' => ($i + 1) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('support_quick_replies')->delete();
        DB::table('support_categories')->delete();
    }
};
