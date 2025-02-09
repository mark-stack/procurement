<script setup>
    //General Imports
    import {useForm, usePage} from "@inertiajs/vue3";
    import {computed, onMounted, ref} from "vue";
    import shared from '@/Shared/shared';

    //Component Imports
    import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
    import CustomProductForm from "@/Components/CustomProductForm.vue";

    //Props
    const props = defineProps({
        project: Object,
        materialListRows: Object,
        senseChecks: Object,
        partialProductMatches: Object,
        requiresCustom: Object,
        allMeasurements: Object,
        formDependentData: Object,
        allGrades: Object,
        business: Object,
        nestingGroups: Object,
    });

    //Form
    const formStore = useForm({
        excel: null,
    });
    const formBulkActions = useForm({
        selectedRawMaterialQuoteIds: [],
    });
    const formPreChecklist = useForm({
        one: false,
        two: false,
        three: false,
        no_bolts: false,
        bolt_qty: false,
        pre_nested_check: false,
    });
    let formClarifications = useForm(Object.assign({}, props.partialProductMatches, {deletedIds:[]}));
    let formCustomisations = useForm(Object.assign({}, props.requiresCustom, {deletedIds:[]}));

    //Variables
    const isDragging = ref(false);
    const uploading = ref(false);
    const fileInput = ref(null);
    const allChecked = ref(false);
    const showClarifications = ref(hasClarifications());
    const showUserCustomProducts = ref(hasUserCustomProducts());
    const isAdmin = usePage().props.auth.isAdmin;

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
            formStore.excel = file;
            processFile(file);
        }
    };

    function handleFileSelect(){
        const file = formStore.excel;
        if(file){
            processFile(file);
        }
    }

    const triggerFileInput = () => {
        fileInput.value.click();
    };

    function processFile(file){
        let allowedFileTypes = [
            "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        ];
        if (!allowedFileTypes.includes(file.type)) {
            alert('Please upload a valid Excel file.');
            return;
        }

        let url = route("products.store",props.project.id);

        formStore.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                uploading.value = false;

                clearFileInput();

                //Clarifications
                formClarifications = useForm(Object.assign({}, props.partialProductMatches, {deletedIds:[]}));
                console.log("after store",formClarifications.deletedIds);
                if(props.partialProductMatches.length > 0){
                    showClarifications.value = true;
                }
                else{
                    showUserCustomProducts.value = true;
                }
            },
            onError: errors => {
                console.log('errors',errors);
                uploading.value = false;
                clearFileInput();
            },
        });

        uploading.value = true;
    }

    function clearFileInput() {
        const fileInput = document.getElementById("dropzone-file");
        if(fileInput){
            fileInput.value = ""; // Clear the file input
            console.log("File input cleared!");
        }
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
                // dropzone.textContent = 'Drag and drop your excel file here';
            });
        }
    });

    function getUnitDisplay(row,slash){
        let unitDisplay = "";

        if(row.nesting_algo === "METERAGE"){
            unitDisplay =  slash ? "/mm" : "mm";
        }
        if(row.nesting_algo === "AREA"){
            unitDisplay =  slash ? "/mm" : "mm";
        }
        if(row.nesting_algo === "BUNDLE"){
            unitDisplay =  slash ? "/each" : "";
        }

        return unitDisplay;
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
        let url = route("raw.material.quote.clarifications",props.business.id);
        formClarifications.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log('success');
                //Hide clarifications
                showClarifications.value = false;

                //Update custom products
                formCustomisations = useForm(Object.assign({}, props.requiresCustom, {deletedIds:[]}));

                //Show custom products
                showUserCustomProducts.value = true;
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }

    function submitCustomisations(){
        let url = route("raw.material.quote.customisations",props.business.id);
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
            display = row.product.product_derived_label;
        }

        return display;
    }

    function hasClarifications(){
        return props.partialProductMatches.length > 0;
    }
    function hasUserCustomProducts(){
        return props.requiresCustom.length > 0;
    }

    function showTable(){
        return !hasClarifications() && !hasUserCustomProducts() && props.materialListRows.length > 0;
    }

    function deleteAll(){
        let message = "Are you sure you want delete all material imports for " + props.project.name;
        const userConfirmed = confirm(message);
        if (userConfirmed) {
            formBulkActions.selectedRawMaterialQuoteIds = getAllMaterialQuoteIds();
            submitBulkDelete();
        }
    }

    function deleteOneCustomisation(rawMaterialQuoteId){
        let message = "Are you sure you want delete this item?";
        const userConfirmed = confirm(message);
        if (userConfirmed) {
            //Add to list of "promise to delete" to actually delete after submitting form
            formCustomisations.deletedIds.push(rawMaterialQuoteId);

            //If delete all the items, then auto submit the form
            if(props.requiresCustom.length === formCustomisations.deletedIds.length){
                submitCustomisations();
            }
        }
    }

    function deleteOneClarification(rawMaterialQuoteId){
        let message = "Are you sure you want delete this item?";
        const userConfirmed = confirm(message);
        if (userConfirmed) {
            //Add to list of "promise to delete" to actually delete after submitting form
            formClarifications.deletedIds.push(rawMaterialQuoteId);

            //If delete all the items, then auto submit the form
            if(props.partialProductMatches.length === formClarifications.deletedIds.length){
                submitClarifications();
            }
        }
    }

    function isNumeric(value) {
        return !isNaN(value) && !isNaN(parseFloat(value));
    }

    function isDeletedCustomisation(id){
        let isDeleted = false;

        if(formCustomisations.deletedIds !== undefined){
            isDeleted = formCustomisations.deletedIds.includes(id);
        }

        return isDeleted;
    }

    function isDeletedClarification(id){
        let isDeleted = false;

        if(formClarifications.deletedIds !== undefined){
            isDeleted = formClarifications.deletedIds.includes(id);
        }

        return isDeleted;
    }

    function displayLength(row){
        let displayLength = "";

        //Bundle
        if(row.nesting_algo !== "BUNDLE"){
            displayLength = parseFloat(row.length_required).toLocaleString();
        }

        return displayLength;
    }
</script>

<template>
    <Head title="Import" />

    <AuthenticatedLayout>
        <div class="py-5">
            <section class="container max-w-5xl mx-auto">
                <button
                    v-if="materialListRows.length > 0"
                    class="bg-red-100 px-2 py-1 rounded mb-3"
                    @click="deleteAll()"
                >
                    <b>Delete all imports</b> for {{project.name}}
                </button>
            </section>

            <!-- Pre-upload checklist and file upload (v-if="senseChecks.length === 0") -->
            <template v-if="!hasClarifications() && !hasUserCustomProducts()">
                <!-- clarifications (might re-upload) -->
<!--                <section class="container max-w-5xl mx-auto">-->
<!--                    <h2 class="font-bold text-lg">Pre-upload checklist:</h2>-->
<!--                    <div class="flex">-->
<!--                        <input-->
<!--                            v-model="formPreChecklist.one"-->
<!--                            type="checkbox"-->
<!--                            class="mt-1 mr-2"-->
<!--                            id="one"-->
<!--                        />-->
<!--                        <label for="one">Are materials un-nested? <small>(the software performs cross-project nesting for material efficiency)</small></label>-->
<!--                    </div>-->
<!--                    <div class="flex">-->
<!--                        <input-->
<!--                            v-model="formPreChecklist.two"-->
<!--                            type="checkbox"-->
<!--                            class="mt-1 mr-2"-->
<!--                            id="two"-->
<!--                        />-->
<!--                        <label for="two">Are consumables allowed for?</label>-->
<!--                    </div>-->
<!--                    <div class="flex">-->
<!--                        <input-->
<!--                            v-model="formPreChecklist.three"-->
<!--                            type="checkbox"-->
<!--                            class="mt-1 mr-2"-->
<!--                            id="three"-->
<!--                        />-->
<!--                        <label for="three">Are deliveries allowed for?</label>-->
<!--                    </div>-->
<!--                </section>-->

                <div class="mx-auto max-w-5xl mt-2">
                    <div>
                        <div
                            id="dropzone"
                            @click="triggerFileInput"
                            @dragover.prevent="handleDragOver"
                            @dragleave="handleDragLeave"
                            @drop.prevent="handleDrop"
                            class="bg-white"
                            :style="(isDragging ? 'border-color: #00f;color: #00f;' : 'color: #aaa;') + (formStore.processing ? 'pointer-events: none;' : '')"
                        >
                            <div class="w-full mx-auto text-center">
                                <div v-if="uploading" class="text-green-500">
                                    <div class="flex gap-x-2 justify-center load-6">
                                        <div class="letter-holder">
                                            <div class="l-1 letter">I</div>
                                            <div class="l-2 letter">m</div>
                                            <div class="l-3 letter">p</div>
                                            <div class="l-4 letter">o</div>
                                            <div class="l-5 letter">r</div>
                                            <div class="l-6 letter">t</div>
                                            <div class="l-7 letter">i</div>
                                            <div class="l-8 letter">n</div>
                                            <div class="l-9 letter">g</div>
                                            <div class="l-10 letter">.</div>
                                            <div class="l-11 letter">.</div>
                                            <div class="l-12 letter">.</div>
                                        </div>
                                        <div>
                                            it can take 10 seconds or so
                                        </div>
                                    </div>
                                </div>
                                <div v-else>
                                    {{isDragging ? 'Drop it here!' : 'Material list in Excel format: Click to upload, or drag & drop here'}}
                                </div>
                            </div>
                        </div>
                        <input
                            id="dropzone-file"
                            type="file"
                            ref="fileInput"
                            accept=".xlsx,.xls"
                            @input="formStore.excel = $event.target.files[0]; handleFileSelect()"
                            hidden
                        />
                    </div>


                    <div v-if="warning" class="text-center text-orange-500 mt-2">
                        {{warning}}
                    </div>
                </div>
            </template>

            <!-- clarifications (user might decide to re-upload) -->
            <section v-if="senseChecks.length > 0" class="container max-w-5xl mx-auto mt-5">
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
                <div v-if="senseChecks.certificates < 100" class="mt-2">
                    <input
                        v-model="formPreChecklist.certificates"
                        type="checkbox"
                        class="mr-2"
                        id="certificates"
                    />
                    <label for="certificates">Are product certificates required?</label>
                </div>

                <!-- todo: you normally purchase X with Y-->

                <!-- todo: there's beams. Where's columns? -->
                <!-- todo: there's columns. Where's beams? -->

                <!-- todo: tonnage checks -->

                <!-- todo: minimum grade check-->

            </section>

            <!-- Clarifications (preparing for RFQ) -->
            <section v-if="showClarifications && hasClarifications()" class="container max-w-5xl mx-auto mt-5">
                <h2 class="font-bold text-lg">Exact product clarifications</h2>
                <form @submit.prevent="submitClarifications()">
                    <template v-for="(item,index) in formClarifications">
                        <div v-if="isNumeric(index) && !isDeletedClarification(item.data.id)" class="mt-5">
                            <p class="italic font-bold">"{{item.data.description}}" <span class="text-red-500 ml-2" style="cursor: pointer;" @click="deleteOneClarification(item.data.id)"><i class="fa-solid fa-xmark"></i></span></p>
                            <div class="grid grid-cols-3">
                                <div v-for="(option,option_index) in item.options">
                                    <label>
                                        <input
                                            v-model="formClarifications[index]['selected']"
                                            type="radio"
                                            :name="index"
                                            :value="option_index"
                                            required
                                        >
                                        {{ option.product_derived_label }}
                                    </label>
                                </div>
                                <label>
                                    <input
                                        v-model="formClarifications[index]['selected']"
                                        type="radio"
                                        :name="index"
                                        value="customise"
                                        required
                                    >
                                    Custom (next step)
                                </label>
                            </div>
                        </div>
                    </template>

                    <button
                        type="submit"
                        class="bg-green-500 rounded px-2 py-1"
                        :disabled="formClarifications.processing"
                    >
                        {{formClarifications.processing ? 'Saving..' : 'Save all'}}
                    </button>
                </form>
            </section>

            <!-- User custom products -->
            <section v-else-if="showUserCustomProducts && hasUserCustomProducts()" class="container max-w-5xl mx-auto mt-5">
                <h2 class="font-bold text-lg">Custom products (add to price book)</h2>
                <p class="mb-3 text-gray-600">
                    This action is just required once. It will be added to the price book for you and other members in your company.
                </p>

                <form @submit.prevent="submitCustomisations()">
                    <div class="grid grid-cols-3 gap-6">
                        <template v-for="(item,index) in formCustomisations">
                            <CustomProductForm
                                v-if="isNumeric(index) && !isDeletedCustomisation(item.data.id)"
                                class="mt-3"
                                :item="item"
                                :index="index"
                                :form="formCustomisations"
                                :allMeasurements="allMeasurements"
                                :formDependentData="formDependentData"
                                :allGrades="allGrades"
                                :nestingGroups="nestingGroups"
                                @deleteOneCustomisation="id => deleteOneCustomisation(id)"
                                :key="'custom-product-form-'+index"
                            />
                        </template>
                    </div>

                    <button
                        type="submit"
                        class="bg-green-500 rounded px-2 py-1"
                        :disabled="formCustomisations.processing"
                    >
                        {{ formCustomisations.processing ? 'Saving...' : 'Save all' }}
                    </button>
                </form>
            </section>

            <!-- table -->
            <section v-if="showTable()" class="container max-w-5xl mx-auto mt-2">
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
                                <div class="relative overflow-auto">
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
                                                    <span>Subtotal</span>
                                                </div>
                                            </th>

                                            <th scope="col" class="sticky top-0 py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                <div class="flex items-center gap-x-3">
                                                    <span>Price Book</span>
                                                </div>
                                            </th>

                                            <th scope="col" class="sticky top-0 py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                <div class="flex items-center gap-x-3">
                                                    <span>Reference</span>
                                                </div>
                                            </th>

<!--                                            <th scope="col" class="sticky top-0 relative py-3.5 px-4">-->
<!--                                                <span class="sr-only">Edit</span>-->
<!--                                            </th>-->
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
                                                                {{ displayLength(row) }}<span class="text-xs">{{getUnitDisplay(row,false)}}</span>
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
                                                            <h2 v-if="row.nesting_algo === 'AREA'" class="font-medium text-gray-800 dark:text-white ">
                                                                {{ parseFloat(row.width_required).toLocaleString() }}<span class="text-xs">{{getUnitDisplay(row,false)}}</span>
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
                                                                {{ parseFloat(row.sub_qty).toFixed(2) }}
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
                                                                {{ new Intl.NumberFormat('en-US', {style: 'currency', currency: 'USD',}).format(row.length_required * row.sub_qty / 1000) }}
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

                                            <!-- Assembly ref -->
                                            <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                <div class="inline-flex items-center gap-x-3">
                                                    <div class="flex items-center gap-x-2">
                                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="m0 0h512v512h-512z"/><path d="m39.557 19 283.883 254h149.003l-283.883-254h-149.002zm-14.557 11.13v25.847l286 255.893v-25.846zm64 107.263v34.584l286 255.893v-84.843l-64-13.002zm-11.445 48.497-42.9 10.723 287.79 257.498 42.9-10.723-287.789-257.498zm-52.555 26.24v23.847l286 255.893v-23.847zm304 78.87v21.973l64 16v126.054l-64 16v21.973h158v-21.973l-64-16v-126.054l64-16v-21.973zm112 135.865v14.108l21.88 5.47z" fill="#fff"/></svg>
                                                        <div>
                                                            <h2 class="font-medium text-gray-800 dark:text-white italic">
                                                                "{{ row.assembly_mark }}"
                                                            </h2>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>

<!--                                            <td class="px-4 py-4 text-sm whitespace-nowrap">-->
<!--                                                <div class="flex items-center gap-x-6">-->
<!--                                                    <p-->
<!--                                                        @click="initiateUpdate(template)"-->
<!--                                                        class="text-gray-500 transition-colors duration-200 dark:hover:text-yellow-500 dark:text-gray-300 hover:text-yellow-500 focus:outline-none"-->
<!--                                                    >-->
<!--                                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">-->
<!--                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />-->
<!--                                                        </svg>-->
<!--                                                    </p>-->
<!--                                                </div>-->
<!--                                            </td>-->
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


    /* -----------------------------------------
      Loader
    -------------------------------------------- */

    .load-wrapp p {
        padding: 0 0 20px;
    }

    .letter {
        float: left;
        font-size: 16px;
    }

    .load-6 .letter {
        animation-name: loadingF;
        animation-duration: 1.6s;
        animation-iteration-count: infinite;
        animation-direction: linear;
    }

    .l-1 {
        animation-delay: 0.48s;
    }
    .l-2 {
        animation-delay: 0.6s;
    }
    .l-3 {
        animation-delay: 0.72s;
    }
    .l-4 {
        animation-delay: 0.84s;
    }
    .l-5 {
        animation-delay: 0.96s;
    }
    .l-6 {
        animation-delay: 1.08s;
    }
    .l-7 {
        animation-delay: 1.2s;
    }
    .l-8 {
        animation-delay: 1.32s;
    }
    .l-9 {
        animation-delay: 1.44s;
    }
    .l-10 {
        animation-delay: 1.56s;
    }
    .l-11 {
        animation-delay: 1.56s;
    }
    .l-12 {
        animation-delay: 1.56s;
    }

    @keyframes loadingA {
    0 {
        height: 15px;
    }
    50% {
        height: 35px;
    }
    100% {
        height: 15px;
    }
    }

    @keyframes loadingB {
    0 {
        width: 15px;
    }
    50% {
        width: 35px;
    }
    100% {
        width: 15px;
    }
    }

    @keyframes loadingC {
    0 {
        transform: translate(0, 0);
    }
    50% {
        transform: translate(0, 15px);
    }
    100% {
        transform: translate(0, 0);
    }
    }

    @keyframes loadingD {
    0 {
        transform: rotate(0deg);
    }
    50% {
        transform: rotate(180deg);
    }
    100% {
        transform: rotate(360deg);
    }
    }

    @keyframes loadingE {
    0 {
        transform: rotate(0deg);
    }
    100% {
        transform: rotate(360deg);
    }
    }

    @keyframes loadingF {
        0% {
            opacity: 0;
        }
        100% {
            opacity: 1;
        }
    }

    @keyframes loadingG {
        0% {
            transform: translate(0, 0) rotate(0deg);
        }
        50% {
            transform: translate(70px, 0) rotate(360deg);
        }
        100% {
            transform: translate(0, 0) rotate(0deg);
        }
    }

    @keyframes loadingH {
        0% {
            width: 15px;
        }
        50% {
            width: 35px;
            padding: 4px;
        }
        100% {
            width: 15px;
        }
    }

    @keyframes loadingI {
        100% {
            transform: rotate(360deg);
        }
    }

    @keyframes bounce {
        0%,
        100% {
            transform: scale(0);
        }
        50% {
            transform: scale(1);
        }
    }

    @keyframes loadingJ {
        0%,
        100% {
            transform: translate(0, 0);
        }

        50% {
            transform: translate(80px, 0);
            background-color: #f5634a;
            width: 25px;
        }
    }
</style>

