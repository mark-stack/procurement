<script setup>
    //General Imports
    import {Link} from "@inertiajs/vue3";

    //Component Imports
    //...

    //Props
    const props = defineProps({
        route: String,
        label: String,
        icon: String,
        alert: {
            type: Boolean,
            default: false,
        },
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
    //...

</script>

<template>
    <Link
        :class="alert ? 'font-extrabold text-red-500' : 'font-medium text-gray-600'"
        class="flex items-center px-3 py-2 transition-colors duration-300 transform rounded-lg hover:bg-gray-100 hover:text-gray-700"
        :href="route"
    >
        <i :class="icon"></i>
        <span class="mx-2 text-sm">{{label}}</span>
    </Link>
</template>

