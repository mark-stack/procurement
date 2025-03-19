<script setup>
    //Props
    const props = defineProps({
        label: String,
        fullWidth: Boolean,
        disabled: Boolean,
    });

    //Methods
    function getClass(){
        let getClass = "text-center h-5 px-2 text-xs font-semibold bg-gray-100 rounded-full border-[1px] border-gray-200";

        //Width
        if(props.fullWidth){
            getClass = getClass + " w-full";
        }

        //Disabled
        if(props.disabled){
            getClass = getClass + " text-gray-400";
        }
        else{
            getClass = getClass + " text-gray-500 hover:bg-red-100";
        }

        return getClass;
    }
</script>

<template>
    <button
        :disabled="disabled"
        :class="getClass()"
    >
        {{label}}
    </button>
</template>
