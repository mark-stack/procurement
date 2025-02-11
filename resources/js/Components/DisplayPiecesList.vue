<script setup>
    //General Imports
    //...

    //Component Imports
    //...

    //Props
    const props = defineProps({
        nestingAlgo: String,
        measurementUnit: String,
        pieces: Object,
        lettersProjectArray: Object,
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
    function displayMeterage(piece){
        //"1 off 9 METERS"
        return piece.quantity + " off " + parseInt(piece.length).toLocaleString() + " mm";
    }

    function displayArea(piece){
        return ""; //todo
    }

    function displayBundle(piece){
        //"12 off"
        return piece.quantity + " off";
    }

    function getHeading(){
        let heading = "";

        if(props.nestingAlgo === "METERAGE"){
            heading = "Cut Lengths:";
        }

        if(props.nestingAlgo === "AREA"){
            heading = "Cut Sizes:";
        }

        if(props.nestingAlgo === "BUNDLE"){
            heading = "Quantities:";
        }

        return heading;
    }
</script>

<template>
    <h2 class="font-semibold">{{ getHeading() }}</h2>
    <!-- METERAGE -->
    <div v-if="nestingAlgo === 'METERAGE'" class="text-sm">
        <p v-for="piece in pieces">
            {{ displayMeterage(piece) }} ({{lettersProjectArray[piece.project.id]}})
        </p>
    </div>

    <!-- AREA -->
    <div v-if="nestingAlgo === 'AREA'" class="text-sm">

    </div>

    <!-- BUNDLE -->
    <div v-if="nestingAlgo === 'BUNDLE'" class="text-sm">
        <p v-for="piece in pieces">
            {{ displayBundle(piece) }}
        </p>
    </div>
</template>

