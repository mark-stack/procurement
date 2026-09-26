<script setup>
    //General Imports
    import {Link, usePage} from '@inertiajs/vue3';
    import {computed} from 'vue';

    //Component Imports
    //...

    //Props
    //...

    //Form
    //...

    //Shared data
    //Impersonating replaces the session identity outright, so without this the page is
    //indistinguishable from being logged in as the customer
    const impersonating = computed(() => usePage().props.auth?.impersonating);
    const user = computed(() => usePage().props.auth?.user);

    //Variables
    //...

    //Shared Methods
    //...

    //Methods
    //...
</script>

<template>
    <div
        v-if="impersonating"
        role="alert"
        class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm font-medium text-white bg-red-600"
    >
        <p>
            You are viewing the site as
            <span class="font-bold">{{ user?.name }}</span>
            <span v-if="user?.email"> ({{ user.email }})</span>.
            Anything you do here is recorded against their account.
        </p>
        <Link
            :href="route('admin.stop.impersonating')"
            method="post"
            as="button"
            type="button"
            class="px-3 py-1 font-bold text-red-700 bg-white rounded shrink-0 hover:bg-red-50"
        >
            Stop impersonating
        </Link>
    </div>
</template>
