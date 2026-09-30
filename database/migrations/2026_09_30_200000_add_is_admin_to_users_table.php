<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who the platform admin is, as a fact about the row rather than a fact about the email in it.
     *
     * isAdmin() used to be `$this->email === config('env.admin_email')`, and a user may change their
     * own email on /profile. So anyone could type the configured address into their own profile and
     * become the platform admin - the master catalogue, every business's templates, and
     * admin.impersonate, which logs in as any user of any business. Rule::unique only stood in the
     * way while a users row already held that address, and BusinessEmailDomain only while it was on a
     * webmail domain; the shipped default happens to be a gmail address, so the hole was closed by
     * accident rather than on purpose.
     *
     * Authority cannot live in a column the user can write. It lives here, where only a migration or
     * a console command can set it.
     *
     * Backfilled from ADMIN_EMAIL so the existing admin keeps their access across this deploy. That
     * is the last time the email decides anything.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('email_verified_at');
        });

        $adminEmail = config('env.admin_email');

        if (is_string($adminEmail) && $adminEmail !== '') {
            DB::table('users')
                ->where('email', $adminEmail)
                ->update(['is_admin' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
