<script setup>
    //General Imports
    import {computed, ref, watch} from "vue";
    import {useForm, usePage, router} from "@inertiajs/vue3";

    //Component Imports
    import Modal from "@/Layouts/Modal.vue";
    import CustomProductForm from "@/Components/CustomProductForm.vue";

    //Props
    const props = defineProps({
        width: String,
        project: Object,
        bomData: Object,
    });

    //Forms
    const formStore = useForm({
        excel: null,
    });
    let formClarifications = thisDownloadedBomData(props.bomData)
        ? (useForm(Object.assign({}, thisDownloadedBomData(props.bomData).partialProductMatches, {deletedIds:[]})))
        : null;
    let formCustomisations = thisDownloadedBomData(props.bomData)
        ? (useForm(Object.assign({}, thisDownloadedBomData(props.bomData).requiresCustom, {deletedIds:[]})))
        : null;
    const formBulkActions = useForm({
        selectedRawMaterialQuoteIds: [],
    });

    //Shared data
    const warning = computed(() => usePage().props.flash.warning);

    //Variables
    const emit = defineEmits(['closeModalOnSuccess']);
    const isDragging = ref(false);
    const uploading = ref(false);
    const fileInput = ref(null);
    const allChecked = ref(false); //todo
    const showClarifications = ref(hasClarifications());
    const showUserCustomProducts = ref(hasUserCustomProducts());
    const isAdmin = usePage().props.auth.isAdmin;
    const business = usePage().props.auth.business;

    //Shared Methods
    //

    //Methods
    const triggerFileInput = () => {
        fileInput.value.click();
    };

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
                //todo
                formClarifications = useForm(Object.assign({}, thisDownloadedBomData(props.bomData).partialProductMatches, {deletedIds:[]}));
                console.log("after store",formClarifications.deletedIds);
                if(thisDownloadedBomData(props.bomData).partialProductMatches.length > 0){
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

    function handleFileSelect(){
        const file = formStore.excel;
        if(file){
            processFile(file);
        }
    }

    function hasClarifications(){
        return thisDownloadedBomData(props.bomData)
            ? (thisDownloadedBomData(props.bomData).partialProductMatches.length > 0)
            : false;
    }

    function hasUserCustomProducts(){
        return thisDownloadedBomData(props.bomData)
            ? (thisDownloadedBomData(props.bomData).requiresCustom.length > 0)
            : false;
    }

    function submitCustomisations(){
        let url = route("raw.material.quote.customisations",business.id);
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

    function thisDownloadedBomData(bomData){
        let data = null;

        if(bomData){
            let rawData = Object.values(bomData).find(item => item.project_id == props.project.id);
            if(rawData){
                data = rawData.data;
            }
        }

        return data;
    }

    function submitClarifications(){
        let url = route("raw.material.quote.clarifications",business.id);
        formClarifications.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log('success');
                //Hide clarifications
                showClarifications.value = false;

                //Update custom products
                formCustomisations = useForm(Object.assign({}, thisDownloadedBomData(props.bomData).requiresCustom, {deletedIds:[]}));

                //Show custom products
                showUserCustomProducts.value = true;
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }

    function isNumeric(value) {
        return !isNaN(value) && !isNaN(parseFloat(value));
    }

    function deleteOneClarification(rawMaterialQuoteId){
        let message = "Are you sure you want delete this item?";
        const userConfirmed = confirm(message);
        if (userConfirmed) {
            //Add to list of "promise to delete" to actually delete after submitting form
            formClarifications.deletedIds.push(rawMaterialQuoteId);

            //If delete all the items, then auto submit the form
            if(thisDownloadedBomData(props.bomData).partialProductMatches.length === formClarifications.deletedIds.length){
                submitClarifications();
            }
        }
    }

    function isDeletedCustomisation(id){
        let isDeleted = false;

        if(formCustomisations.deletedIds !== undefined){
            isDeleted = formCustomisations.deletedIds.includes(id);
        }

        return isDeleted;
    }

    function deleteOneCustomisation(rawMaterialQuoteId){
        let message = "Are you sure you want delete this item?";
        const userConfirmed = confirm(message);
        if (userConfirmed) {
            //Add to list of "promise to delete" to actually delete after submitting form
            formCustomisations.deletedIds.push(rawMaterialQuoteId);

            //If delete all the items, then auto submit the form
            if(thisDownloadedBomData(props.bomData).requiresCustom.length === formCustomisations.deletedIds.length){
                submitCustomisations();
            }
        }
    }

    function showTable(){
        return !hasClarifications() && !hasUserCustomProducts() && thisDownloadedBomData(props.bomData).materialListRows.length > 0;
    }

    function isDeletedClarification(id){
        let isDeleted = false;

        if(formClarifications.deletedIds !== undefined){
            isDeleted = formClarifications.deletedIds.includes(id);
        }

        return isDeleted;
    }

    //Watcher
    watch(props.bomData, (newVal) => {
        if(newVal){
            showClarifications.value = hasClarifications();
            showUserCustomProducts.value = hasUserCustomProducts();

            formClarifications = useForm(Object.assign({}, thisDownloadedBomData(props.bomData).partialProductMatches, {deletedIds:[]}));
            formCustomisations = useForm(Object.assign({}, thisDownloadedBomData(props.bomData).requiresCustom, {deletedIds:[]}));
        }
    });
</script>

<template>
    <Modal>
        <div :style="'width:'+width+'px'">

            <div class="dark:bg-gray-900 rounded-xl">
                <div class="px-6 pt-4 pb-4 mx-auto text-center">
                    <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100">
                        Bill of Materials
                    </h1>


                    <div class="mt-2">
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

                    <div v-if="thisDownloadedBomData(bomData)" class="p-3 mt-5 overflow-y-auto" style="height:300px">
                        <!-- clarifications (user might decide to re-upload) -->
                        <section v-if="thisDownloadedBomData(bomData).senseChecks.length > 0" class="container mt-5">
                            <h2 class="font-bold text-lg">Sense checks</h2>

                            <!-- No bolts -->
                            <div v-if="!thisDownloadedBomData(bomData).senseChecks.has_bolts" class="mt-2">
                                <input
                                    v-model="thisDownloadedBomData(bomData).formPreChecklist.no_bolts"
                                    type="checkbox"
                                    class="mr-2"
                                    id="no_bolts"
                                />
                                <label for="no_bolts">No bolts found. This is correct?</label>
                            </div>

                            <!-- Nuts & washers allowed for?-->
                            <div v-if="thisDownloadedBomData(bomData).senseChecks.has_bolts" class="mt-2">
                                <input
                                    v-model="thisDownloadedBomData(bomData).formPreChecklist.has_bolts"
                                    type="checkbox"
                                    class="mr-2"
                                    id="has_bolts"
                                />
                                <label for="has_bolts">Do the bolts included have cost allowance for nuts and washers?</label>
                            </div>

                            <!-- Bolt quantity -->
                            <div v-if="thisDownloadedBomData(bomData).senseChecks.bolt_qty < 100" class="mt-2">
                                <input
                                    v-model="thisDownloadedBomData(bomData).formPreChecklist.bolt_qty"
                                    type="checkbox"
                                    class="mr-2"
                                    id="bolt_qty"
                                />
                                <label for="bolt_qty">There's only {{thisDownloadedBomData(bomData).senseChecks.bolt_qty}} bolts? This is correct?</label>
                            </div>

                            <!-- Mill certs -->
                            <div v-if="thisDownloadedBomData(bomData).senseChecks.certificates < 100" class="mt-2">
                                <input
                                    v-model="thisDownloadedBomData(bomData).formPreChecklist.certificates"
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
                        <section v-if="showClarifications && hasClarifications()" class="container mt-5">
                            <h2 class="font-bold text-lg">Exact product clarifications</h2>
                            <form @submit.prevent="submitClarifications()">
                                <template v-for="(item,index) in formClarifications">
                                    <div v-if="isNumeric(index) && !isDeletedClarification(item.data.id)" class="mt-5">
                                        <p class="italic font-bold text-left">"{{item.data.description}}" <span class="text-red-500 ml-2" style="cursor: pointer;" @click="deleteOneClarification(item.data.id)"><i class="fa-solid fa-xmark"></i></span></p>
                                        <div class="grid grid-cols-3 text-left">
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
                        <section v-else-if="showUserCustomProducts && hasUserCustomProducts()" class="mt-5">
                            <h2 class="font-bold text-lg">Custom products (add to price book)</h2>
                            <p class="mb-3 text-gray-600">
                                This action is just required once. It will be added to the price book for you and other members in your company.
                            </p>

                            <form @submit.prevent="submitCustomisations()">
                                <div class="grid grid-cols-3 gap-6">
                                    <template v-for="(item,index) in formCustomisations">
                                        <CustomProductForm
                                            v-if="isNumeric(index) && !isDeletedCustomisation(item.data.id)"
                                            class="mt-3 text-left"
                                            :item="item"
                                            :index="index"
                                            :form="formCustomisations"
                                            :allMeasurements="thisDownloadedBomData(bomData).allMeasurements"
                                            :formDependentData="thisDownloadedBomData(bomData).formDependentData"
                                            :allGrades="thisDownloadedBomData(bomData).allGrades"
                                            :nestingGroups="thisDownloadedBomData(bomData).nestingGroups"
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
                        <section v-if="showTable()" class="container mt-2">
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
                                                                <span>Rate</span>
                                                            </div>
                                                        </th>

                                                        <th v-if="isAdmin" scope="col" class="sticky top-0 py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                            <div class="flex items-center gap-x-3">
                                                                <span>Baseline</span>
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
                                                        <!-- unit rate -->
                                                        <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                            <div class="inline-flex items-center gap-x-3">
                                                                <div class="flex items-center gap-x-2">
                                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="m0 0h512v512h-512z"/><path d="m39.557 19 283.883 254h149.003l-283.883-254h-149.002zm-14.557 11.13v25.847l286 255.893v-25.846zm64 107.263v34.584l286 255.893v-84.843l-64-13.002zm-11.445 48.497-42.9 10.723 287.79 257.498 42.9-10.723-287.789-257.498zm-52.555 26.24v23.847l286 255.893v-23.847zm304 78.87v21.973l64 16v126.054l-64 16v21.973h158v-21.973l-64-16v-126.054l64-16v-21.973zm112 135.865v14.108l21.88 5.47z" fill="#fff"/></svg>
                                                                    <div>
                                                                        <h2 class="font-medium text-gray-800 dark:text-white ">
                                                                            {{ new Intl.NumberFormat('en-US', {style: 'currency', currency: 'USD',}).format(row.unit_rate) }}<span class="text-xs">{{getUnitDisplay(row,true)}}</span>
                                                                        </h2>
                                                                        <p v-if="row.baseline_unit_rate_comparison !== 'NONE'" :class="getUnitRateColour(row)" class="text-xs">Too {{ row.baseline_unit_rate_comparison.toLowerCase() }}?</p>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <!-- baseline (admin) -->
                                                        <td v-if="isAdmin" class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                            <div class="inline-flex items-center gap-x-3">
                                                                <div class="flex items-center gap-x-2">
                                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="m0 0h512v512h-512z"/><path d="m39.557 19 283.883 254h149.003l-283.883-254h-149.002zm-14.557 11.13v25.847l286 255.893v-25.846zm64 107.263v34.584l286 255.893v-84.843l-64-13.002zm-11.445 48.497-42.9 10.723 287.79 257.498 42.9-10.723-287.789-257.498zm-52.555 26.24v23.847l286 255.893v-23.847zm304 78.87v21.973l64 16v126.054l-64 16v21.973h158v-21.973l-64-16v-126.054l64-16v-21.973zm112 135.865v14.108l21.88 5.47z" fill="#fff"/></svg>
                                                                    <div>
                                                                        <h2 v-if="row.baseline_unit_rate" class="font-medium text-gray-800 dark:text-white ">
                                                                            {{ new Intl.NumberFormat('en-US', {style: 'currency', currency: 'USD',}).format(row.baseline_unit_rate) }}<span class="text-xs">/m</span>
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
                                                                            {{ new Intl.NumberFormat('en-US', {style: 'currency', currency: 'USD',}).format(row.length_required * row.sub_qty * row.unit_rate / 1000) }}
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
                    <!-- loading download data -->
                    <p v-else class="mt-5">
                        {{thisDownloadedBomData(bomData) ??'Loading BOM data...'}}
                    </p>
                </div>
            </div>
        </div>
    </Modal>
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
