<script setup>
    //General Imports

    //Props
    const props = defineProps({
        label: [String, Number],
        disabled: Boolean,
        /**
         * True when this sits inside an <Link>. The anchor is then the interactive element and
         * carries the focus, so render a plain div - a <button> nested in an <a> is invalid markup
         * and would add a second tab stop for the one action.
         */
        insideLink: Boolean,
    });

    //Methods
    function getClass(){
        let getClass = "inline-flex h-8 w-full select-none items-center justify-center gap-1.5 rounded-lg border px-2.5 text-xs font-semibold transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-400 focus-visible:ring-offset-1";

        //Disabled
        if(props.disabled){
            getClass = getClass + " cursor-not-allowed border-gray-200 bg-gray-100 text-gray-400";
        }
        else{
            getClass = getClass + " cursor-pointer border-green-700 bg-green-700 text-white shadow-sm hover:border-green-800 hover:bg-green-800";
        }

        return getClass;
    }
</script>

<template>
    <!-- The "move this along" action - always the loudest button on a card -->
    <div
        v-if="insideLink"
        :class="getClass()"
    >
        <span class="truncate">{{label}}</span>
        <i class="fa-regular fa-circle-right text-sm"></i>
    </div>
    <button
        v-else
        type="button"
        :disabled="disabled"
        :class="getClass()"
    >
        <span class="truncate">{{label}}</span>
        <i class="fa-regular fa-circle-right text-sm"></i>
    </button>
</template>
