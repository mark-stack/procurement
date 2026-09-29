<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An account here belongs to a company, not to a person.
 *
 * Registration finds or creates a Business keyed on the email domain, so everyone whose
 * address ends in acmesteel.com.au lands in the one business and shares its projects,
 * templates, offcuts and subscription. That is the feature - a fabricator's second
 * estimator signs up and is already in the right place, with no invite to chase.
 *
 * It is also why a personal address cannot be allowed through. Two strangers who both
 * happen to use Gmail are not colleagues, but the domain says they are: they would be
 * dropped into one shared "gmail.com" business, reading each other's cutting lists. The
 * first one to sign up also burns the free trial and the subscription for everyone who
 * follows, because both hang off the business rather than the user.
 *
 * Hence the lists below. Consumer webmail is the obvious half; the ISP mailboxes matter
 * just as much here, because a Bigpond or Optus address is what a one-man fabrication
 * shop in Australia is most likely to type in. Throwaway domains are last: a trial per
 * business is only worth anything if a new business costs something to make.
 *
 * Matching is on the exact domain. Resolving @mail.acme.com back to acme.com needs the
 * public suffix list to do safely - .com.au alone makes naive truncation wrong - and a
 * wrong answer here merges two real companies, which is the thing being prevented.
 */
class BusinessEmailDomain implements ValidationRule
{
    /**
     * Consumer webmail.
     *
     * @var list<string>
     */
    private const WEBMAIL = [
        'gmail.com', 'googlemail.com',
        'hotmail.com', 'hotmail.co.uk', 'hotmail.com.au',
        'outlook.com', 'outlook.com.au', 'live.com', 'live.com.au', 'msn.com',
        'yahoo.com', 'yahoo.com.au', 'yahoo.co.uk', 'ymail.com', 'rocketmail.com',
        'icloud.com', 'me.com', 'mac.com',
        'aol.com', 'aim.com',
        'protonmail.com', 'protonmail.ch', 'proton.me', 'pm.me',
        'gmx.com', 'gmx.net', 'gmx.de', 'mail.com', 'email.com',
        'zoho.com', 'yandex.com', 'yandex.ru', 'mail.ru',
        'fastmail.com', 'fastmail.fm', 'hey.com',
        'tutanota.com', 'tutanota.de', 'tuta.io',
        'qq.com', '163.com', '126.com', 'sina.com',
    ];

    /**
     * ISP mailboxes. Mostly Australian, because that is who buys this.
     *
     * @var list<string>
     */
    private const ISP = [
        'bigpond.com', 'bigpond.com.au', 'bigpond.net.au', 'telstra.com', 'telstra.com.au',
        'optusnet.com.au', 'optus.com.au',
        'iinet.net.au', 'westnet.com.au', 'internode.on.net', 'adam.com.au',
        'tpg.com.au', 'iprimus.com.au', 'dodo.com.au', 'exetel.com.au',
        'ozemail.com.au', 'exemail.com.au', 'aussiebroadband.com.au',
        'xtra.co.nz', 'clear.net.nz',
        'btinternet.com', 'sky.com', 'virginmedia.com',
        'comcast.net', 'verizon.net', 'sbcglobal.net', 'att.net', 'cox.net', 'charter.net',
    ];

    /**
     * Throwaway addresses.
     *
     * @var list<string>
     */
    private const DISPOSABLE = [
        'mailinator.com', 'guerrillamail.com', 'sharklasers.com', 'yopmail.com',
        '10minutemail.com', 'tempmail.com', 'temp-mail.org', 'throwawaymail.com',
        'trashmail.com', 'dispostable.com', 'maildrop.cc', 'getnada.com',
        'fakeinbox.com', 'mailnesia.com', 'mintemail.com', 'moakt.com',
        'emailondeck.com', 'spamgourmet.com', 'discard.email', 'mailedu.de',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $domain = User::domainFromEmail($value);

        /*
         * Unreadable, so there is nothing to judge. The "email" rule alongside this one has
         * already objected, and two messages about one typo is one too many.
         */
        if ($domain === null) {
            return;
        }

        if (in_array($domain, self::blockedDomains(), true)) {
            $fail('Please use your work email address. :domain is a personal email provider, and an account here is shared by everyone at your company.')
                ->translate(['domain' => $domain]);
        }
    }

    /**
     * @return list<string>
     */
    private static function blockedDomains(): array
    {
        return [...self::WEBMAIL, ...self::ISP, ...self::DISPOSABLE];
    }
}
