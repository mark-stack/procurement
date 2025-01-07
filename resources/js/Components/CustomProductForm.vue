<script setup>
    //General Imports
    //...

    //Component Imports
    import SelectOrType from "@/Components/SelectOrType.vue";
    import Dimensions from "@/Components/Dimensions.vue";

    //Props
    const props = defineProps({
        item: Object,
        index: Number,
        form: Object,
        allMeasurements: Object,
        formDependentData: Object,
        allGrades: Object,
        nestingGroups: Object,
    });

    //Form
    //...

    //Shared data
    //...

    //Variables
    const emit = defineEmits(['deleteOneCustomisation']);

    //Shared Methods
    //...

    //Methods
    function getProductOptions(){
        return Object.keys(props.formDependentData);
    }

    function getMaterialOptions(index){
        let productSelection = props.form[index]['selected']['product_category'];
        let indexOfProductSelection = Object.keys(props.formDependentData).indexOf(productSelection);
        let materialsObject = Object.values(props.formDependentData)[indexOfProductSelection];

        return Object.keys(materialsObject);
    }

    function getGradeOptions(index){
        let productSelection = props.form[index]['selected']['product_category'];
        let materialSelection = props.form[index]['selected']['material'];
        let indexOfProductSelection = Object.keys(props.formDependentData).indexOf(productSelection);
        let gradesObject = Object.values(props.formDependentData)[indexOfProductSelection][materialSelection];

        //Has grades
        if(gradesObject){
            return Object.keys(gradesObject);
        }
        //No grades: return all grade options
        else{
            return [];//props.allGrades;
        }
    }

    function getNestingOptions(index){
        let none = "Single Units - No minimum quantity";
        let bundle = "Single Units - Packs/boxes (e.g 50 pack)";
        let meterage = "Meterage - Stock lengths (e.g 6 meters)";
        let area = "Area - Stock sizes (e.g 1000 x 4000)";

        let productSelection = props.form[index]['selected']['product_category'];
        let materialSelection = props.form[index]['selected']['material'];
        let indexOfProductSelection = Object.keys(props.formDependentData).indexOf(productSelection);
        let gradesObject = Object.values(props.formDependentData)[indexOfProductSelection][materialSelection];
        let nestingOptionsRaw = [];
        if(gradesObject){
            let nestingOptions = Object.values(gradesObject);
            nestingOptions.forEach(nestingOption => {
                nestingOption.forEach(option => {
                    nestingOptionsRaw.push(option);
                });
            });
        }

        let nestingOptions = {};

        if(nestingOptionsRaw.length > 0){
            nestingOptionsRaw.forEach(option => {
                //METERAGE
                if(option === "METERAGE"){
                    nestingOptions["METERAGE"] = meterage;
                }
                //METERAGE
                if(option === "BUNDLE"){
                    nestingOptions["BUNDLE"] = bundle;
                }
                //METERAGE
                if(option === "AREA"){
                    nestingOptions["AREA"] = area;
                }
                //NONE
                if(option === "NONE"){
                    nestingOptions["NONE"] = none;
                }
            });
        }
        else{
            nestingOptions = {
                NONE:none,
                BUNDLE:bundle,
                METERAGE:meterage,
                AREA:area,
            };
        }

        return nestingOptions;
    }

    function showMaterials(index){
        //todo: "props.form[index]" is not found after submitting clarifications
        let anyProductIsSelected = false;

        if(props.form[index] !== undefined){
            anyProductIsSelected = props.form[index]['selected']['product_category'];
        }

        return anyProductIsSelected;
    }

    function showGrade(index){
        let anyMaterialIsSelected = false;

        if(props.form[index] !== undefined){
            anyMaterialIsSelected = props.form[index]['selected']['material'];
        }

        return anyMaterialIsSelected;
    }

    function showNesting(index){
        /**
            Any grade selected
         */
        let anyGradeIsSelected = false;

        if(props.form[index] !== undefined){
            anyGradeIsSelected = props.form[index]['selected']['grade'];
        }

        return anyGradeIsSelected;
    }

    function showPurchasables(index){
        /**
         For LENGTH and QTY types
         */
        let showPurchasables = false;

        if(props.form[index] !== undefined){
            //NONE/BUNDLE/METERAGE/AREA
            let nesting_algo = props.form[index]['selected']['nesting_algo'];

            //Dimensional
            if(nesting_algo === "METERAGE" || nesting_algo === "BUNDLE"){
                showPurchasables = true;
            }
            else{
                showPurchasables = false;
            }
        }

        return showPurchasables;
    }

    function showPurchasablesArea(index){
        /**
         For AREA type
         */
        //NONE/BUNDLE/METERAGE/AREA
        let nesting_algo = props.form[index]['selected']['nesting_algo'];

        if(nesting_algo === "AREA"){
            return true;
        }
        else{
            return false;
        }
    }

    function purchasablesLabel(index){
        //NONE/BUNDLE/METERAGE/AREA
        let nesting_algo = props.form[index]['selected']['nesting_algo'];

        let label = "Purchasable size/quantities (at least 1)";

        if(nesting_algo === "BUNDLE"){
            label = "Purchasable pack quantities (at least 1)";
        }
        if(nesting_algo === "METERAGE"){
            label = "Purchasable lengths (At least 1, must be different)";
        }

        return label;
    }

    function purchasablesPlaceholder(index){
        //NONE/BUNDLE/METERAGE/AREA
        let nesting_algo = props.form[index]['selected']['nesting_algo'];

        let placeholder = "Size";

        if(nesting_algo === "BUNDLE"){
            placeholder = "Qty";
        }
        if(nesting_algo === "METERAGE"){
            placeholder = "mm";
        }

        return placeholder;
    }

    function purchasableMin(index){
        let nesting_algo = props.form[index]['selected']['nesting_algo'];

        let min = "0.1";

        if(nesting_algo === "BUNDLE"){
            min = "1";
        }

        return min;
    }
    function purchasableStep(index){
        let nesting_algo = props.form[index]['selected']['nesting_algo'];

        let step = "0.1";

        if(nesting_algo === "BUNDLE"){
            step = "1";
        }

        return step;
    }

    function onChangeActions(field,index){
        /**
          Reset dependent fields below this
         */
        if(field === 'product_category'){
            //Clear
            props.form[index]['selected']['material'] = null;
            props.form[index]['selected']['grade'] = null;
            props.form[index]['selected']['nesting_algo'] = null;
            props.form[index]['selected']['nominal_length'] = null;
            props.form[index]['selected']['nominal_width'] = null;
            props.form[index]['selected']['nominal_height'] = null;
            props.form[index]['selected']['purchasable_length_1'] = null;
            props.form[index]['selected']['purchasable_length_2'] = null;
            props.form[index]['selected']['purchasable_length_3'] = null;
            props.form[index]['selected']['purchasable_width_1'] = null;
            props.form[index]['selected']['purchasable_width_2'] = null;
            props.form[index]['selected']['purchasable_width_3'] = null;
        }
        if(field === 'material'){
            //Clear
            props.form[index]['selected']['grade'] = null;
            props.form[index]['selected']['nesting_algo'] = null;
            props.form[index]['selected']['nominal_length'] = null;
            props.form[index]['selected']['nominal_width'] = null;
            props.form[index]['selected']['nominal_height'] = null;
            props.form[index]['selected']['purchasable_length_1'] = null;
            props.form[index]['selected']['purchasable_length_2'] = null;
            props.form[index]['selected']['purchasable_length_3'] = null;
            props.form[index]['selected']['purchasable_width_1'] = null;
            props.form[index]['selected']['purchasable_width_2'] = null;
            props.form[index]['selected']['purchasable_width_3'] = null;
        }
        if(field === 'grade'){
            //Clear
            props.form[index]['selected']['nesting_algo'] = null;

            //Set nesting type (NONE/BUNDLE/METERAGE/AREA)
            //BOLT/UB/UC/PFC/PLATE/LVL/SHS
            let currentProductSelection = props.form[index]['selected']['product_category'];
            let meterageProducts = props.nestingGroups["METERAGE"]; //["UB","UC","PFC","LVL","RHS","SHS"];
            let areaProducts = props.nestingGroups["AREA"]; //["PLATE"];
            let bundleProducts = props.nestingGroups["BUNDLE"]; //["BOLT","ALLTHREAD"];

            if(meterageProducts.includes(currentProductSelection)){
                props.form[index]['selected']['nesting_algo'] = "METERAGE";
            }
            if(areaProducts.includes(currentProductSelection)){
                props.form[index]['selected']['nesting_algo'] = "AREA";
            }
            if(bundleProducts.includes(currentProductSelection)){
                props.form[index]['selected']['nesting_algo'] = "BUNDLE";
            }
        }
    }
</script>

<template>
    <div>
        <div class="flex justify-between">
            <h3 class="font-bold italic">
                "{{item.data.description}}"
            </h3>
            <span class="text-red-500" @click="$emit('deleteOneCustomisation',item.data.id)" style="cursor: pointer;"><i class="fa-solid fa-xmark"></i></span>
        </div>

        Spreadsheet row #{{item.data.csv_index + 1}}
        <!-- PRODUCT -->
        <SelectOrType
            :id="item.data.id"
            label="Product Category"
            reference="product_category"
            :index="index"
            :form="form[index]"
            :options="getProductOptions()"
            :errors="form.errors"
            @change="onChangeActions('product_category',index)"
        />
        <!-- MATERIAL (customOptions['materials'][form[index]['subOption']['material']])-->
        <SelectOrType
            v-if="showMaterials(index)"
            :id="item.data.id"
            label="Material"
            reference="material"
            :index="index"
            :form="form[index]"
            :options="getMaterialOptions(index)"
            :errors="form.errors"
            @change="onChangeActions('material',index)"
        />
        <div
            v-if="showGrade(index)"
            class="grid grid-cols-2 gap-x-2"
        >
            <!-- GRADE -->
            <SelectOrType
                :id="item.data.id"
                label="Grade"
                reference="grade"
                :index="index"
                :form="form[index]"
                :options="getGradeOptions(index)"
                :errors="form.errors"
                @change="onChangeActions('grade',index)"
            />
            <!-- Dimensions -->
            <Dimensions
                :form="form"
                :index="index"
                :id="item.data.id"
            />
        </div>
        <!-- NESTING -->
        <div v-if="showNesting(index)">
            <label class="block text-gray-500 text-sm">Nesting</label>
            <select
                class="w-full rounded"
                v-model="form[index]['selected']['nesting_algo']"
                :class="form.errors[item.data.id+'-nesting_algo'] ? 'border-2 border-red-500' : ''"
            >
                <option
                    v-if="Object.values(getNestingOptions(index)).length > 1"
                    :value="null"
                    disabled
                >
                    Select
                </option>
                <option
                    v-for="(description,reference) in getNestingOptions(index)"
                    :value="reference"
                >
                    {{description}}
                </option>
            </select>
        </div>

        <!-- Purchasable (length & size) -->
        <div v-if="showPurchasables(index)">
            <label class="block text-gray-500 text-sm">{{purchasablesLabel(index)}}</label>
            <div class="grid grid-cols-3 gap-2">
                <input
                    v-model="form[index]['selected']['purchasable_length_1']"
                    :key="index+'-purchasable_length_1'"
                    type="number"
                    :min="purchasableMin(index)"
                    :step="purchasableStep(index)"
                    :placeholder="purchasablesPlaceholder(index)"
                    class="w-full rounded"
                    :class="form.errors[item.data.id+'-purchasable_length_1'] ? 'border-2 border-red-500' : ''"
                />
                <input
                    v-model="form[index]['selected']['purchasable_length_2']"
                    :key="index+'-purchasable_length_2'"
                    type="number"
                    :min="purchasableMin(index)"
                    :step="purchasableStep(index)"
                    :placeholder="purchasablesPlaceholder(index)"
                    class="w-full rounded"
                    :class="form.errors[item.data.id+'-purchasable_length_2'] ? 'border-2 border-red-500' : ''"
                />
                <input
                    v-model="form[index]['selected']['purchasable_length_3']"
                    :key="index+'-purchasable_length_3'"
                    type="number"
                    :min="purchasableMin(index)"
                    :step="purchasableStep(index)"
                    :placeholder="purchasablesPlaceholder(index)"
                    class="w-full rounded"
                    :class="form.errors[item.data.id+'-purchasable_length_3'] ? 'border-2 border-red-500' : ''"
                />
            </div>
        </div>
        <!-- Purchasable (area) -->
        <div v-if="showPurchasablesArea(index)">
            <label class="block text-gray-500 text-sm">Purchasable Area sizes (at least 1)</label>
            <div class="grid grid-cols-1 gap-2">
                <div class="grid grid-cols-2 gap-2">
                    <div class="flex">
                        <input
                            v-model="form[index]['selected']['purchasable_length_1']"
                            type="number"
                            placeholder="Length"
                            class="w-full rounded"
                            :class="form.errors[item.data.id+'-purchasable_length_1'] ? 'border-2 border-red-500' : ''"
                        />
                        <span class="mt-2 ml-2">X</span>
                    </div>
                    <input
                        v-model="form[index]['selected']['purchasable_width_1']"
                        type="number"
                        placeholder="Width"
                        class="w-full rounded"
                        :class="form.errors[item.data.id+'-purchasable_width_1'] ? 'border-2 border-red-500' : ''"
                    />
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div class="flex">
                        <input
                            v-model="form[index]['selected']['purchasable_length_2']"
                            type="number"
                            placeholder="Length"
                            class="w-full rounded"
                            :class="form.errors[item.data.id+'-purchasable_length_2'] ? 'border-2 border-red-500' : ''"
                        />
                        <span class="mt-2 ml-2">X</span>
                    </div>
                    <input
                        v-model="form[index]['selected']['purchasable_width_2']"
                        type="number"
                        placeholder="Width"
                        class="w-full rounded"
                        :class="form.errors[item.data.id+'-purchasable_width_2'] ? 'border-2 border-red-500' : ''"
                    />
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div class="flex">
                        <input
                            v-model="form[index]['selected']['purchasable_length_3']"
                            type="number"
                            placeholder="Length"
                            class="w-full rounded"
                            :class="form.errors[item.data.id+'-purchasable_length_3'] ? 'border-2 border-red-500' : ''"
                        />
                        <span class="mt-2 ml-2">X</span>
                    </div>
                    <input
                        v-model="form[index]['selected']['purchasable_width_3']"
                        type="number"
                        placeholder="Width"
                        class="w-full rounded"
                        :class="form.errors[item.data.id+'-purchasable_width_3'] ? 'border-2 border-red-500' : ''"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
