<script setup>
    //General Imports
    //...

    //Component Imports
    //...

    //Props
    const props = defineProps({
        form: Object,
        index: Number,
    });

    //Variables
    let nominalSizeData = props.form[props.index]['nominalSizeData'];
    let currentProductSelection = props.form[props.index]['selected']['product'];

    //Methods
    function getPlaceholder(field){
        return nominalSizeData[currentProductSelection][field];
    }
</script>

<template>
    <div>
        <label class="block text-gray-500 text-sm">Dimensions</label>
        <input
            v-if="nominalSizeData[currentProductSelection]['length']"
            v-model="form[index]['selected']['nominal_length']"
            type="number"
            :placeholder="getPlaceholder('length_placeholder')"
            class="w-full rounded"
            :class="form.errors[index+'-nominal_length'] ? 'border-2 border-red-500' : ''"
        />
        <input
            v-if="nominalSizeData[currentProductSelection]['width']"
            v-model="form[index]['selected']['nominal_width']"
            type="number"
            :placeholder="getPlaceholder('width_placeholder')"
            class="w-full rounded"
            :class="form.errors[index+'-nominal_width'] ? 'border-2 border-red-500' : ''"
        />
        <input
            v-if="nominalSizeData[currentProductSelection]['height']"
            v-model="form[index]['selected']['nominal_height']"
            type="number"
            :placeholder="getPlaceholder('height_placeholder')"
            class="w-full rounded"
            :class="form.errors[index+'-nominal_height'] ? 'border-2 border-red-500' : ''"
        />
    </div>
</template>
