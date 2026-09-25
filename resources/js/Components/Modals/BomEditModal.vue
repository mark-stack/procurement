<script setup>
    //General Imports
    import {computed, ref, shallowRef, toRefs, watch} from "vue";
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
        /**
         * The payload is fetched before this opens. Say so when that fetch failed,
         * rather than showing the same empty state as a project with no BOM yet.
         */
        loadFailed: Boolean,
    });

    /**
     * Just the data component of the payload, and only when it belongs to this project.
     * Everything below reads through here, so a payload for another project - or none
     * at all - degrades to an empty modal instead of a null dereference.
     */
    const bom = computed(() => {
        const payload = props.bomData;

        return (payload && props.project && payload.project_id === props.project.id)
            ? payload.data
            : null;
    });

    //Derived state
    const hasClarifications = computed(() => (bom.value?.partialProductMatches?.length ?? 0) > 0);
    const hasUserCustomProducts = computed(() => (bom.value?.requiresCustom?.length ?? 0) > 0);
    const hasMaterialList = computed(() => (bom.value?.materialListRows?.length ?? 0) > 0);
    const materialListRows = computed(() => bom.value?.materialListRows ?? []);
    const unimportedItems = computed(() => bom.value?.unimportedItems ?? {notRecognised: [], otherPlan: []});

    //Forms
    const formStore = useForm({
        excel: null,
    });
    /**
     * These are rebuilt from scratch on every redownload, so they are held in a ref -
     * reassigning a bare "let" leaves the template rendering the form it started with.
     */
    const formClarifications = shallowRef(buildForm(bom.value?.partialProductMatches));
    const formCustomisations = shallowRef(buildForm(bom.value?.requiresCustom));
    const formBulkActions = useForm({
        selectedRawMaterialQuoteIds: [],
    });

    //Shared data
    const warning = computed(() => usePage().props.flash?.warning);

    //Variables
    const emit = defineEmits(['redownload']);
    const isDragging = ref(false);
    const uploading = ref(false);
    const fileInput = ref(null);
    const uploadError = ref(null);
    const showClarifications = ref(hasClarifications.value);
    const showUserCustomProducts = ref(hasUserCustomProducts.value);
    const business = usePage().props.auth.business;
    const freezeView = ref(false);

    //Shared Methods
    import shared from "@/Shared/shared.js";
    const {confirmDialog, askToConfirm, confirmDialogAccepted, confirmDialogCancelled} = useConfirm();

    //Selection
    const selectableRowIds = computed(() => materialListRows.value
        .filter(row => !row.status)
        .map(row => row.id));

    //Derived from the selection itself, so ticking rows one by one keeps the header honest
    const allChecked = computed(() => selectableRowIds.value.length > 0
        && selectableRowIds.value.every(id => formBulkActions.selectedRawMaterialQuoteIds.includes(id)));

    const someChecked = computed(() => formBulkActions.selectedRawMaterialQuoteIds.length > 0 && !allChecked.value);

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

    function buildForm(rows){
        return useForm(Object.assign({}, rows ?? [], {deletedIds:[]}));
    }

    function processFile(file){
        /**
         * Match the server's rule (mimes:xlsx,xls|max:2048). Browsers report .xls under
         * several MIME types and sometimes none at all, so go by the extension - the old
         * MIME allow-list rejected valid .xls files the server would have accepted.
         */
        if(!/\.(xlsx|xls)$/i.test(file.name)){
            failUpload("That doesn't look like a spreadsheet. Please upload a .xlsx or .xls material list.");
            return;
        }

        if(file.size > 2048 * 1024){
            failUpload("That file is over 2MB. Please upload a smaller material list.");
            return;
        }

        uploadError.value = null;

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
                uploading.value = false;
                //Nothing is being recalculated any more, so stop showing that it is
                freezeView.value = false;
                uploadError.value = errors.excel ?? "The upload failed. Please try again.";
                clearFileInput();
            },
        });

        uploading.value = true;
        freezeView.value = true;
    }

    function failUpload(message){
        uploadError.value = message;
        formStore.excel = null;
        clearFileInput();
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

    function submitCustomisations(){
        let url = route("raw.material.quote.customisations");
        formCustomisations.value.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                //Hide customisations
                showUserCustomProducts.value = false;

                //Re-download
                reloadAndDownloadModal();
            },
        });
    }

    function submitClarifications(){
        let url = route("raw.material.quote.clarifications");
        formClarifications.value.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                reloadAndDownloadModal();
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
                formClarifications.value.deletedIds.push(rawMaterialQuoteId);

                //If delete all the items, then auto submit the form
                if((bom.value?.partialProductMatches?.length ?? 0) === formClarifications.value.deletedIds.length){
                    submitClarifications();
                }
            },
        });
    }

    function isDeletedCustomisation(id){
        return formCustomisations.value.deletedIds?.includes(id) ?? false;
    }

    function deleteOneCustomisation(rawMaterialQuoteId){
        askToConfirm({
            title: "Delete this item?",
            message: "It will be dropped from this project's material list when you save.",
            confirmLabel: "Delete item",
            tone: "danger",
            onConfirmed: () => {
                //Add to list of "promise to delete" to actually delete after submitting form
                formCustomisations.value.deletedIds.push(rawMaterialQuoteId);

                //If delete all the items, then auto submit the form
                if((bom.value?.requiresCustom?.length ?? 0) === formCustomisations.value.deletedIds.length){
                    submitCustomisations();
                }
            },
        });
    }

    function showTable(){
        return !hasClarifications.value && !hasUserCustomProducts.value && hasMaterialList.value;
    }

    function isDeletedClarification(id){
        return formClarifications.value.deletedIds?.includes(id) ?? false;
    }

    function submitBulkDelete(){
        let url = route("raw.material.quote.bulk.destroy");
        formBulkActions.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                formBulkActions.selectedRawMaterialQuoteIds = [];
                clearFileInput();

                //Re-download
                reloadAndDownloadModal();
            },
            onError: () => {
                uploading.value = false;
                freezeView.value = false;
                formBulkActions.selectedRawMaterialQuoteIds = [];
                clearFileInput();
            },
        });
    }

    function toggleMasterCheckbox(){
        //Uncheck all
        if(allChecked.value){
            formBulkActions.selectedRawMaterialQuoteIds = [];
        }
        //Check all
        else{
            formBulkActions.selectedRawMaterialQuoteIds = [...selectableRowIds.value];
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

    function displayLength(row){
        let displayLength = "";

        //Bundle
        if(row.nesting_algo !== "BUNDLE"){
            displayLength = parseFloat(row.length_required).toLocaleString();
        }

        return displayLength;
    }

    function displayQuantity(row){
        /**
         * Bundled items are counted, not measured, so they read as whole numbers.
         */
        const quantity = parseFloat(row.sub_qty);

        return Number.isInteger(quantity) ? quantity.toLocaleString() : quantity.toFixed(2);
    }

    function displayProductMatches(row){
        /**
         * A row with no matched product is either awaiting a custom product or sits
         * outside the current plan. Either way "user-custom" is an internal token, not
         * something to print at the user.
         */
        return row['product']
            ? row.product.product_derived_label
            : "no matching product yet";
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

    function canUpload(){
        /**
         * 1) Nesting stage only (nesting card).
         * 2) Don't show whilst clarifying or doing custom products
         */
        return props.modalCanUpload && !hasClarifications.value && !hasUserCustomProducts.value;
    }

    function canDelete(){
        /**
         * Nesting stage only (nesting card).
         */
        return props.modalCanUpload;
    }

    //Watcher
    const { refreshModalBom } = toRefs(props);
    watch(refreshModalBom, () => {
        //The redownload has landed (or failed) - either way nothing is calculating now
        freezeView.value = false;

        showClarifications.value = hasClarifications.value;
        showUserCustomProducts.value = hasUserCustomProducts.value;

        formClarifications.value = buildForm(bom.value?.partialProductMatches);
        formCustomisations.value = buildForm(bom.value?.requiresCustom);
    });
</script>

<template>
    <Modal :fakeModal="false" redirect="current" ariaLabel="Bill of Materials">
        <!-- A hard pixel width would overflow a phone, so it is only ever a ceiling -->
        <div class="w-full" :style="'max-width:'+width+'px'">

            <div class="dark:bg-gray-900 rounded-xl">
                <div class="pt-4 pb-4 mx-auto text-center">
                    <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100">
                        Bill of Materials
                    </h1>

                    <div
                        v-if="freezeView"
                        class="min-h-[300px] p-20 text-gray-700 italic"
                    >
                        <span class="block font-bold text-xl">Calculating...</span>
                        <span class="block text-lg">“Patience is bitter, but its fruit is sweet.”</span>
                    </div>
                    <div v-else class="pt-5">
                        <!-- Drag n drop  -->
                        <div
                            v-if="canUpload()"
                            class="px-5"
                        >
                            <!-- Rectangle -->
                            <div>
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

                            <!-- Upload problems: a rejected file, or what the server said -->
                            <div v-if="uploadError || warning" class="text-center text-orange-500 mt-2">
                                {{uploadError || warning}}
                            </div>
                        </div>

                        <!-- The fetch behind this modal failed -->
                        <div
                            v-if="loadFailed"
                            class="mx-5 mt-2 rounded-lg py-2 px-4 bg-red-50 text-red-700 text-left"
                        >
                            <p class="font-sans text-sm">
                                This project's material list couldn't be loaded. Please close the modal and try again.
                            </p>
                        </div>

                        <!--
                            Items the import could not use. Kept above the section switch: they
                            matter most when nothing imported at all, and that is exactly when
                            the table below is not rendered.
                        -->
                        <div
                            v-if="unimportedItems.notRecognised.length > 0"
                            class="mx-5 mt-2 rounded-lg py-2 px-4 bg-[#fff2b2] text-[#7c620c] text-left"
                        >
                            <p class="font-sans text-xs">
                                Items from your uploaded BOM's for this project that are not recognised as linear stock:
                                <br><span class="text-sm">{{unimportedItems.notRecognised.join(", ")}}</span>
                            </p>
                        </div>

                        <div
                            v-if="unimportedItems.otherPlan.length > 0"
                            class="mx-5 mt-2 rounded-lg py-2 px-4 bg-[#fff2b2] text-[#7c620c] text-left"
                        >
                            <p class="font-sans text-xs">
                                Items recognised but not covered by your current plan:
                                <br><span class="text-sm">{{unimportedItems.otherPlan.join(", ")}}</span>
                            </p>
                        </div>

                        <!-- Clarifications / User custom products / Table-->
                        <!-- Each section owns its own scrolling, so there is never a scrollbar inside a scrollbar -->
                        <div class="pl-5 pr-1">
                            <!-- Clarifications  -->
                            <section
                                v-if="showClarifications && hasClarifications"
                                class="min-h-[300px] max-h-[50vh] overflow-y-auto pl-5"
                            >
                                <h2 class="font-bold text-lg">Exact product clarifications</h2>
                                <form @submit.prevent="submitClarifications()">
                                    <template v-for="(item,index) in formClarifications" :key="'clarification-'+index">
                                        <div v-if="isNumeric(index) && !isDeletedClarification(item.data.id)" class="mt-5">
                                            <p class="italic font-bold text-left">"{{item.data.description}}" <span class="text-red-500 ml-2" style="cursor: pointer;" @click="deleteOneClarification(item.data.id)"><i class="fa-solid fa-xmark"></i></span></p>
                                            <div class="grid grid-cols-1 sm:grid-cols-3 text-left">
                                                <div v-for="(option,option_index) in item.options" :key="'option-'+index+'-'+option_index">
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
                                v-else-if="showUserCustomProducts && hasUserCustomProducts"
                                class="min-h-[300px] max-h-[50vh] overflow-y-auto"
                            >
                                <h2 class="font-bold text-lg">Custom products (add to price book)</h2>
                                <p class="mb-3 text-gray-600">
                                    This action is just required once. It will be added to the price book for you and other members in your company.
                                </p>

                                <form @submit.prevent="submitCustomisations()">
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                        <template v-for="(item,index) in formCustomisations" :key="'custom-product-form-'+index">
                                            <CustomProductForm
                                                v-if="isNumeric(index) && !isDeletedCustomisation(item.data.id)"
                                                class="mt-3 text-left"
                                                :item="item"
                                                :index="index"
                                                :form="formCustomisations"
                                                :allMeasurements="bom.allMeasurements"
                                                :formDependentData="bom.formDependentData"
                                                :allGrades="bom.allGrades"
                                                :nestingGroups="bom.nestingGroups"
                                                @deleteOneCustomisation="id => deleteOneCustomisation(id)"
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
                                class="min-h-[300px] text-left"
                            >
                                <button
                                    v-if="canDelete()"
                                    :disabled="formBulkActions.selectedRawMaterialQuoteIds.length === 0"
                                    @click="submitBulkDelete()"
                                    :class="formBulkActions.selectedRawMaterialQuoteIds.length === 0 ? 'text-gray-500' : ''"
                                    class="text-sm bg-red-200 px-2 py-1 rounded"
                                >
                                    Delete Selected ({{formBulkActions.selectedRawMaterialQuoteIds.length}})
                                </button>

                                <div class="flex flex-col">
                                    <div class="inline-block min-w-full py-2 align-middle">
                                        <div class="overflow-hidden border border-gray-200 dark:border-gray-700 md:rounded-lg">
                                            <div class="relative overflow-auto max-h-[45vh]">
                                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-left">
                                                    <thead class="sticky top-0 bg-gray-50 dark:bg-gray-800">
                                                    <tr>
                                                        <th scope="col" class=" py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                            <div class="flex items-center gap-x-3">
                                                                <input
                                                                    v-if="canDelete()"
                                                                    id="masterCheckbox"
                                                                    @change="toggleMasterCheckbox()"
                                                                    type="checkbox"
                                                                    :checked="allChecked"
                                                                    :indeterminate="someChecked"
                                                                    aria-label="Select all deletable rows"
                                                                    class="text-blue-500 border-gray-300 rounded dark:bg-gray-900 dark:ring-offset-gray-900 dark:border-gray-700"
                                                                >
                                                                <span>Description</span>
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
                                                    <tr v-for="row in materialListRows" :key="row.id">
                                                        <!-- description -->
                                                        <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                            <div class="inline-flex items-center gap-x-3">
                                                                <input
                                                                    v-if="canDelete() && !row.status"
                                                                    :id="'check'+row.id"
                                                                    type="checkbox"
                                                                    :checked="formBulkActions.selectedRawMaterialQuoteIds.includes(row.id)"
                                                                    class="text-blue-500 border-gray-300 rounded dark:bg-gray-900 dark:ring-offset-gray-900 dark:border-gray-700"
                                                                    @change="toggleCheckbox(row.id)"
                                                                >

                                                                <div :class="row.status ? 'ml-7': ''">
                                                                    <div>
                                                                        <!-- Cropped for the column, so the whole description stays available on hover -->
                                                                        <label
                                                                            v-if="canDelete() && !row.status"
                                                                            :for="'check'+row.id"
                                                                            :title="row.description"
                                                                            class="font-medium text-gray-800 dark:text-white"
                                                                        >
                                                                            {{ shared.cropText(row.description) }}
                                                                        </label>
                                                                        <span
                                                                            v-else
                                                                            :title="row.description"
                                                                            class="font-medium text-gray-800 dark:text-white"
                                                                        >
                                                                            {{ shared.cropText(row.description) }}
                                                                        </span>
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
                                                                            {{ displayQuantity(row) }}
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
                            </section>

                            <!-- Nothing imported yet -->
                            <div
                                v-else-if="!loadFailed"
                                class="min-h-[200px] pt-20 text-gray-600 text-lg"
                            >
                                <template v-if="canUpload()">
                                    Upload your first Bill of Materials above <i class="fa-regular fa-hand-point-up"></i> <i class="fa-regular fa-hand-point-up"></i>
                                </template>
                                <template v-else>
                                    There are no materials on this project yet.
                                </template>
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
