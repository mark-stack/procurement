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
        class="flex items-center px-3 py-2 text-gray-600 transition-colors duration-300 transform rounded-lg dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 dark:hover:text-gray-200 hover:text-gray-700"
        :href="route"
    >
        <i :class="icon"></i>
        <span class="mx-2 text-sm font-medium">{{label}}</span>
    </Link>
</template>

