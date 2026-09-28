import {computed} from "vue";
import {usePage} from "@inertiajs/vue3";

/**
 * The single source of truth for what the front end says about billing.
 *
 * Shared rather than read straight out of the page props, because three separate places need the
 * same answer and have to agree on it: the banner draws it, the nav highlights Billing off it, and
 * AuthenticatedLayout has to know whether the banner is there at all so it can give back its height
 * (the fixed-height grid under the nav overflows by exactly the banner otherwise, which is the bug
 * the impersonation banner already had to fix).
 *
 * The shape of props.billing is App\Billing\SubscriptionState::toArray(), which is deliberately
 * provider-neutral: nothing here knows whether Stripe, Paddle or a bank transfer is behind it.
 */
export default function useBilling(){
    const billing = computed(() => usePage().props.billing ?? null);

    //Writes are refused, everything is still readable and printable
    const readOnly = computed(() => billing.value?.readOnly === true);

    const daysRemaining = computed(() => billing.value?.daysRemaining ?? null);

    /*
     * A trial only starts nagging in its last stretch. Counting down from day one of a month would
     * train people to ignore the bar, so by the time it does appear it means something.
     */
    const trialEndingSoon = computed(() =>
        billing.value?.onTrial === true && daysRemaining.value !== null && daysRemaining.value <= 10
    );

    const paymentFailed = computed(() => billing.value?.status === "PAST_DUE");

    const cancelling = computed(() => billing.value?.status === "CANCELLING");

    /*
     * Whether Billing is worth a look right now, which is also what turns the nav item red.
     */
    const needsAttention = computed(() =>
        readOnly.value || paymentFailed.value || trialEndingSoon.value
    );

    /**
     * What the bar says, or null for no bar. One object so the component has no logic of its own
     * and the layout can ask "is there a bar" without duplicating the conditions.
     */
    const banner = computed(() => {
        if (!billing.value) {
            return null;
        }

        if (readOnly.value) {
            return {
                tone: "danger",
                message: billing.value.everPaid
                    ? "Your subscription has ended, so this account is read-only."
                    : "Your free trial has ended, so this account is read-only.",
                detail: "Everything you have is still here to read and print. Subscribe to make changes again.",
                action: "Subscribe",
            };
        }

        if (paymentFailed.value) {
            return {
                tone: "warning",
                message: "Your last payment did not go through.",
                detail: "Nothing has been switched off yet, but it will be if the payment keeps failing.",
                action: "Fix payment",
            };
        }

        if (cancelling.value) {
            return {
                tone: "warning",
                message: "Your subscription is cancelled.",
                detail: daysRemaining.value !== null
                    ? `You keep full access for ${daysPhrase(daysRemaining.value)}.`
                    : "You keep full access until the end of the period you have paid for.",
                action: "Resubscribe",
            };
        }

        if (trialEndingSoon.value) {
            return {
                tone: "warning",
                message: `Your free trial ends in ${daysPhrase(daysRemaining.value)}.`,
                detail: "Subscribe before then and nothing changes.",
                action: "See plans",
            };
        }

        return null;
    });

    return {billing, readOnly, daysRemaining, needsAttention, banner};
}

/**
 * "3 days", "1 day", "today" - a countdown that hits zero should not read "in 0 days".
 */
export function daysPhrase(days){
    if (days === null || days === undefined) {
        return "";
    }

    if (days <= 0) {
        return "today";
    }

    return days === 1 ? "1 day" : `${days} days`;
}

/**
 * Plan amounts arrive as minor units and an ISO currency, so the browser formats them rather than
 * the server guessing at a symbol. Every price quoted in this product excludes GST.
 */
export function formatPlanAmount(plan){
    if (!plan) {
        return "";
    }

    return new Intl.NumberFormat("en-AU", {
        style: "currency",
        currency: plan.currency.toUpperCase(),
        //A whole-dollar price reads better without the cents, and all of them currently are
        minimumFractionDigits: plan.amount % 100 === 0 ? 0 : 2,
    }).format(plan.amount / 100);
}
