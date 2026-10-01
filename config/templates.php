<?php

/*
 * Import templates: the records that say how a customer's bill of materials is read.
 *
 * A template used to be something only we could write. An upload that matched none of them told the
 * customer to email the file to support, and somebody here read it, filled in the templates form and
 * pressed Activate - which is why a new signup sat on an onboarding page for up to two business days
 * before it could import anything at all.
 *
 * It now writes its own. An upload that matches nothing is described by App\Services\
 * TemplateProposalService, the proposal is run through the real importer by App\Services\
 * TemplateTestService, and if every check that would stop an admin saving it passes, the template is
 * recorded and the file imports. The settings here bound that: how often one upload may spend money
 * at OpenAI, and how long a failed attempt's copy of a customer's spreadsheet is kept.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Learning a template from an upload that matches nothing
    |--------------------------------------------------------------------------
    */

    'learning' => [

        /*
         * The whole feature's off switch.
         *
         * False puts the customer back on the old message - that we have been sent the file and are
         * on it - without asking OpenAI anything. The attempt is still recorded, so a business
         * uploading formats we cannot read is still visible from the admin side; it just is not read
         * automatically. Here so that a bad month at the API, or a customer whose sheets keep
         * producing wrong templates, can be dealt with without a deploy.
         */
        'enabled' => (bool) env('TEMPLATE_LEARNING_ENABLED', true),

        /*
         * How many attempts one business may make per hour.
         *
         * Each attempt is two OpenAI calls and a full extraction, and the thing that triggers it is
         * a customer pressing upload - so without a ceiling, somebody retrying the same unreadable
         * file twenty times is twenty times the cost for the same answer. Successes count too: a
         * business that has legitimately just taught us four formats in an hour is also the shape of
         * a business about to teach us four wrong ones.
         *
         * Over the ceiling the upload is refused the way an unreadable one is, and the attempt is
         * recorded as THROTTLED so that the retrying is visible rather than silently absorbed.
         */
        'hourly_limit' => (int) env('TEMPLATE_LEARNING_HOURLY_LIMIT', 5),

        /*
         * Days a failed attempt's copy of the customer's spreadsheet is kept.
         *
         * It is held for one reason: so an admin can reproduce the failure and finish the template
         * by hand. That reason expires - an attempt nobody has picked up in a month is not about to
         * be picked up, and the file is a customer's project data. The row survives the deletion and
         * still says what went wrong; only the spreadsheet goes. See templates:prune-samples.
         */
        'sample_retention_days' => (int) env('TEMPLATE_LEARNING_SAMPLE_RETENTION_DAYS', 30),
    ],

];
