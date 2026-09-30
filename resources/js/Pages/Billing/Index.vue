<script setup>
    //General Imports
    import {Head, Link, useForm} from '@inertiajs/vue3';
    import {computed} from 'vue';

    //Component Imports
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

    //Shared Imports
    import {daysPhrase, formatPlanAmount} from '@/Shared/useBilling.js';

    //Props
    const props = defineProps({
        //App\Billing\SubscriptionState::toArray()
        billing: Object,
        //The plans the live provider can actually take money for. Empty is a normal state: it means
        //this installation bills by invoice, and the page says so rather than showing nothing.
        plans: Array,
        //Whether Subscribe leads to a card form at the provider, or to an invoice request here
        hostedCheckout: Boolean,
        invoiceEmail: String,
        trialDays: Number,
        //"success" or "cancelled" on the way back from a hosted checkout
        checkout: String,
    });

    //Form
    const form = useForm({
        plan: null,
    });

    //Shared data
    //...

    //Variables
    /*
     * The same four lines the pricing page on the landing site sells. Repeated here rather than put
     * in config/billing.php because it is marketing copy, not billing configuration - if it ever
     * needs to differ per plan it can move, but today every plan includes all of it.
     */
    const includedFeatures = [
        'Unlimited import sources',
        'Unlimited staff',
        'Unlimited nesting',
        'White glove support',
    ];

    //Shared Methods
    //...

    //Methods
    /*
     * A Stripe checkout completes, sends the customer straight back here, and the subscription
     * itself lands a second or two later by webhook. So the state on this page load can still read
     * as lapsed for someone who has just paid. Saying "activating" is the truth; saying "your trial
     * has ended" to someone holding a receipt is not.
     */
    const justPaid = computed(() => props.checkout === 'success' && props.billing.readOnly);

    const statusTone = computed(() => {
        if (props.billing.readOnly) {
            return 'bg-red-50 border-red-200';
        }

        if (props.billing.status === 'PAST_DUE' || props.billing.status === 'CANCELLING') {
            return 'bg-amber-50 border-amber-200';
        }

        return 'bg-white border-gray-200';
    });

    /*
     * What the status panel says under the heading. The trial and a cancellation are both counting
     * down to a date; an active subscription is not, and its next invoice date lives at the
     * provider rather than in this database, so it is not quoted here as a figure that could be
     * stale - the Manage billing button goes to the schedule itself.
     */
    const statusDetail = computed(() => {
        if (justPaid.value) {
            return 'Payment received. Your subscription is being activated - reload in a moment.';
        }

        if (props.billing.onTrial) {
            return `Your free trial ends ${props.billing.daysRemaining <= 0 ? 'today' : `in ${daysPhrase(props.billing.daysRemaining)}`}. No card has been taken.`;
        }

        switch (props.billing.status) {
            case 'ACTIVE':
                return props.billing.source === 'manual'
                    ? 'This account is billed by invoice.'
                    : 'Your subscription is active.';
            case 'PAST_DUE':
                return 'A payment failed and is being retried. Nothing has been switched off yet.';
            case 'CANCELLING':
                return `Cancelled. You keep full access for ${daysPhrase(props.billing.daysRemaining)}.`;
            case 'TRIAL_EXPIRED':
                return `Your ${props.trialDays}-day free trial has ended. The account is read-only until you subscribe.`;
            case 'LAPSED':
                return 'Your subscription has ended. The account is read-only until you subscribe again.';
            default:
                return 'There is no subscription on this account.';
        }
    });

    const intervalLabel = (plan) => {
        switch (plan.interval) {
            case 'week': return '/week';
            case 'month': return '/month';
            case 'year': return '/year';
            default: return `/${plan.interval}`;
        }
    };

    /*
     * Whether this plan ends in a card form or in an invoice request, which is the difference
     * between what its button can honestly say. Two things make it an invoice: the plan is sold
     * that way on purpose (the annual one), or this installation has no provider taking cards at
     * all. Per plan rather than per page, because the two plans on sale differ.
     */
    const paysByCard = (plan) => plan.checkout === 'provider' && props.hostedCheckout;

    /*
     * A yearly plan is sold against fifty-two weeks of the weekly one, so the discount is worked
     * out from the two prices on the page rather than written into the copy - change either figure
     * in config/billing.php and this follows it instead of quoting last quarter's percentage.
     */
    const weeksPerYear = 52;

    const savingsPct = (plan) => {
        if (plan.interval !== 'year') {
            return null;
        }

        const weekly = props.plans.find((p) => p.interval === 'week');

        if (!weekly) {
            return null;
        }

        const fullPrice = weekly.amount * weeksPerYear;
        const saving = Math.round((1 - plan.amount / fullPrice) * 100);

        //Nothing to boast about if it is not actually cheaper
        return saving >= 1 ? saving : null;
    };

    const subscribe = (plan) => {
        form.plan = plan.key;
        form.post(route('billing.checkout'), {preserveScroll: true});
    };
</script>

<template>
    <Head title="Billing" />

    <AuthenticatedLayout>
        <div class="max-w-5xl px-4 py-10 mx-auto overflow-y-auto">

            <h1 class="text-2xl font-bold text-gray-900">Billing</h1>

            <!-- Where this account stands -->
            <div class="p-5 mt-6 border rounded-lg shadow-sm" :class="statusTone">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold tracking-wider text-gray-500 uppercase">Status</p>
                        <p class="text-2xl font-extrabold text-gray-900">
                            {{ justPaid ? 'Activating' : billing.label }}
                        </p>
                        <p class="mt-1 text-sm text-gray-700">{{ statusDetail }}</p>
                        <p v-if="billing.plan" class="mt-1 text-sm text-gray-500">
                            {{ billing.plan.name }} &middot; {{ formatPlanAmount(billing.plan) }}{{ intervalLabel(billing.plan) }} (ex GST)
                        </p>
                    </div>

                    <a
                        v-if="billing.manageable"
                        :href="route('billing.manage')"
                        class="px-4 py-2 text-sm font-bold text-white bg-gray-800 rounded shadow-sm shrink-0 hover:bg-gray-900"
                    >
                        Manage billing
                    </a>
                </div>

                <!-- Read-only is not locked out, and it is worth being explicit about that -->
                <p v-if="billing.readOnly" class="pt-4 mt-4 text-sm text-gray-700 border-t border-red-200">
                    Every project, batch, nesting plan and cutting list you have is still here to open,
                    print and download. What is paused is creating and changing things.
                </p>
            </div>

            <!-- Plans -->
            <div v-if="plans.length" class="mt-10">
                <h2 class="text-lg font-bold text-gray-900">
                    {{ billing.status === 'ACTIVE' || billing.status === 'PAST_DUE' ? 'Change plan' : 'Plans' }}
                </h2>

                <div class="grid max-w-3xl gap-5 mt-4 sm:grid-cols-2">
                    <div
                        v-for="plan in plans"
                        :key="plan.key"
                        class="flex flex-col justify-between p-5 bg-white border rounded shadow-sm"
                    >
                        <div>
                            <div class="flex items-start justify-between gap-2">
                                <p class="text-sm font-bold tracking-wider uppercase">{{ plan.name }}</p>
                                <span
                                    v-if="savingsPct(plan)"
                                    class="px-2 py-1 text-xs font-bold text-green-800 bg-green-100 rounded shrink-0"
                                >
                                    Save {{ savingsPct(plan) }}%
                                </span>
                            </div>
                            <p class="mt-2 text-4xl font-extrabold text-gray-900">
                                {{ formatPlanAmount(plan) }}<span class="text-lg font-bold">{{ intervalLabel(plan) }}</span>
                            </p>
                            <p class="text-sm font-light text-gray-500">
                                ex GST &middot; {{ paysByCard(plan) ? 'paid by card' : 'paid on invoice' }}
                            </p>

                            <ul class="mt-4 space-y-2">
                                <li v-for="feature in includedFeatures" :key="feature" class="flex items-center gap-2">
                                    <i class="text-green-600 fa-solid fa-check"></i>
                                    <span class="text-sm font-medium text-gray-800">{{ feature }}</span>
                                </li>
                            </ul>
                        </div>

                        <div class="mt-6">
                            <!--
                                Wording follows where the button actually goes. Promising a card
                                form and then showing an invoice request, or the other way round, is
                                a small lie the page does not need to tell.
                            -->
                            <button
                                type="button"
                                @click="subscribe(plan)"
                                :disabled="form.processing"
                                class="inline-flex items-center justify-center w-full h-11 px-6 font-medium tracking-wide text-white transition duration-200 bg-green-600 rounded shadow-md hover:bg-green-700 disabled:opacity-50"
                            >
                                <template v-if="form.processing && form.plan === plan.key">Just a moment...</template>
                                <template v-else>{{ paysByCard(plan) ? 'Subscribe' : 'Request an invoice' }}</template>
                            </button>

                            <!-- Redundant where the button above already goes here -->
                            <Link
                                v-if="paysByCard(plan)"
                                :href="route('billing.invoice', {plan: plan.key})"
                                class="block mt-2 text-xs text-center text-gray-500 underline hover:text-gray-700"
                            >
                                Request an invoice instead
                            </Link>
                        </div>
                    </div>
                </div>
            </div>

            <!--
                No plans sellable online. Not an error: it is the manual driver, or a provider whose
                price ids have not been configured yet, and either way an invoice is how to buy.
            -->
            <div v-else class="p-5 mt-10 bg-white border rounded shadow-sm">
                <h2 class="text-lg font-bold text-gray-900">Subscribing</h2>
                <p class="mt-2 text-sm text-gray-700">
                    Subscriptions on this account are set up by invoice. Email
                    <a :href="`mailto:${invoiceEmail}?subject=Subscription%20for%20SteelNesting.com.au`" class="font-bold underline">{{ invoiceEmail }}</a>
                    and one will be raised for you.
                </p>
            </div>

        </div>
    </AuthenticatedLayout>
</template>
