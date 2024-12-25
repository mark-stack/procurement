<script setup>
    //General Imports
    //...

    //Component Imports
    //...

    //Props
    const props = defineProps({
        form: Object,
        index: Number,
        id: Number,
    });

    //Variables
    let nominalSizeData = props.form[props.index]['nominalSizeData'];
    let currentProductSelection = props.form[props.index]['selected']['product'];

    //Methods
    function getPlaceholder(field){
        let placeholder = field;

        if(nominalSizeData[currentProductSelection] === undefined){
            if(field === "length_placeholder"){
                placeholder = "Length";
            }
            if(field === "width_placeholder"){
                placeholder = "Width";
            }
            if(field === "height_placeholder"){
                placeholder = "Height";
            }
        }
        else{
            placeholder = nominalSizeData[currentProductSelection][field];
        }

        return placeholder;
    }
</script>

<template>
    <div>
        <label class="block text-gray-500 text-sm">Dimensions</label>
        <input
            v-if="nominalSizeData[currentProductSelection] ? nominalSizeData[currentProductSelection]['length'] : true"
            v-model="form[index]['selected']['nominal_length']"
            type="number"
            :placeholder="getPlaceholder('length_placeholder')"
            class="w-full rounded"
            :class="form.errors[id+'-nominal_length'] ? 'border-2 border-red-500' : ''"
        />
        <input
            v-if="nominalSizeData[currentProductSelection] ? nominalSizeData[currentProductSelection]['width'] : true"
            v-model="form[index]['selected']['nominal_width']"
            type="number"
            :placeholder="getPlaceholder('width_placeholder')"
            class="w-full rounded"
            :class="form.errors[id+'-nominal_width'] ? 'border-2 border-red-500' : ''"
        />
        <input
            v-if="nominalSizeData[currentProductSelection] ? nominalSizeData[currentProductSelection]['height'] : true"
            v-model="form[index]['selected']['nominal_height']"
            type="number"
            :placeholder="getPlaceholder('height_placeholder')"
            class="w-full rounded"
            :class="form.errors[id+'-nominal_height'] ? 'border-2 border-red-500' : ''"
        />
    </div>
</template>
