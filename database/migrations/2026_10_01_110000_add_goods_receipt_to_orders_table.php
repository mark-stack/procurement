<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The delivery, as a record rather than a tick.
     *
     * orders carried three booleans - order_sent, order_confirmation_received, is_delivered - and not
     * one date between them. So the board could say steel had arrived and nothing could say when, who
     * booked it in, what the docket said, or whether anybody had looked at what came off the truck.
     * OrderMarkDeliveredController set one flag and returned.
     *
     * ISO 9001 8.6 does not accept that: product is not released for use until the planned verification
     * is complete, and the documented information on release - including the person authorising it - is
     * retained. 8.4.3 is the other half, since the thing being verified here is an externally provided
     * process. A checkbox retains neither.
     *
     * The three timestamps beside the three booleans rather than instead of them. Every screen, scope
     * and gate in the application reads the booleans - Batch::newStockOrdersWithCertificates filters on
     * order_sent, Business::availableOffcuts keys on is_delivered, SetOrderSentForBatchSupplierGroup
     * holds "one sent order per supplier group" - and rewriting all of that to read a nullable date is
     * a far bigger change than the one being asked for. The dates say when; the flags go on saying
     * what.
     *
     * Nothing is backfilled. An order already marked delivered gets a null received_at, which reads as
     * "arrived, and no receipt was recorded" - true, and the whole point. updated_at was available and
     * would have been a plausible-looking guess at a date somebody checked the steel, which is the one
     * thing a record like this must never contain. Order::hasReceiptRecord() is what tells the two
     * apart.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            /*
             * When the order left. Written by UpdateOrderSentStatus alongside the flag it dates.
             */
            $table->timestamp('order_sent_at')->nullable()->after('order_sent');

            /*
             * When the merchant acknowledged it.
             *
             * Added knowing that nothing writes it yet, and saying so rather than leaving the next
             * reader to discover it: order_confirmation_received is a column from the first orders
             * migration that no controller and no screen has ever set - only a test helper. The flag is
             * half a feature, and the date beside it is here so that the half which is missing is a
             * screen rather than a migration. Until then it stays null, which reads correctly as "no
             * confirmation recorded".
             */
            $table->timestamp('order_confirmation_received_at')
                ->nullable()
                ->after('order_confirmation_received');

            //When the steel was booked in, which is not the same as when it turned up
            $table->timestamp('received_at')->nullable()->after('is_delivered');

            /*
             * Who booked it in. The authorisation 8.6 asks for, and nullable on delete for the reason
             * material_certificates.user_id is: losing an account must not take the receipt with it.
             */
            $table->foreignId('received_by_user_id')
                ->nullable()
                ->after('received_at')
                ->constrained('users')
                ->nullOnDelete();

            //What the driver handed over, which is how this receipt is found again in a paper file
            $table->string('delivery_docket_number')->nullable()->after('received_by_user_id');

            /*
             * The two checks, each one tri-state.
             *
             * Nullable rather than defaulting false, for the reason products.certificates is: "no" and
             * "nobody answered" are different facts, and a boolean with a default turns every delivery
             * recorded before this existed into one somebody had checked and failed.
             */
            $table->boolean('quantity_verified')->nullable()->after('delivery_docket_number');
            $table->boolean('grade_verified')->nullable()->after('quantity_verified');

            /*
             * What was wrong with it, if anything - see App\Enums\GoodsReceiptNonconformanceEnums.
             *
             * A string holding the enum value rather than a database enum, like every other enum column
             * in this schema (offcuts.removed_reason, quotes.supplier_category): adding a case to a
             * database enum is a migration, and a reason this application wants to add should not be.
             */
            $table->string('receipt_nonconformance')->nullable()->after('grade_verified');
            $table->text('receipt_note')->nullable()->after('receipt_nonconformance');

            //Every read of this is "which deliveries have no receipt behind them"
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['received_at']);
            $table->dropConstrainedForeignId('received_by_user_id');
            $table->dropColumn([
                'order_sent_at',
                'order_confirmation_received_at',
                'received_at',
                'delivery_docket_number',
                'quantity_verified',
                'grade_verified',
                'receipt_nonconformance',
                'receipt_note',
            ]);
        });
    }
};
