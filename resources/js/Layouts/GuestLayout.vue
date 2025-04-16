<script setup>
    //General imports
    import { Link } from '@inertiajs/vue3';

    //Component imports
    //...

    //Props
    const props = defineProps({
        wider: Boolean,
    });
</script>

<template>
    <div
        class="flex min-h-screen flex-col items-center bg-gray-100 pt-6 sm:justify-center sm:pt-0"
    >
        <div>
            <Link href="/" aria-label="Company" title="Company" class="inline-flex items-center mr-8">
                <span class="ml-2 text-3xl font-bold tracking-wide text-gray-900">SteelNesting.com.au</span>
            </Link>
        </div>

        <div
            :class="wider ? 'sm:max-w-3xl' : 'sm:max-w-md'"
            class="mt-6 w-full overflow-hidden bg-white px-6 py-4 shadow-md sm:rounded-lg"
        >
            <slot />
        </div>
    </div>
</template>
