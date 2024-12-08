<script setup>
    //General Imports
    //...

    //Component Imports
    //...

    //Props
    const props = defineProps({
        label: String,
        reference: String,
        index: Number,
        form: Object,
        customOptions: Object,
        errors: Object,
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
    function displayFormat(option){
        let display = option;

        if(option === 'GR_4_6'){
            display = "GR 4.6";
        }
        if(option === 'GR_8_8'){
            display = "GR 8.8";
        }

        return display;
    }

    function changeActions(){
        //Reset "other"
        props.form['selected_other'][props.reference] = null;

        //Materials: if select "STEEL"
        if(props.form['selected'][props.reference] === "STEEL"){
            props.form['subOption']['grade'] = "STEEL";
        }
        //Materials: if select "TIMBER"
        else if(props.form['selected'][props.reference] === "TIMBER"){
            props.form['subOption']['grade'] = "TIMBER";
        }
        //Materials: if select "PLASTIC"
        else if(props.form['selected'][props.reference] === "PLASTIC"){
            props.form['subOption']['grade'] = "PLASTIC";
        }
        else{
            props.form['subOption']['grade'] = "all";
        }
    }
</script>

<template>
    <div>
        <label class="block text-gray-500 text-sm">{{label}}</label>
        <select
            v-if="form['selected'][reference] !== 'Other'"
            v-model="form['selected'][reference]"
            class="w-full rounded"
            :class="errors[props.index+'-'+props.reference] ? 'border-2 border-red-500' : ''"
            @change="changeActions()"
        >
            <option :value="null" disabled>Select</option>
            <option
                v-for="option in customOptions"
                :value="option"
            >
                {{displayFormat(option)}}
            </option>
            <option value="Other">Other (custom)</option>
        </select>

        <div
            v-if="form['selected'][reference] === 'Other'"
            class="relative w-full max-w-sm"
        >
            <!-- Input field -->
            <input
                v-model="form['selected_other'][reference]"
                type="text"
                placeholder="Other"
                class="w-full rounded"
                :class="errors[props.index+'-'+props.reference] ? 'border-2 border-red-500' : ''"
            />

            <!-- "X" button -->
            <button
                type="button"
                class="absolute mb-1 text-xl inset-y-0 right-2 flex items-center text-gray-500 hover:text-gray-700"
                @click="form['selected_other'][reference] = false; form['selected'][reference] = null"
            >
                &times;
            </button>
        </div>
    </div>
</template>
