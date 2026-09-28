<script setup>
    //General Imports
    import {Link} from '@inertiajs/vue3';

    //Component Imports
    //...

    //Props
    //...

    //Form
    //...

    //Shared data
    //Every condition and every word of it lives in the composable, so that AuthenticatedLayout can
    //ask whether this bar is showing without re-deciding it - see useBilling.js
    import useBilling from '@/Shared/useBilling.js';
    const {banner} = useBilling();

    //Variables
    //Matches the impersonation banner's height, which is what the layout subtracts for it
    const base = 'flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm font-medium text-white';

    //Shared Methods
    //...

    //Methods
    //...
</script>

<template>
    <div
        v-if="banner"
        role="alert"
        :class="[base, banner.tone === 'danger' ? 'bg-red-600' : 'bg-amber-600']"
    >
        <p>
            <span class="font-bold">{{ banner.message }}</span>
            <span v-if="banner.detail"> {{ banner.detail }}</span>
        </p>
        <Link
            :href="route('billing.index')"
            class="px-3 py-1 font-bold rounded shrink-0 bg-white hover:bg-gray-50"
            :class="banner.tone === 'danger' ? 'text-red-700' : 'text-amber-700'"
        >
            {{ banner.action }}
        </Link>
    </div>
</template>
