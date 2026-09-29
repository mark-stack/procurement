<script setup>
    //Props
    const props = defineProps({
        label: String,
        fullWidth: Boolean,
        /*
         * Editing a project is the owner's call, so this button greys out on a colleague's
         * project the way CardButtonRed does for Archive beside it - the two sit in the same row
         * and used to disagree about who the card belonged to.
         */
        disabled: Boolean,
        //Optional - lets a disabled button say why it is disabled
        title: String,
    });

    //Methods
    function getClass(){
        let getClass = "inline-flex h-8 items-center justify-center rounded-lg border px-2.5 text-xs font-semibold transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1";

        //Width
        if(props.fullWidth){
            getClass = getClass + " w-full";
        }

        //Disabled
        if(props.disabled){
            getClass = getClass + " cursor-not-allowed border-gray-200 bg-gray-50 text-gray-400";
        }
        else{
            getClass = getClass + " border-gray-300 bg-white text-gray-600 shadow-sm hover:border-orange-200 hover:bg-orange-50 hover:text-orange-700 focus-visible:ring-orange-400";
        }

        return getClass;
    }
</script>

<template>
    <button
        type="button"
        :disabled="disabled"
        :title="title"
        :class="getClass()"
    >
        <span class="truncate">{{label}}</span>
    </button>
</template>
