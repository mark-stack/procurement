<script setup>
    //General Imports
    import {computed, ref, toRefs, watch} from "vue";
    import {useForm, usePage} from "@inertiajs/vue3";

    //Component Imports
    import Modal from "@/Layouts/Modal.vue";
    import ConfirmModal from "@/Components/Modals/ConfirmModal.vue";
    import CustomProductForm from "@/Components/CustomProductForm.vue";
    import useConfirm from "@/Shared/useConfirm.js";

    //Props
    const props = defineProps({
        width: String,
        project: Object,
        bomData: Object,
        refreshModalBom: Boolean,
        modalCanUpload: Boolean,
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
    const emit = defineEmits(['closeModalOnSuccess','redownload']);
    const isDragging = ref(false);
    const uploading = ref(false);
    const fileInput = ref(null);
    const allChecked = ref(false);
    const showClarifications = ref(hasClarifications());
    const showUserCustomProducts = ref(hasUserCustomProducts());
    const isAdmin = usePage().props.auth.isAdmin;
    const business = usePage().props.auth.business;
    const freezeView = ref(false);

    //Shared Methods
    import shared from "@/Shared/shared.js";
    const {confirmDialog, askToConfirm, confirmDialogAccepted, confirmDialogCancelled} = useConfirm();

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

        let url = route("projects.products.store",props.project.id);

        formStore.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                uploading.value = false;

                clearFileInput();

                //Re-download
                reloadAndDownloadModal();
            },
            onError: errors => {
                console.log('errors',errors);
                uploading.value = false;
                clearFileInput();
            },
        });

        uploading.value = true;
        freezeView.value = true;
    }

    function clearFileInput() {
        const fileInput = document.getElementById("dropzone-file");
        if(fileInput){
            fileInput.value = ""; // Clear the file input
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

    function hasSenseChecks(){
        return thisDownloadedBomData(props.bomData)
            ? (thisDownloadedBomData(props.bomData).senseChecks.length > 0)
            : false;
    }

    function hasMaterialList(){
        return thisDownloadedBomData(props.bomData)
            ? (thisDownloadedBomData(props.bomData).materialListRows.length > 0)
            : false;
    }

    function submitCustomisations(){
        let url = route("raw.material.quote.customisations");
        formCustomisations.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log("success response after 'submitCustomisations'");

                //Hide customisations
                showUserCustomProducts.value = false;

                //Re-download
                reloadAndDownloadModal();
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }

    function thisDownloadedBomData(bomData){
        /**
         Get just the data component from payload
         */
        let data = null;

        if(bomData && props.project){
            if(bomData.project_id === props.project.id){
                data = bomData.data;
            }
        }

        return data;

        // let data = null;
        //
        // console.log("bomData",bomData.data);
        // console.log("project id", props.project.id);
        //
        // if(bomData){
        //     let rawData = Object.values(bomData).find(item => item.project_id == props.project.id);
        //     if(rawData){
        //         data = rawData.data;
        //     }
        // }
        //
        // console.log("data",data);
        //
        // return data;
    }

    function submitClarifications(){
        let url = route("raw.material.quote.clarifications");
        formClarifications.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log("success response after 'submitClarifications'");
                reloadAndDownloadModal();
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }

    function reloadAndDownloadModal(){
        freezeView.value = true;

        emit("redownload",props.project);
    }

    function isNumeric(value) {
        return !isNaN(value) && !isNaN(parseFloat(value));
    }

    function deleteOneClarification(rawMaterialQuoteId){
        askToConfirm({
            title: "Delete this item?",
            message: "It will be dropped from this project's material list when you save.",
            confirmLabel: "Delete item",
            tone: "danger",
            onConfirmed: () => {
                //Add to list of "promise to delete" to actually delete after submitting form
                formClarifications.deletedIds.push(rawMaterialQuoteId);

                //If delete all the items, then auto submit the form
                if(thisDownloadedBomData(props.bomData).partialProductMatches.length === formClarifications.deletedIds.length){
                    submitClarifications();
                }
            },
        });
    }

    function isDeletedCustomisation(id){
        let isDeleted = false;

        if(formCustomisations.deletedIds !== undefined){
            isDeleted = formCustomisations.deletedIds.includes(id);
        }

        return isDeleted;
    }

    function deleteOneCustomisation(rawMaterialQuoteId){
        askToConfirm({
            title: "Delete this item?",
            message: "It will be dropped from this project's material list when you save.",
            confirmLabel: "Delete item",
            tone: "danger",
            onConfirmed: () => {
                //Add to list of "promise to delete" to actually delete after submitting form
                formCustomisations.deletedIds.push(rawMaterialQuoteId);

                //If delete all the items, then auto submit the form
                if(thisDownloadedBomData(props.bomData).requiresCustom.length === formCustomisations.deletedIds.length){
                    submitCustomisations();
                }
            },
        });
    }

    function showTable(){
        return !hasClarifications() && !hasUserCustomProducts() && hasMaterialList();
    }

    function isDeletedClarification(id){
        let isDeleted = false;

        if(formClarifications.deletedIds !== undefined){
            isDeleted = formClarifications.deletedIds.includes(id);
        }

        return isDeleted;
    }

    function submitBulkDelete(){
        let url = route("raw.material.quote.bulk.destroy");
        formBulkActions.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                formBulkActions.selectedRawMaterialQuoteIds = [];
                allChecked.value = false;
                clearFileInput();

                //Re-download
                reloadAndDownloadModal();
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

    function getAllMaterialQuoteIds(){
        let result = [];
        Object.values(thisDownloadedBomData(props.bomData).materialListRows).forEach(item => {
            if(!item.status){
                result.push(item.id);
            }
        });

        return result;
    }

    function displayLength(row){
        let displayLength = "";

        //Bundle
        if(row.nesting_algo !== "BUNDLE"){
            displayLength = parseFloat(row.length_required).toLocaleString();
        }

        return displayLength;
    }

    function displayProductMatches(row){
        let display = "user-custom";

        //Has one product match
        if(row['product']){
            display = row.product.product_derived_label;
        }

        return display;
    }

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

    function getPriceUnitDisplay(row,slash){
        let unitDisplay = "";

        if(row.nesting_algo === "METERAGE"){
            unitDisplay =  slash ? "/m" : "m";
        }

        return unitDisplay;
    }

    function canUpload(){
        /**
         * 1) Nesting stage only (nesting card).
         * 2) Don't show whilst clarifying or doing custom products
         */
        return props.modalCanUpload && !hasClarifications() && !hasUserCustomProducts();
    }

    function canDelete(){
        /**
         * Nesting stage only (nesting card).
         */
        return props.modalCanUpload;
    }

    //Watcher
    const { refreshModalBom } = toRefs(props);
    watch(refreshModalBom, (newVal) => {
        freezeView.value = false;

        showClarifications.value = hasClarifications();
        showUserCustomProducts.value = hasUserCustomProducts();

        formClarifications = useForm(Object.assign({}, thisDownloadedBomData(props.bomData).partialProductMatches, {deletedIds:[]}));
        formCustomisations = useForm(Object.assign({}, thisDownloadedBomData(props.bomData).requiresCustom, {deletedIds:[]}));
    });
</script>

<template>
    <Modal :fakeModal="false" redirect="current" ariaLabel="Bill of Materials">
        <div :style="'width:'+width+'px'">

            <div class="dark:bg-gray-900 rounded-xl">
                <div class="pt-4 pb-4 mx-auto text-center">
                    <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100">
                        Bill of Materials
                    </h1>

                    <div
                        v-if="freezeView"
                        style="height:400px"
                        class="p-20 text-gray-700 italic"
                    >
                        <span class="block font-bold text-xl">Calculating...</span>
                        <span class="block text-lg">“Patience is bitter, but its fruit is sweet.”</span>
                    </div>
                    <div v-else class="pt-5">
                        <!-- Drag n drop  -->
                        <div
                            v-if="canUpload()"
                            class="pl-5 pr-5"
                        >
                            <!-- Rectangle -->
                            <div>
                                <!-- (isDragging ? 'border-color: #00f;color: #00f;' : 'color: #aaa;') +  -->
                                <div
                                    id="dropzone"
                                    @click="triggerFileInput"
                                    @dragover.prevent="handleDragOver"
                                    @dragleave="handleDragLeave"
                                    @drop.prevent="handleDrop"
                                    class="border-2 text-gray-600 hover:text-gray-700 bg-gray-50 hover:bg-gray-100 border-gray-400 hover:border-gray-500 border-dashed font-semibold text-lg"
                                    :class="isDragging ? 'bg-gray-100' : 'bg-gray-50'"
                                    :style="(formStore.processing ? 'pointer-events: none;' : '')"
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
                                        <div v-else class="text-gray-700">
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

                            <!-- drag n drop error -->
                            <div v-if="warning" class="text-center text-orange-500 mt-2">
                                {{warning}}
                            </div>
                        </div>

                        <!-- Sense checks / Clarifications / User custom products / Table-->
                        <div class="overflow-y-auto pl-5 pr-1">
                            <!-- Sense checks -->
                            <section
                                v-if="hasSenseChecks()"
                                style="height:400px"
                            >
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

                            <!-- Clarifications  -->
                            <section
                                v-else-if="showClarifications && hasClarifications()"
                                style="height:400px"
                                class="pl-5"
                            >
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
                                                <label v-if="business.allow_custom_products">
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
                            <section
                                v-else-if="showUserCustomProducts && hasUserCustomProducts()"
                                style="height:400px"
                            >
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
                            <section
                                v-else-if="showTable()"
                                style="height:350px"
                                class="text-left"
                            >

                                <!-- v-if="thisDownloadedBomData(props.bomData).itemsNotFound && thisDownloadedBomData(props.bomData).business.meterage_only" -->
                                <div v-if="thisDownloadedBomData(props.bomData).itemsNotFound" class="mt-2 flex justify-between rounded-lg py-2 px-4 bg-[#fff2b2] text-[#7c620c]">
                                    <p class="font-sans text-xs">
                                        Items from your uploaded BOM's for this project that are not recognised as linear stock:
                                        <br><span class="text-sm">{{thisDownloadedBomData(props.bomData).itemsNotFound}}</span>
                                    </p>
                                </div>

                                <button
                                    v-if="canDelete()"
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
                                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-left">
                                                        <thead class="sticky top-0 bg-gray-50 dark:bg-gray-800">
                                                        <tr>
                                                            <th scope="col" class=" py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                                <div class="flex items-center gap-x-3">
                                                                    <input
                                                                        v-if="canDelete()"
                                                                        id="masterCheckbox"
                                                                        @input="toggleMasterCheckbox()"
                                                                        type="checkbox"
                                                                        :checked="allChecked"
                                                                        class="text-blue-500 border-gray-300 rounded dark:bg-gray-900 dark:ring-offset-gray-900 dark:border-gray-700"
                                                                    >
                                                                    <label for="masterCheckbox">Description</label>
                                                                </div>
                                                            </th>

                                                            <th scope="col" class="sticky top-0 py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                                <div class="flex items-center gap-x-3">
                                                                    <span>Length</span>
                                                                </div>
                                                            </th>

                                                            <th scope="col" class="sticky top-0 py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                                <div class="flex items-center gap-x-3">
                                                                    <span>Quantity</span>
                                                                </div>
                                                            </th>

                                                            <th scope="col" class="sticky top-0 py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                                <div class="flex items-center gap-x-3">
                                                                    <span>Reference</span>
                                                                </div>
                                                            </th>

                                                            <th scope="col" class="sticky top-0 py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                                <div class="flex items-center gap-x-3">
                                                                    <span>Status</span>
                                                                </div>
                                                            </th>
                                                        </tr>
                                                        </thead>
                                                        <tbody class="bg-white divide-y divide-gray-200 dark:divide-gray-700 dark:bg-gray-900">
                                                        <tr v-for="row in thisDownloadedBomData(bomData).materialListRows">
                                                            <!-- description -->
                                                            <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                                <div class="inline-flex items-center gap-x-3">
                                                                    <input
                                                                        v-if="canDelete() && !row.status"
                                                                        :id="'check'+row.id"
                                                                        type="checkbox"
                                                                        :checked="formBulkActions.selectedRawMaterialQuoteIds.includes(row.id)"
                                                                        class="text-blue-500 border-gray-300 rounded dark:bg-gray-900 dark:ring-offset-gray-900 dark:border-gray-700"
                                                                        @input="toggleCheckbox(row.id)"
                                                                    >

                                                                    <div :class="row.status ? 'ml-7': ''">
                                                                        <div>
                                                                            <label :for="'check'+row.id" class="font-medium text-gray-800 dark:text-white ">
                                                                                {{ shared.cropText(row.description) }}
                                                                            </label>
                                                                            <span class="block text-gray-500">({{ displayProductMatches(row) }})</span>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <!-- length -->
                                                            <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                                <div class="inline-flex items-center gap-x-3">
                                                                    <div class="flex items-center gap-x-2">
                                                                        <div>
                                                                            <h2 class="font-medium text-gray-800 dark:text-white ">
                                                                                <span v-if="row.nesting_algo === 'AREA'">L: </span>{{ displayLength(row) }}<span class="text-xs">{{getUnitDisplay(row,false)}}</span>
                                                                            </h2>
                                                                            <h2 v-if="row.nesting_algo === 'AREA'" class="font-medium text-gray-800 dark:text-white ">
                                                                                W: {{ parseFloat(row.width_required).toLocaleString() }}<span class="text-xs">{{getUnitDisplay(row,false)}}</span>
                                                                            </h2>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </td>

                                                            <!-- sub qty -->
                                                            <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                                <div class="inline-flex items-center gap-x-3">
                                                                    <div class="flex items-center gap-x-2">
                                                                        <div>
                                                                            <h2 class="font-medium text-gray-800 dark:text-white ">
                                                                                {{ parseFloat(row.sub_qty).toFixed(2) }}
                                                                            </h2>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </td>

                                                            <!-- Assembly ref -->
                                                            <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                                <div class="inline-flex items-center gap-x-3">
                                                                    <div class="flex items-center gap-x-2">
                                                                        <div>
                                                                            <h2 class="font-medium text-gray-800 dark:text-white italic">
                                                                                {{row.assembly_mark ? ('"'+row.assembly_mark+'"') : ''}}
                                                                            </h2>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </td>

                                                            <!-- Status -->
                                                            <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                                <div class="inline-flex items-center gap-x-3">
                                                                    <div class="flex items-center gap-x-2">
                                                                        <div>
                                                                            <h2 class="font-medium text-gray-800 dark:text-white italic">
                                                                                {{ row.status }}
                                                                            </h2>
                                                                        </div>
                                                                    </div>
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

                            <div
                                v-else class="pt-28 text-gray-600 text-lg"
                                style="height:300px"
                            >
                                Upload your first Bill of Materials above <i class="fa-regular fa-hand-point-up"></i> <i class="fa-regular fa-hand-point-up"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Teleports itself out, so it is only nested here to keep a single root -->
        <ConfirmModal
            v-if="confirmDialog"
            :title="confirmDialog.title"
            :message="confirmDialog.message"
            :confirmLabel="confirmDialog.confirmLabel"
            :tone="confirmDialog.tone"
            @confirm="confirmDialogAccepted()"
            @cancel="confirmDialogCancelled()"
        />
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
0% {
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
0% {
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
0% {
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
0% {
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
0% {
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
