<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Email domains allowed through the personal-address check
    |--------------------------------------------------------------------------
    |
    | App\Rules\BusinessEmailDomain refuses consumer webmail, ISP mailboxes and throwaway
    | domains at registration, because the email domain is what decides which Business an
    | account joins - see the rule for the whole argument. That is exactly the wrong
    | behaviour on a development machine, where the addresses being typed are whatever a
    | form filler invented and there is nobody to share a business with.
    |
    | Comma-separated domains, matched exactly, e.g. "mailinator.com,yopmail.com".
    |
    | Ignored outright when the app is in production, which is the point of it being here
    | rather than a line deleted from the rule's lists. A domain named in a production
    | .env by accident - a copied file, a reused deployment template - does nothing, so
    | the only way to weaken the live check is to edit the rule and mean it.
    |
    */

    'allowed_email_domains' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('REGISTRATION_ALLOWED_EMAIL_DOMAINS', '')),
    ))),

];
