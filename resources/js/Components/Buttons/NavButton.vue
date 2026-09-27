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
        //Logging out is not the same kind of click as opening a page, so it does not hover the same
        danger: {
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
    /*
     * A destructive action behind a nav item is one stray click from running, so it asks first.
     *
     * This has to be Inertia's own onBefore prop rather than a @click listener. Link renders
     * h(tag, {...attrs, ...{onClick}}), spreading its internal onClick AFTER the inherited
     * attrs, so a parent's @click is overwritten and never runs: the confirm was silently
     * skipped and one click on "Update Materials" rewrote the whole platform catalogue.
     *
     * Inertia cancels the visit on an exact false, so every other path returns undefined.
     */
    const onBefore = () => {
        if (props.confirm && !window.confirm(props.confirm)) {
            return false;
        }
    };

</script>

<template>
    <Link
        :class="[
            alert ? 'font-extrabold text-red-500' : 'font-medium text-gray-600',
            danger ? 'hover:bg-red-50 hover:text-red-700' : 'hover:bg-gray-100 hover:text-gray-700',
        ]"
        class="flex items-center w-full gap-2 px-3 py-2 text-sm text-left transition-colors duration-200 rounded-lg"
        :href="route"
        :method="method"
        :as="method === 'get' ? 'a' : 'button'"
        :on-before="onBefore"
    >
        <!-- A fixed width, so labels line up however wide the icon glyph happens to be -->
        <i :class="icon" class="w-4 text-center shrink-0"></i>
        <span>{{label}}</span>
    </Link>
</template>
