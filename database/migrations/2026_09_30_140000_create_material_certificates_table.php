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
        /*
         * The mill certificate itself, as a file, rather than a note of where to find one.
         *
         * orders.material_cert_numbers stays exactly as it is and keeps its meaning: a written
         * reference - heat numbers, a cert number, the folder the paper copy lives in. What it never
         * was is the certificate, and the column it is edited in was labelled "Material Certs", so
         * people typed a filename into it and believed the PDF had been attached.
         *
         * Both are now first-class and either one on its own counts as certified: some merchants
         * email a PDF, some print a number on the docket, and a yard that has one of the two is
         * traceable. Anything reading "does this order have certs" has to ask about both - see
         * Order::scopeHasMaterialCerts.
         *
         * Rows are never rewritten in place: a replacement cert is a new row and a wrong one is
         * deleted, so the trail behind an offcut is a list of what was actually received.
         */
        Schema::create('material_certificates', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            //Who attached it. Nullable so losing a user account never takes a certificate with it
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            //Where it sits on the private disk. Never exposed - downloads go through the controller
            $table->string('path');
            //What it was called when it arrived, which is what every screen shows
            $table->string('original_filename');
            $table->string('mime_type', 191)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('material_certificates');
    }
};
