<script setup>
    //General Imports
    import {Head, Link} from '@inertiajs/vue3';
    import {computed} from 'vue';

    //Component Imports
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

    //Shared Imports
    import {formatPlanAmount} from '@/Shared/useBilling.js';

    //Props
    const props = defineProps({
        plan: Object,
        invoiceEmail: String,
        businessName: String,
    });

    //Form
    //...

    //Shared data
    //...

    //Variables
    //...

    //Shared Methods
    //...

    //Methods
    /*
     * A prefilled email rather than a form that posts somewhere. An invoice needs a purchase order
     * number, an ABN and a billing address that nobody has asked this business for yet, and the
     * fastest way to collect all three is a reply-able email from the person who has them.
     */
    const mailto = computed(() => {
        const subject = `Invoice request: ${props.plan.name} - SteelNesting.com.au`;

        const body = [
            `Please invoice us for the ${props.plan.name} plan (${formatPlanAmount(props.plan)} per ${props.plan.interval}, ex GST).`,
            '',
            `Business: ${props.businessName ?? ''}`,
            'Billing contact:',
            'Billing address:',
            'ABN:',
            'Purchase order number (if you need one on the invoice):',
        ].join('\n');

        return `mailto:${props.invoiceEmail}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
    });
</script>

<template>
    <Head title="Request an invoice" />

    <AuthenticatedLayout>
        <div class="max-w-2xl px-4 py-10 mx-auto overflow-y-auto">

            <Link :href="route('billing.index')" class="text-sm text-gray-500 underline hover:text-gray-700">
                Back to billing
            </Link>

            <h1 class="mt-4 text-2xl font-bold text-gray-900">Request an invoice</h1>

            <div class="p-5 mt-6 bg-white border rounded-lg shadow-sm">
                <p class="text-sm font-bold tracking-wider uppercase">{{ plan.name }}</p>
                <p class="mt-1 text-4xl font-extrabold text-gray-900">
                    {{ formatPlanAmount(plan) }}<span class="text-lg font-bold">/{{ plan.interval }}</span>
                </p>
                <p class="text-sm font-light text-gray-500">ex GST</p>

                <p class="mt-5 text-sm text-gray-700">
                    An invoice is raised by hand and settled by bank transfer. Send the details below and
                    it will be issued, then your account is switched over - no card needed, and nothing
                    changes about what you can do in the meantime.
                </p>

                <a
                    :href="mailto"
                    class="inline-flex items-center justify-center w-full h-12 px-6 mt-6 font-medium tracking-wide text-white transition duration-200 rounded shadow-md bg-deep-purple-accent-400 hover:bg-deep-purple-accent-500"
                >
                    Email the request
                </a>

                <p class="mt-3 text-xs text-center text-gray-500">
                    Or write to <a :href="`mailto:${invoiceEmail}`" class="underline">{{ invoiceEmail }}</a> yourself.
                </p>
            </div>

        </div>
    </AuthenticatedLayout>
</template>
