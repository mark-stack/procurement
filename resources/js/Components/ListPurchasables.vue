<script setup>
    //General Imports
    //...

    //Component Imports
    //...

    //Props
    const props = defineProps({
        nestingAlgo: String,
        measurementUnit: String,
        list: Object,
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
    function getHeading(){
        let heading = "";

        if(props.nestingAlgo === "METERAGE"){
            heading = "Lengths:";
        }

        if(props.nestingAlgo === "AREA"){
            heading = "Sizes:";
        }

        if(props.nestingAlgo === "BUNDLE"){
            heading = "Pack Quantities:";
        }

        return heading;
    }

    function suffix(){
        let suffix = "";

        if(props.nestingAlgo === "METERAGE"){
            suffix = " mm";
        }

        if(props.nestingAlgo === "AREA"){
            suffix = " LxW";
        }

        if(props.nestingAlgo === "BUNDLE"){
            suffix = " off";
        }

        return suffix;
    }
</script>

<template>
    <h2 class="font-semibold">{{ getHeading() }}</h2>
    <ul class="text-sm">
        <li v-for="item in list">{{parseInt(item).toLocaleString()}}{{ suffix() }}</li>
    </ul>
</template>
