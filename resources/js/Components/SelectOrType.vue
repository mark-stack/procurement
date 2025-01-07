<script setup>
    //General Imports
    //...

    //Component Imports
    //...

    //Props
    const props = defineProps({
        id: Number,
        label: String,
        reference: String,
        index: Number,
        form: Object,
        options: Object,
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
        if(option === 'GR_5_8'){
            display = "GR 5.8";
        }
        if(option === 'GR_8_8'){
            display = "GR 8.8";
        }
        if(option === 'GR_10_9'){
            display = "GR 10.9";
        }
        if(option === 'GR_12_9'){
            display = "GR 12.9";
        }
        if(option === 'PLAIN_CARBON_STEEL'){
            display = "PLAIN CARBON STEEL";
        }
        if(option === 'HEX_BOLT'){
            display = "HEX BOLT";
        }
        if(option === 'CSK_BOLT'){
            display = "CSK BOLT";
        }

        return display;
    }

    function clearSingleForm(){
        props.form['selected_other'][props.reference] = null;
        props.form['selected'][props.reference] = null;
        props.form['selected']['material'] = null;
    }
</script>

<template>
    <div>
        <label class="block text-gray-500 text-sm">{{label}}</label>
        <select
            v-if="form['selected'][reference] !== 'Other'"
            v-model="form['selected'][reference]"
            class="w-full rounded"
            :class="errors[id+'-'+reference] ? 'border-2 border-red-500' : ''"
        >
            <option :value="null" disabled>Select</option>
            <option
                v-for="option in options"
                :value="option"
            >
                {{displayFormat(option)}}
            </option>
        </select>

        <div
            v-if="form['selected'][reference] === 'Other' || 1==1"
            class="relative w-full max-w-sm"
        >
            <!-- Input field -->
            <input
                v-model="form['selected_other'][reference]"
                type="text"
                placeholder="Other"
                class="w-full rounded"
                :class="errors[props.id+'-'+props.reference] ? 'border-2 border-red-500' : ''"
            />

            <!-- "X" button -->
            <button
                type="button"
                class="absolute mb-1 text-xl inset-y-0 right-2 flex items-center text-gray-500 hover:text-gray-700"
                @click="clearSingleForm()"
            >
                &times;
            </button>
        </div>
    </div>
</template>
