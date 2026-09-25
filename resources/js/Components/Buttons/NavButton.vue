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
        method: {
            type: String,
            default: "get",
        },
        confirm: {
            type: String,
            default: null,
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
    //A destructive action behind a nav item is one stray click from running, so it asks first
    const onClick = (event) => {
        if (props.confirm && !window.confirm(props.confirm)) {
            event.preventDefault();
        }
    };

</script>

<template>
    <Link
        :class="[
            alert ? 'font-extrabold text-red-500' : 'font-medium text-gray-600',
            method !== 'get' ? 'w-full text-left' : '',
        ]"
        class="flex items-center px-3 py-2 transition-colors duration-300 transform rounded-lg hover:bg-gray-100 hover:text-gray-700"
        :href="route"
        :method="method"
        :as="method === 'get' ? 'a' : 'button'"
        @click="onClick"
    >
        <i :class="icon"></i>
        <span class="mx-2 text-sm">{{label}}</span>
    </Link>
</template>
