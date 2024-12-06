<script setup>
    //General Imports
    import {useForm, usePage} from "@inertiajs/vue3";
    import {computed, onMounted, ref} from "vue";


    //Component Imports
    import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
    import SelectOrType from "@/Components/SelectOrType.vue";

    //Props
    const props = defineProps({
        project: Object,
        materialListRows: Object,
        senseChecks: Object,
        generalProductMatches: Object,
        customItems: Object,
        customOptions: Object,
    });

    //Form
    const formStore = useForm({
        csv: null,
    });
    const formBulkActions = useForm({
        selectedRawMaterialQuoteIds: [], //initialMapBulkActions(),
    });
    const formPreChecklist = useForm({
        one: false,
        two: false,
        three: false,
        no_bolts: false,
        bolt_qty: false,
        pre_nested_check: false,
    });
    const formClarifications = useForm(props.generalProductMatches);
    const formCustomisations = useForm(props.customItems);

    //Variables
    const isDragging = ref(false);
    const uploading = ref(false);
    const fileInput = ref(null);
    const allChecked = ref(false);
    const screenHeight = window.innerHeight;

    //Shared data
    const warning = computed(() => usePage().props.flash.warning);

    //Methods
    function toggleCheckbox(id){
        const index = formBulkActions.selectedRawMaterialQuoteIds.indexOf(id);
        if (index > -1) {
            // If the number exists, remove it
            formBulkActions.selectedRawMaterialQuoteIds.splice(index, 1);
        }
        else {
            // If the number doesn't exist, add it
            formBulkActions.selectedRawMaterialQuoteIds.push(id);
        }
    }

    function toggleMasterCheckbox(){
        //Uncheck all
        if(allChecked.value){
            allChecked.value = false;
            formBulkActions.selectedRawMaterialQuoteIds = [];
        }
        //Check all
        else if(allChecked.value === false){
            allChecked.value = true;
            formBulkActions.selectedRawMaterialQuoteIds = getAllMaterialQuoteIds();
        }
    }

    function getAllMaterialQuoteIds(){
        let result = [];
        Object.values(props.materialListRows).forEach(item => {
            result.push(item.id);
        });

        return result;
    }

    const handleDragOver = () => {
        isDragging.value = true;
    };

    const handleDragLeave = () => {
        isDragging.value = false;
    };

    const handleDrop = (event) => {
        isDragging.value = false;
        const file = event.dataTransfer.files[0];
        if(file){
            formStore.csv = file;
            processFile(file);
        }
    };

    function handleFileSelect(){
        const file = formStore.csv;
        if(file){
            processFile(file);
        }
    };

    const triggerFileInput = () => {
        fileInput.value.click();
    };

    function processFile(file){
        console.log("processFile");
        if (file.type !== 'text/csv') {
            alert('Please upload a valid CSV file.');
            return;
        }

        let url = route("products.store",props.project.id);

        formStore.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log('success');
                uploading.value = false;
                clearFileInput();
            },
            onError: errors => {
                console.log('errors',errors);
                uploading.value = false;
                clearFileInput();
            },
        });

        uploading.value = true;

        console.log("formStore.file",formStore.csv);
    }

    function clearFileInput() {
        const fileInput = document.getElementById("dropzone-file");
        fileInput.value = ""; // Clear the file input
        console.log("File input cleared!");
    }

    //On mounted
    onMounted(() => {
        const dropzone = document.getElementById('dropzone');

        if(dropzone){
            // Drag and drop events
            dropzone.addEventListener('dragover', (e) => {
                e.preventDefault();
                dropzone.style.borderColor = '#00f';
                // dropzone.textContent = 'Drop it here!';
            });

            dropzone.addEventListener('dragleave', () => {
                dropzone.style.borderColor = '#ccc';
                // dropzone.textContent = 'Drag and drop your CSV file here';
            });
        }
    });

    function getUnitDisplay(row,slash){
        let result = "";
        if(row.measurement_unit === 'SINGLE'){
            result = slash ? "/each" : "";
        }
        else{
            result = slash ? "/m" : "m";
        }

        return result;
    }

    function cropText(text, maxLength = 25) {
        if (text.length > maxLength) {
            return text.substring(0, maxLength) + "...";
        }
        return text;
    }

    function submitBulkDelete(){
        let url = route("raw.material.quote.bulk.destroy");
        formBulkActions.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log('success');
                uploading.value = false;
                formBulkActions.selectedRawMaterialQuoteIds = [];
                allChecked.value = false;
                clearFileInput();
            },
            onError: errors => {
                console.log('errors',errors);
                uploading.value = false;
                formBulkActions.selectedRawMaterialQuoteIds = [];
                allChecked.value = false;
                clearFileInput();
            },
        });
    }

    function submitClarifications(){
        let url = route("raw.material.quote.clarifications");
        formClarifications.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log('success');
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }

    function submitCustomisations(){
        let url = route("raw.material.quote.customisations");
        formCustomisations.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log('success');
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }

    function displayProductMatches(row){
        let display = "user-custom";

        //Has one product match
        if(row['product']){
            let product = row['product'];
            display = formatProduct(product.product,product.size,product.grade, product.surface,product.length);
        }

        return display;
    }

    function formatProduct(product,size,grade,surface,length){
        //Size
        let actualSize = size;
        if(product === "PLATE"){
            actualSize = size+"PL";
        }
        if(product === "BOLT"){
            if(length){
                actualSize = "M"+size+"x"+length;
            }
            else{
                actualSize = "M"+size;
            }
        }
        if(size.includes("X")){
            actualSize = size.toLowerCase();
        }

        //Product
        let actualProduct = product;
        if(product === "PLATE"){
            actualProduct = "";
        }
        if(product === "BOLT"){
            actualProduct = "";
        }
        if(product === "LVL"){
            actualProduct = " "+product;
        }

        //Grade
        let actualGrade = grade;
        if(grade === "GR_4_6"){
            actualGrade = "GR4.6"
        }
        if(grade === "GR_8_8"){
            actualGrade = "GR8.8"
        }

        //Surface
        let actualSurface = ' '+surface;
        if(surface === "NONE"){
            actualSurface = "";
        }
        if(surface === "GALVANISED"){
            actualSurface = " GALV";
        }
        if(surface === "TREATED_H2"){
            actualSurface = " H2";
        }

        return  actualSize + actualProduct + " " + actualGrade + actualSurface;
    }

    function getsubOption(index){
        let subOption = "all";

        if(formCustomisations[index]['material'] === 'STEEL'){
            subOption = "STEEL";
        }
        if(formCustomisations[index]['timber'] === 'TIMBER'){
            subOption = "TIMBER";
        }

        return subOption;
    }
</script>

<template>
    <Head title="Import" />

    <AuthenticatedLayout>
        <div class="py-5">
            <template v-if="!senseChecks">
                <!-- clarifications (might re-upload) -->
                <section class="container max-w-5xl mx-auto">
                    <h2 class="font-bold text-lg">Pre-upload checklist:</h2>
                    <div class="flex">
                        <input
                            v-model="formPreChecklist.one"
                            type="checkbox"
                            class="mt-1 mr-2"
                            id="one"
                        />
                        <label for="one">Are materials un-nested? <small>(the software performs cross-project nesting for material efficiency)</small></label>
                    </div>
                    <div class="flex">
                        <input
                            v-model="formPreChecklist.two"
                            type="checkbox"
                            class="mt-1 mr-2"
                            id="two"
                        />
                        <label for="two">Are consumables allowed for?</label>
                    </div>
                    <div class="flex">
                        <input
                            v-model="formPreChecklist.three"
                            type="checkbox"
                            class="mt-1 mr-2"
                            id="three"
                        />
                        <label for="three">Are deliveries allowed for?</label>
                    </div>
                </section>

                <div v-show="formPreChecklist.one && formPreChecklist.two && formPreChecklist.three" class="mx-auto max-w-5xl mt-2">
                    <div class="overflow-hidden shadow-sm sm:rounded-lg">
                        <div>
                            <div
                                id="dropzone"
                                @click="triggerFileInput"
                                @dragover.prevent="handleDragOver"
                                @dragleave="handleDragLeave"
                                @drop.prevent="handleDrop"
                                class="bg-white"
                                :style="isDragging ? 'border-color: #00f;color: #00f;' : 'color: #aaa;'"
                            >
                                <div class="w-full mx-auto text-center">
                                    {{isDragging ? 'Drop it here!' : 'Material list in CSV format: Click to upload, or drag & drop here'}}
                                </div>
                            </div>
                            <input
                                id="dropzone-file"
                                type="file"
                                ref="fileInput"
                                accept=".csv"
                                @input="formStore.csv = $event.target.files[0]; handleFileSelect()"
                                hidden
                            />
                            <div v-if="uploading">Uploading...</div>
                        </div>

                    </div>
                    <div v-if="warning" class="text-center text-orange-500 mt-2">
                        {{warning}}
                    </div>
                </div>
            </template>

            <!-- clarifications (might re-upload) -->
            <section v-if="senseChecks" class="container max-w-5xl mx-auto mt-5">
                <h2 class="font-bold text-lg">Sense checks</h2>

                <!-- No bolts -->
                <div v-if="!senseChecks.has_bolts" class="mt-2">
                    <input
                        v-model="formPreChecklist.no_bolts"
                        type="checkbox"
                        class="mr-2"
                        id="no_bolts"
                    />
                    <label for="no_bolts">No bolts found. This is correct?</label>
                </div>

                <!-- Nuts & washers allowed for?-->
                <div v-if="senseChecks.has_bolts" class="mt-2">
                    <input
                        v-model="formPreChecklist.has_bolts"
                        type="checkbox"
                        class="mr-2"
                        id="has_bolts"
                    />
                    <label for="has_bolts">Do the bolts included have cost allowance for nuts and washers?</label>
                </div>

                <!-- Bolt quantity -->
                <div v-if="senseChecks.bolt_qty < 100" class="mt-2">
                    <input
                        v-model="formPreChecklist.bolt_qty"
                        type="checkbox"
                        class="mr-2"
                        id="bolt_qty"
                    />
                    <label for="bolt_qty">There's only {{senseChecks.bolt_qty}} bolts? This is correct?</label>
                </div>

                <!-- Mill certs -->
                <div v-if="senseChecks.mill_certs < 100" class="mt-2">
                    <input
                        v-model="formPreChecklist.mill_certs"
                        type="checkbox"
                        class="mr-2"
                        id="mill_certs"
                    />
                    <label for="mill_certs">Are Mill certificates required?</label>
                </div>

                <!-- todo: you normally purchase X with Y-->

                <!-- pre-nesting clarification -->
                <div v-if="senseChecks.pre_nested_check" class="mt-2">
                    <input
                        v-model="formPreChecklist.pre_nested_check"
                        type="checkbox"
                        class="mr-2"
                        id="pre_nested_check"
                    />
                    <label for="pre_nested_check">Pre-nesting clarification: There's some materials with exact stock sizes. Are these pre-nested, or just coincidence?</label>
                </div>

                <!-- todo: tonnage checks -->

                <!-- todo: minimum grade check-->

            </section>

            <!-- Clarifications (preparing for RFQ) -->
            <section v-if="generalProductMatches.length > 0" class="container max-w-5xl mx-auto mt-5">
                <h2 class="font-bold text-lg">Exact product clarifications</h2>
                <form @submit.prevent="submitClarifications()">
                    <div v-for="(item,index) in generalProductMatches" class="mt-5">
                        <p class="italic font-bold">"{{item.data.description}}"</p>
                        <div class="grid grid-cols-4">
                            <div v-for="(option,option_index) in item.options">
                                <label>
                                    <input
                                        v-model="formClarifications[index]['selected']"
                                        type="radio"
                                        :name="index"
                                        :value="option_index"
                                        required
                                    >
                                    {{ formatProduct(option.product,option.size,option.grade,option.surface,option.length)}}
                                </label>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="bg-green-500 rounded px-2 py-1">
                        Save all
                    </button>
                </form>
            </section>

            <!-- User custom products -->
            <section v-if="customItems.length > 0" class="container max-w-5xl mx-auto mt-5">
                <h2 class="font-bold text-lg">Custom products (add to price book)</h2>
                <p class="mb-3 text-gray-600">
                    This action is just required once. It will be added to the price book for you and other members in your company.
                </p>
                <form @submit.prevent="submitCustomisations()">
                    <p v-for="(item,index) in customItems" class="mt-3">
                        <h3 class=""><span class="font-bold italic">"{{item.data.description}}"</span> (spreadsheet row [123])</h3>
                        <div class="grid grid-cols-12 gap-x-2">
                            <!-- PRODUCT -->
                            <div class="col-span-2">
                                <SelectOrType
                                    label="Product Category"
                                    reference="product"
                                    :index="index"
                                    :form="formCustomisations[index]"
                                    :customOptions="customOptions['products'][formCustomisations[index]['subOption']['product']]"
                                />
                            </div>

                            <!-- MATERIAL -->
                            <div class="col-span-2">
                                <SelectOrType
                                    label="Material"
                                    reference="material"
                                    :index="index"
                                    :form="formCustomisations[index]"
                                    :customOptions="customOptions['materials'][formCustomisations[index]['subOption']['material']]"
                                />
                            </div>
                            <!-- GRADE-->
                            <div class="col-span-2">
                                <SelectOrType
                                    label="Grade"
                                    reference="grade"
                                    :index="index"
                                    :form="formCustomisations[index]"
                                    :customOptions="customOptions['grades'][formCustomisations[index]['subOption']['grade']]"
                                />
                            </div>
                            <!-- SIZE-->
                            <div class="col-span-2">
                                <label class="block text-gray-500 text-sm">Size (number)</label>
                                <input
                                    v-model="formCustomisations[index]['selected']['size']"
                                    type="number"
                                    placeholder="SIZE"
                                    class="w-full rounded"
                                />
                            </div>
                            <!-- LENGTH-->
                            <div class="col-span-2">
                                <label class="block text-gray-500 text-sm">Quantify</label>
                                <select
                                    class="w-full rounded"
                                    v-model="formCustomisations[index]['selected']['quantify']"
                                >
                                    <option :value="null" disabled>Select</option>
                                    <option
                                        v-for="option in customOptions['measurement_unit']['all']"
                                        :value="option"
                                    >
                                        {{option === "SINGLE" ? 'SINGLE ITEM' : option}}
                                    </option>
                                </select>
                            </div>

                            <!-- SUPPLIER-->
                            <div class="col-span-2">
                                <SelectOrType
                                    label="Suppliers"
                                    reference="suppliers"
                                    :index="index"
                                    :form="formCustomisations[index]"
                                    :customOptions="customOptions['suppliers'][formCustomisations[index]['subOption']['suppliers']]"
                                />
<!--                                <label class="block text-gray-500 text-sm">Suppliers</label>-->
<!--                                <select class="w-full rounded">-->
<!--                                    <option value="" name="">XYZ Company</option>-->
<!--                                    <option value="" name="">ABC Company</option>-->
<!--                                    <option value="" name="">Not sure yet</option>-->
<!--                                    <option value="Other" name="">Other</option>-->
<!--                                </select>-->
                            </div>
                        </div>
                    </p>
                    <button type="submit" class="bg-green-500 rounded px-2 py-1">
                        Save all
                    </button>
                </form>
            </section>

            <!-- table -->
            <section class="container max-w-5xl mx-auto mt-2">
                <button
                    :disabled="formBulkActions.selectedRawMaterialQuoteIds.length == 0"
                    @click="submitBulkDelete()"
                    :class="formBulkActions.selectedRawMaterialQuoteIds.length == 0 ? 'text-gray-500' : ''"
                    class="text-sm bg-red-200 px-2 py-1 rounded"
                >
                    Delete Selected ({{formBulkActions.selectedRawMaterialQuoteIds.length}})
                </button>

                <div class="flex flex-col">
                    <div class="overflow-x-auto">
                        <div class="inline-block min-w-full py-2 align-middle">
                            <div class="overflow-hidden border border-gray-200 dark:border-gray-700 md:rounded-lg">
                                <div class="relative overflow-auto" :style="'height:' + (screenHeight - 300) + 'px'">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead class="sticky top-0 bg-gray-50 dark:bg-gray-800">
                                        <tr>
                                            <th scope="col" class=" py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                <div class="flex items-center gap-x-3">
                                                    <input
                                                        id="masterCheckbox"
                                                        @input="toggleMasterCheckbox()"
                                                        type="checkbox"
                                                        :checked="allChecked"
                                                        class="text-blue-500 border-gray-300 rounded dark:bg-gray-900 dark:ring-offset-gray-900 dark:border-gray-700"
                                                    >
                                                    <label for="masterCheckbox">Your Description</label>
                                                </div>
                                            </th>

                                            <th scope="col" class="sticky top-0 py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                <div class="flex items-center gap-x-3">
                                                    <span>Length</span>
                                                </div>
                                            </th>

                                            <th scope="col" class="sticky top-0 py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                <div class="flex items-center gap-x-3">
                                                    <span>Width</span>
                                                </div>
                                            </th>

                                            <th scope="col" class="sticky top-0 py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                <div class="flex items-center gap-x-3">
                                                    <span>Sub Qty</span>
                                                </div>
                                            </th>

                                            <th scope="col" class="sticky top-0 py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                <div class="flex items-center gap-x-3">
                                                    <span>Rate</span>
                                                </div>
                                            </th>

                                            <th scope="col" class="sticky top-0 py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                <div class="flex items-center gap-x-3">
                                                    <span>Subtotal</span>
                                                </div>
                                            </th>

                                            <th scope="col" class="sticky top-0 py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                <div class="flex items-center gap-x-3">
                                                    <span>Price Book</span>
                                                </div>
                                            </th>

                                            <th scope="col" class="sticky top-0 relative py-3.5 px-4">
                                                <span class="sr-only">Edit</span>
                                            </th>
                                        </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200 dark:divide-gray-700 dark:bg-gray-900">
                                        <tr v-for="row in materialListRows">
                                            <!-- description -->
                                            <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                <div class="inline-flex items-center gap-x-3">
                                                    <input
                                                        :id="'check'+row.id"
                                                        type="checkbox"
                                                        :checked="formBulkActions.selectedRawMaterialQuoteIds.includes(row.id)"
                                                        class="text-blue-500 border-gray-300 rounded dark:bg-gray-900 dark:ring-offset-gray-900 dark:border-gray-700"
                                                        @input="toggleCheckbox(row.id)"
                                                    >

                                                    <div class="flex items-center gap-x-2">
                                                        <div>
                                                            <label :for="'check'+row.id" class="font-medium text-gray-800 dark:text-white ">
                                                                {{ cropText(row.description) }}
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <!-- length -->
                                            <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                <div class="inline-flex items-center gap-x-3">
                                                    <div class="flex items-center gap-x-2">
                                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="m0 0h512v512h-512z"/><path d="m39.557 19 283.883 254h149.003l-283.883-254h-149.002zm-14.557 11.13v25.847l286 255.893v-25.846zm64 107.263v34.584l286 255.893v-84.843l-64-13.002zm-11.445 48.497-42.9 10.723 287.79 257.498 42.9-10.723-287.789-257.498zm-52.555 26.24v23.847l286 255.893v-23.847zm304 78.87v21.973l64 16v126.054l-64 16v21.973h158v-21.973l-64-16v-126.054l64-16v-21.973zm112 135.865v14.108l21.88 5.47z" fill="#fff"/></svg>
                                                        <div>
                                                            <h2 class="font-medium text-gray-800 dark:text-white ">
                                                                {{ row.length_required }}<span class="text-xs">{{getUnitDisplay(row,false)}}</span>
                                                            </h2>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <!-- width -->
                                            <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                <div class="inline-flex items-center gap-x-3">
                                                    <div class="flex items-center gap-x-2">
                                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="m0 0h512v512h-512z"/><path d="m39.557 19 283.883 254h149.003l-283.883-254h-149.002zm-14.557 11.13v25.847l286 255.893v-25.846zm64 107.263v34.584l286 255.893v-84.843l-64-13.002zm-11.445 48.497-42.9 10.723 287.79 257.498 42.9-10.723-287.789-257.498zm-52.555 26.24v23.847l286 255.893v-23.847zm304 78.87v21.973l64 16v126.054l-64 16v21.973h158v-21.973l-64-16v-126.054l64-16v-21.973zm112 135.865v14.108l21.88 5.47z" fill="#fff"/></svg>
                                                        <div>
                                                            <h2 class="font-medium text-gray-800 dark:text-white ">
                                                                {{ row.width_required }}<span class="text-xs">{{getUnitDisplay(row,false)}}</span>
                                                            </h2>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <!-- sub qty -->
                                            <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                <div class="inline-flex items-center gap-x-3">
                                                    <div class="flex items-center gap-x-2">
                                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="m0 0h512v512h-512z"/><path d="m39.557 19 283.883 254h149.003l-283.883-254h-149.002zm-14.557 11.13v25.847l286 255.893v-25.846zm64 107.263v34.584l286 255.893v-84.843l-64-13.002zm-11.445 48.497-42.9 10.723 287.79 257.498 42.9-10.723-287.789-257.498zm-52.555 26.24v23.847l286 255.893v-23.847zm304 78.87v21.973l64 16v126.054l-64 16v21.973h158v-21.973l-64-16v-126.054l64-16v-21.973zm112 135.865v14.108l21.88 5.47z" fill="#fff"/></svg>
                                                        <div>
                                                            <h2 class="font-medium text-gray-800 dark:text-white ">
                                                                {{ row.sub_qty }}
                                                            </h2>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <!-- unit rate -->
                                            <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                <div class="inline-flex items-center gap-x-3">
                                                    <div class="flex items-center gap-x-2">
                                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="m0 0h512v512h-512z"/><path d="m39.557 19 283.883 254h149.003l-283.883-254h-149.002zm-14.557 11.13v25.847l286 255.893v-25.846zm64 107.263v34.584l286 255.893v-84.843l-64-13.002zm-11.445 48.497-42.9 10.723 287.79 257.498 42.9-10.723-287.789-257.498zm-52.555 26.24v23.847l286 255.893v-23.847zm304 78.87v21.973l64 16v126.054l-64 16v21.973h158v-21.973l-64-16v-126.054l64-16v-21.973zm112 135.865v14.108l21.88 5.47z" fill="#fff"/></svg>
                                                        <div>
                                                            <h2 class="font-medium text-gray-800 dark:text-white ">
                                                                {{ new Intl.NumberFormat('en-US', {style: 'currency', currency: 'USD',}).format(row.unit_rate) }}<span class="text-xs">{{getUnitDisplay(row,true)}}</span>
                                                            </h2>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <!-- subtotal -->
                                            <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                <div class="inline-flex items-center gap-x-3">
                                                    <div class="flex items-center gap-x-2">
                                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="m0 0h512v512h-512z"/><path d="m39.557 19 283.883 254h149.003l-283.883-254h-149.002zm-14.557 11.13v25.847l286 255.893v-25.846zm64 107.263v34.584l286 255.893v-84.843l-64-13.002zm-11.445 48.497-42.9 10.723 287.79 257.498 42.9-10.723-287.789-257.498zm-52.555 26.24v23.847l286 255.893v-23.847zm304 78.87v21.973l64 16v126.054l-64 16v21.973h158v-21.973l-64-16v-126.054l64-16v-21.973zm112 135.865v14.108l21.88 5.47z" fill="#fff"/></svg>
                                                        <div>
                                                            <h2 class="font-medium text-gray-800 dark:text-white ">
                                                                {{ new Intl.NumberFormat('en-US', {style: 'currency', currency: 'USD',}).format(row.length_required * row.sub_qty * row.unit_rate) }}
                                                            </h2>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <!-- price book product -->
                                            <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                <div class="inline-flex items-center gap-x-3">
                                                    <div class="flex items-center gap-x-2">
                                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="m0 0h512v512h-512z"/><path d="m39.557 19 283.883 254h149.003l-283.883-254h-149.002zm-14.557 11.13v25.847l286 255.893v-25.846zm64 107.263v34.584l286 255.893v-84.843l-64-13.002zm-11.445 48.497-42.9 10.723 287.79 257.498 42.9-10.723-287.789-257.498zm-52.555 26.24v23.847l286 255.893v-23.847zm304 78.87v21.973l64 16v126.054l-64 16v21.973h158v-21.973l-64-16v-126.054l64-16v-21.973zm112 135.865v14.108l21.88 5.47z" fill="#fff"/></svg>
                                                        <div>
                                                            <h2 class="font-medium text-gray-800 dark:text-white ">
                                                                {{ displayProductMatches(row) }}
                                                            </h2>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>

                                            <td class="px-4 py-4 text-sm whitespace-nowrap">
                                                <div class="flex items-center gap-x-6">
                                                    <p
                                                        @click="initiateUpdate(template)"
                                                        class="text-gray-500 transition-colors duration-200 dark:hover:text-yellow-500 dark:text-gray-300 hover:text-yellow-500 focus:outline-none"
                                                    >
                                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                                        </svg>
                                                    </p>
                                                </div>
                                            </td>
                                        </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>

</template>

<style scoped>
    #dropzone {
        width: 100%;
        height: 100px;
        border: 2px dashed #ccc;
        border-radius: 10px;
        text-align: center;
        line-height: 100px;
        /*color: #aaa;*/
        cursor: pointer;
        transition: border-color 0.3s;
    }

    /*
    #dropzone.drop-active {
        border-color: #00f;
        color: #00f;
    }
    */

    button {
        margin-top: 10px;
    }
</style>

