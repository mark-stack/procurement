<script setup>
    //General Imports
    import {useForm, usePage} from "@inertiajs/vue3";

    //Component Imports
    import Modal from "@/Layouts/Modal.vue";
    import ConfirmModal from "@/Components/Modals/ConfirmModal.vue";
    import useConfirm from "@/Shared/useConfirm.js";
    import {computed, ref, toRefs, watch} from "vue";

    //Props
    const props = defineProps({
        width: String,
        editProject: Object,
        bomData: Object,
        refreshNewProject: Boolean,
        projectAfterUpload: Object,
        /**
         * The parent keeps this mounted with v-show, so the component has to be told
         * when it is reopened - otherwise the previous attempt's name, files, errors
         * and loading state are still sitting there.
         */
        show: Boolean,
    });

    /**
     * Limits. Kept in step with App\Http\Requests\StoreProjectRequest, which is
     * where they are actually enforced.
     */
    const MAX_FILES = 5;
    const MAX_FILE_BYTES = 1024 * 1024;

    //Forms
    const formProjectCreate = useForm({
        name: "",
        reference: null,
        date_materials_required: null,
        tentative: false,
        excel: [],
    });
    let formClarifications = thisDownloadedBomData(props.bomData)
        ? (useForm(Object.assign({}, thisDownloadedBomData(props.bomData).partialProductMatches, {deletedIds:[]})))
        : null;
    let formCustomisations = thisDownloadedBomData(props.bomData)
        ? (useForm(Object.assign({}, thisDownloadedBomData(props.bomData).requiresCustom, {deletedIds:[]})))
        : null;

    //Shared data
    const projectFlashed = computed(() => usePage().props.flash?.project);
    const flashedWarning = computed(() => usePage().props.flash?.warning);
    const supportEmail = computed(() => usePage().props.adminEmail);

    //Variables
    const emit = defineEmits(['closeModal','closeModalOnSuccess','redownload']);
    const showClarifications = ref(hasClarifications());
    const showUserCustomProducts = ref(hasUserCustomProducts());
    const business = usePage().props.auth.business;
    const freezeView = ref(false);
    const fileInput = ref(null);
    //A flash survives in the page props, so it has to be dismissable by hand
    const warningDismissed = ref(false);

    //Shared Methods
    const {confirmDialog, askToConfirm, confirmDialogAccepted, confirmDialogCancelled} = useConfirm();

    //Computed
    const warning = computed(() => (warningDismissed.value ? null : flashedWarning.value));

    const oversizedFiles = computed(
        () => formProjectCreate.excel.filter(file => file.size > MAX_FILE_BYTES)
    );

    const tooManyFiles = computed(() => formProjectCreate.excel.length > MAX_FILES);

    /**
     * Every per-file rule reports under its own key ("excel.0"), which the single
     * errors.excel lookup used to swallow.
     */
    const excelErrors = computed(() => Object.entries(formProjectCreate.errors)
        .filter(([key]) => key === 'excel' || key.startsWith('excel.'))
        .map(([, message]) => message));

    const projectName = computed(() => (formProjectCreate.name ?? "").trim());

    /**
     * Was a manually maintained ref that three call sites had to remember to
     * refresh, and that never saw a change to processing or freezeView at all.
     */
    const saveButtonDisabled = computed(() =>
        formProjectCreate.processing
        || freezeView.value
        || formProjectCreate.excel.length === 0
        || projectName.value === ""
        || tooManyFiles.value
        || oversizedFiles.value.length > 0
    );

    /**
     * A disabled button with no explanation is just a dead end.
     */
    const disabledReason = computed(() => {
        if(formProjectCreate.processing || freezeView.value){
            return null;
        }
        if(projectName.value === ""){
            return "Enter a project name to continue.";
        }
        if(formProjectCreate.excel.length === 0){
            return "Attach at least one Excel material list to continue.";
        }
        if(tooManyFiles.value){
            return `Remove ${formProjectCreate.excel.length - MAX_FILES} file(s) - a maximum of ${MAX_FILES} can be uploaded at once.`;
        }
        if(oversizedFiles.value.length > 0){
            return "Remove the file(s) over the 1Mb limit to continue.";
        }

        return null;
    });

    //Shared Methods
    //

    //Methods
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

    function isDeletedClarification(id){
        let isDeleted = false;

        if(formClarifications.deletedIds !== undefined){
            isDeleted = formClarifications.deletedIds.includes(id);
        }

        return isDeleted;
    }

    function submit(){
        //A new attempt: don't keep showing the last one's warning
        warningDismissed.value = true;

        //Edit mode
        if(props.editProject){
            let url = route("projects.update",props.editProject.id);
            formProjectCreate.put(url, {
                preserveScroll: true,
                onSuccess: () => {
                    //Close modal
                    freezeView.value = false;
                    emit('closeModalOnSuccess');
                },
            });
        }
        /**
         Create mode:
         At this stage there's no project object created.
         This will create the project, extract BOM, then return Project model.
         */
        else{
            //Freeze
            freezeView.value = true;

            let url = route("projects.store");

            formProjectCreate.post(url, {
                preserveScroll: true,
                onSuccess: () => {
                    //A fresh warning from this attempt should be visible
                    warningDismissed.value = false;

                    formProjectCreate.reset();

                    //Excel upload was valid and successful
                    if(projectFlashed.value){
                        //Download BOM data
                        reloadAndDownloadModal(projectFlashed.value);
                    }
                    //Excel was invalid (prompt to get support help)
                    else{
                        //Hide loader
                        freezeView.value = false;
                    }
                },
                onError: () => {
                    //Hide loader
                    freezeView.value = false;

                    //Clear attached files
                    formProjectCreate.excel = [];
                },
            });
        }
    }

    function thisDownloadedBomData(bomData){
        /**
            Get just the data component from payload
         */
        let data = null;

        if(bomData && props.projectAfterUpload){
            if(bomData.project_id === props.projectAfterUpload.id){
                data = bomData.data;
            }
        }

        return data;
    }

    function editMode(){
        //Populate form
        formProjectCreate.name = props.editProject.name;
        formProjectCreate.date_materials_required = props.editProject.date_materials_required;
        formProjectCreate.reference = props.editProject.reference;
        formProjectCreate.tentative = props.editProject.tentative;
    }

    /**
     * Reopening the modal has to start from a clean slate. The editProject watcher
     * only fires when that prop actually changes, so a second "add project" (null
     * to null) left the last attempt's state on screen.
     */
    function resetToInitialState(){
        formProjectCreate.reset();
        formProjectCreate.clearErrors();
        formProjectCreate.excel = [];

        //A close mid-upload used to leave this stuck on the "sip of coffee" screen
        freezeView.value = false;
        warningDismissed.value = true;

        if(props.editProject){
            editMode();
        }
    }

    function onClose(){
        //Don't come back to a frozen modal
        freezeView.value = false;

        emit('closeModal');
    }

    function removeFile(fileName){
        formProjectCreate.excel = formProjectCreate.excel.filter(file => file.name !== fileName);
    }

    function addFiles(files){
        //Add files where unique names
        Object.values(files).forEach(file => {
            //Unique items added
            let exists = formProjectCreate.excel.find(uploadedFile => uploadedFile.name === file.name);
            if(!exists){
                //Add to list (might exceed limit, but user can remove)
                formProjectCreate.excel.push(file);
            }
        });

        //Clear cache
        if(fileInput.value){
            fileInput.value.value = "";
        }
    }

    function reloadAndDownloadModal(projectFlashed){
        /**
         * Download BOM data like partialProductMatches, nesting, quotes, etc
         */

        freezeView.value = true;

        emit("redownload",projectFlashed);
    }

    function isNumeric(value) {
        return !isNaN(value) && !isNaN(parseFloat(value));
    }

    function submitClarifications(){
        //Freeze
        freezeView.value = true;

        let url = route("raw.material.quote.clarifications",business.id);
        formClarifications.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                reloadAndDownloadModal(props.projectAfterUpload);
            },
            onError: () => {
                freezeView.value = false;
            },
        });
    }

    function isAdd(){
        return !editProject.value && !hasClarifications();
    }

    function isEdit(){
        return editProject.value && !hasClarifications();
    }

    function isClarify(){
        return showClarifications.value && hasClarifications();
    }

    function isCustomProducts(){
        return showUserCustomProducts.value && hasUserCustomProducts();
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

    /*
    Watchers
     */
    const { editProject } = toRefs(props);
    watch(editProject, (newVal) => {
        //Edit mode
        if(newVal){
            editMode();
        }
        //New mode
        else{
            formProjectCreate.reset();
            formProjectCreate.clearErrors();
        }
    });

    const { show } = toRefs(props);
    watch(show, (isOpen) => {
        if(isOpen){
            resetToInitialState();
        }
    });

    const { refreshNewProject } = toRefs(props);
    watch(refreshNewProject, () => {
        showClarifications.value = hasClarifications();
        showUserCustomProducts.value = hasUserCustomProducts();

        if(thisDownloadedBomData(props.bomData)){
            /*
              Clarifications
             */
            let partialProductMatches = thisDownloadedBomData(props.bomData).partialProductMatches;
            formClarifications = useForm(Object.assign({}, partialProductMatches, {deletedIds:[]}));
            if(partialProductMatches.length === 0){
               //Close if no clarifications
               freezeView.value = false;
               emit("closeModalOnSuccess")
            }
            else{
                freezeView.value = false;
            }

            /*
              Custom
             */
            let requiresCustom = thisDownloadedBomData(props.bomData).requiresCustom;
            formCustomisations = useForm(Object.assign({}, requiresCustom, {deletedIds:[]}));
            if(requiresCustom.length > 0){
                freezeView.value = false;
            }
        }
    });
</script>

<template>
    <Modal
        :fakeModal="false"
        redirect="current"
        :open="show"
        ariaLabel="Add new project"
        @closeModal="onClose"
    >
        <!-- Fits the viewport on a phone instead of overflowing a fixed 550px -->
        <div :style="{ width: 'min(' + width + 'px, calc(100vw - 2rem))' }">

            <div class="dark:bg-gray-900 rounded-xl">
                <!-- Loading -->
                <div
                    v-if="freezeView"
                    class="flex items-center justify-center text-center text-xl dark:text-gray-100"
                    style="height:300px"
                    role="status"
                    aria-live="polite"
                >
                    Processing: Have a sip of coffee ☕️
                </div>

                <!-- Add project -->
                <div
                    v-else-if="isAdd()"

                    class="px-6 pt-4 pb-4 mx-auto text-center"
                >
                    <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100">
                        Add New Project
                    </h1>

                    <div class="pb-2">
                        <form @submit.prevent="submit()" class="text-left">
                            <div class="grid grid-cols-1 gap-6 mt-4">
                                <!-- Name -->
                                <div>
                                    <label for="new-project-name" class="text-gray-700 dark:text-gray-200 ml-1">Project Name *</label>
                                    <input
                                        id="new-project-name"
                                        v-model="formProjectCreate.name"
                                        type="text"
                                        class="w-full px-4 py-2 text-gray-700 bg-white border rounded-md dark:bg-gray-900 dark:text-gray-300 dark:border-gray-600 focus:border-blue-400 dark:focus:border-blue-300 focus:outline-none focus:ring focus:ring-blue-300 focus:ring-opacity-40"
                                        placeholder="Name"
                                        required
                                        :disabled="formProjectCreate.processing || freezeView"
                                    >
                                    <div v-if="formProjectCreate.errors.name" class="text-sm text-red-500">{{ formProjectCreate.errors.name }}</div>
                                </div>

                                <!-- Project reference -->
                                <!--                                <div>-->
                                <!--                                    <label class="text-gray-700 dark:text-gray-200 ml-1">Project reference</label>-->
                                <!--                                    <input-->
                                <!--                                        v-model="formProjectCreate.reference"-->
                                <!--                                        type="text"-->
                                <!--                                        class="w-full px-4 py-2 text-gray-700 bg-white border rounded-md dark:bg-gray-900 dark:text-gray-300 dark:border-gray-600 focus:border-blue-400 dark:focus:border-blue-300 focus:outline-none focus:ring focus:ring-blue-300 focus:ring-opacity-40"-->
                                <!--                                        placeholder="Reference ID"-->
                                <!--                                        required-->
                                <!--                                    >-->
                                <!--                                    <div v-if="formProjectCreate.errors.reference" class="text-sm text-red-500">{{ formProjectCreate.errors.reference }}</div>-->
                                <!--                                </div>-->

                                <!-- Date materials required -->
                                <!--                                <div>-->
                                <!--                                    <label class="text-gray-700 dark:text-gray-200 ml-1">Materials required by</label>-->
                                <!--                                    <input-->
                                <!--                                        v-model="formProjectCreate.date_materials_required"-->
                                <!--                                        type="date"-->
                                <!--                                        class="w-full px-4 py-2 text-gray-700 bg-white border rounded-md dark:bg-gray-900 dark:text-gray-300 dark:border-gray-600 focus:border-blue-400 dark:focus:border-blue-300 focus:outline-none focus:ring focus:ring-blue-300 focus:ring-opacity-40"-->
                                <!--                                        required-->
                                <!--                                    >-->
                                <!--                                    <div v-if="formProjectCreate.errors.date_materials_required" class="text-sm text-red-500">{{ formProjectCreate.errors.date_materials_required }}</div>-->
                                <!--                                </div>-->

                                <!-- BOM upload -->
                                <div class="overflow-y-auto" style="max-height:200px">
                                    <!-- invalid template (ref to admin support)-->
                                    <template v-if="formProjectCreate.errors.invalid_template">
                                        <div class="text-orange-700 dark:text-orange-300 text-sm border-2 border-orange-300 rounded-2xl p-3">
                                            <!-- one file -->
                                            <div v-if="Object.keys(formProjectCreate.errors.invalid_template).length === 1">
                                                <b><i>"{{formProjectCreate.errors.invalid_template[0]}}"</i></b> didn't auto-detect properly. Did this template change?
                                                Please email the file to <a :href="'mailto:'+supportEmail" class="text-orange-700 dark:text-orange-300 font-semibold underline">{{ supportEmail }}</a> to have it re-calibrated quickly.
                                            </div>
                                            <!-- multiple files -->
                                            <div v-else-if="Object.keys(formProjectCreate.errors.invalid_template).length > 1">
                                                The following files didn't auto-detect properly. Did the templates change?
                                                Please email them to <a :href="'mailto:'+supportEmail" class="text-orange-700 dark:text-orange-300 font-semibold underline">{{ supportEmail }}</a> to have them re-calibrated quickly.
                                                <br>
                                                <ul>
                                                    <li v-for="file in formProjectCreate.errors.invalid_template" :key="file">
                                                        - {{file}}
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            class="block mx-auto text-center font-semibold underline mt-2 text-sm dark:text-gray-200"
                                            @click="formProjectCreate.clearErrors('invalid_template')"
                                        >
                                            Ok, got it
                                        </button>
                                    </template>


                                    <template v-if="!formProjectCreate.errors.invalid_template">
                                        <!--
                                            sr-only rather than hidden: display:none takes the input out of
                                            the tab order, which left keyboard and screen reader users with
                                            no way at all to attach a file.
                                        -->
                                        <label
                                            class="inline-block text-blue-700 dark:text-blue-400 font-semibold hover:text-blue-900 dark:hover:text-blue-300 cursor-pointer rounded focus-within:outline-none focus-within:ring-2 focus-within:ring-blue-400 focus-within:ring-offset-2"
                                        >
                                            + add Excel Material Lists
                                            <input
                                                ref="fileInput"
                                                type="file"
                                                class="sr-only"
                                                multiple
                                                accept=".xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                                @input="addFiles($event.target.files)"
                                                :disabled="formProjectCreate.processing || freezeView"
                                                @click="formProjectCreate.clearErrors('invalid_template')"
                                            />
                                        </label>
                                        <div
                                            v-for="(message,index) in excelErrors"
                                            :key="'excel-error-'+index"
                                            class="text-sm text-red-500 mt-2"
                                        >
                                            {{ message }}
                                        </div>
                                        <div
                                            v-if="tooManyFiles"
                                            class="text-sm text-red-500 mt-2 font-semibold"
                                        >
                                            Maximum {{ MAX_FILES }} BOM files can be uploaded. Click the "X" to remove.
                                        </div>
                                        <!-- nothing extracted / template didn't auto-detect -->
                                        <div
                                            v-if="warning"
                                            class="text-sm text-orange-500 mt-2"
                                        >
                                            {{warning}}
                                        </div>
                                    </template>



                                    <div
                                        v-if="formProjectCreate.excel.length > 0"
                                        class="pl-1 pt-3 pb-3 font-semibold"
                                    >
                                        <div
                                            v-for="(file,index) in formProjectCreate.excel"
                                            class="grid grid-cols-5 p-2 items-center"
                                            :class="index > 0 ? 'border-t-[1px] border-gray-300 dark:border-gray-700' : ''"
                                            :key="file.name"
                                        >
                                            <p
                                                :class="file.size > MAX_FILE_BYTES ? 'text-red-500' : 'dark:text-gray-200'"
                                                class="col-span-4 break-all"
                                            >
                                                {{file.name}} <span class="block text-xs" v-if="file.size > MAX_FILE_BYTES">(Exceeds 1Mb limit - please remove)</span>
                                            </p>
                                            <button
                                                type="button"
                                                class="text-right rounded focus:outline-none focus:ring-2 focus:ring-red-400"
                                                :aria-label="'Remove ' + file.name"
                                                :disabled="formProjectCreate.processing || freezeView"
                                                @click="removeFile(file.name)"
                                            >
                                                <i class="fa-solid fa-xmark text-red-500"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- submit button -->
                                <div v-if="!formProjectCreate.errors.invalid_template">
                                    <button
                                        type="submit"
                                        :disabled="saveButtonDisabled"
                                        style="height:40px"
                                        :class="saveButtonDisabled ? 'bg-blue-300 cursor-not-allowed' : 'bg-blue-700 hover:bg-blue-600 focus:outline-none focus:bg-blue-600'"
                                        class="w-full px-4 py-2 text-sm font-medium tracking-wide text-white capitalize transition-colors duration-300 transform rounded-md "
                                    >
                                        <span v-if="editProject">Updat{{(formProjectCreate.processing || freezeView) ? 'ing...' : 'e'}}</span>
                                        <span v-else>{{(formProjectCreate.processing || freezeView) ? 'Extracting...' : 'Extract materials'}}</span>
                                    </button>
                                    <!-- Say why the button is dead rather than leaving the user guessing -->
                                    <p
                                        v-if="disabledReason"
                                        class="text-sm text-gray-500 dark:text-gray-400 mt-2 text-center"
                                    >
                                        {{ disabledReason }}
                                    </p>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Edit project -->
                <div
                    v-else-if="isEdit()"
                    style="height:380px"
                    class="px-6 pt-4 pb-4 mx-auto text-center"
                >
                    <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100">
                        Edit {{formProjectCreate.name}}
                    </h1>

                    <div class="pb-2">
                        <form @submit.prevent="submit()" class="text-left">
                            <div class="grid grid-cols-1 gap-6 mt-4">
                                <!-- Name -->
                                <div>
                                    <label for="edit-project-name" class="text-gray-700 dark:text-gray-200 ml-1">Project Name *</label>
                                    <input
                                        id="edit-project-name"
                                        v-model="formProjectCreate.name"
                                        type="text"
                                        class="w-full px-4 py-2 text-gray-700 bg-white border rounded-md dark:bg-gray-900 dark:text-gray-300 dark:border-gray-600 focus:border-blue-400 dark:focus:border-blue-300 focus:outline-none focus:ring focus:ring-blue-300 focus:ring-opacity-40"
                                        placeholder="Name"
                                        required
                                    >
                                    <div v-if="formProjectCreate.errors.name" class="text-sm text-red-500">{{ formProjectCreate.errors.name }}</div>
                                </div>

                                <!--
                                    Every other field is posted back without being shown, so a
                                    rejection on one of those used to fail completely silently.
                                -->
                                <div
                                    v-for="(message,key) in formProjectCreate.errors"
                                    :key="'edit-error-'+key"
                                >
                                    <p v-if="key !== 'name'" class="text-sm text-red-500">{{ message }}</p>
                                </div>

                                <!-- submit button -->
                                <div>
                                    <button
                                        type="submit"
                                        :disabled="(formProjectCreate.processing || freezeView)"
                                        style="height:40px"
                                        class="w-full px-4 py-2 text-sm font-medium tracking-wide text-white capitalize transition-colors duration-300 transform bg-blue-700 rounded-md hover:bg-blue-600 focus:outline-none focus:bg-blue-600"
                                    >
                                        <span v-if="editProject">Updat{{(formProjectCreate.processing || freezeView) ? 'ing...' : 'e'}}</span>
                                        <span v-else>{{(formProjectCreate.processing || freezeView)? 'Adding...' : 'Add Project'}}</span>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>

                </div>


                <!-- Clarifications  -->
                <section
                    v-else-if="isClarify()"
                    style="height:380px"
                    class="pl-5 overflow-y-auto dark:text-gray-100"
                >
                    <h2 class="font-bold text-lg">Clarify {{ thisDownloadedBomData(bomData).project.name }} materials</h2>
                    <form @submit.prevent="submitClarifications()">
                        <template v-for="(item,index) in formClarifications" :key="'clarification-'+index">
                            <div v-if="isNumeric(index) && !isDeletedClarification(item.data.id)" class="mt-5">
                                <p class="italic font-bold text-left">
                                    "{{item.data.description}}"
                                    <button
                                        type="button"
                                        class="text-red-500 ml-2 rounded focus:outline-none focus:ring-2 focus:ring-red-400"
                                        :aria-label="'Remove ' + item.data.description"
                                        @click="deleteOneClarification(item.data.id)"
                                    >
                                        <i class="fa-solid fa-xmark"></i>
                                    </button>
                                </p>
                                <div class="grid grid-cols-2 text-left">
                                    <div v-for="(option,option_index) in item.options" :key="'option-'+option_index">
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

                        <div class="flex justify-center p-6">
                            <button
                                type="submit"
                                class="bg-blue-500 rounded px-4 py-2 text-white"
                                :disabled="(formProjectCreate.processing || freezeView)"
                            >
                                {{(formProjectCreate.processing || freezeView) ? 'Saving..' : 'Save clarifications'}}
                            </button>
                        </div>

                    </form>
                </section>

                <!-- Custom products -->
                <section
                    v-else-if="isCustomProducts()"
                    style="height:380px"
                    class="px-6 py-4 dark:text-gray-100"
                >
                    <h2 class="font-bold text-lg">Custom products</h2>
                    <p class="mb-3 text-gray-600 dark:text-gray-400">
                        Some materials need to be added to your price book by hand. We'll be in touch at
                        <a :href="'mailto:'+supportEmail" class="font-semibold underline">{{ supportEmail }}</a>
                        to get these set up.
                    </p>

                    <!--                        <h2 class="font-bold text-lg">Custom products (add to price book)</h2>-->
                    <!--                        <p class="mb-3 text-gray-600">-->
                    <!--                            This action is just required once. It will be added to the price book for you and other members in your company.-->
                    <!--                        </p>-->

                    <!--                        <form @submit.prevent="submitCustomisations()">-->
                    <!--                            <div class="grid grid-cols-3 gap-6">-->
                    <!--                                <template v-for="(item,index) in formCustomisations">-->
                    <!--                                    <CustomProductForm-->
                    <!--                                        v-if="isNumeric(index) && !isDeletedCustomisation(item.data.id)"-->
                    <!--                                        class="mt-3 text-left"-->
                    <!--                                        :item="item"-->
                    <!--                                        :index="index"-->
                    <!--                                        :form="formCustomisations"-->
                    <!--                                        :allMeasurements="thisDownloadedBomData(bomData).allMeasurements"-->
                    <!--                                        :formDependentData="thisDownloadedBomData(bomData).formDependentData"-->
                    <!--                                        :allGrades="thisDownloadedBomData(bomData).allGrades"-->
                    <!--                                        :nestingGroups="thisDownloadedBomData(bomData).nestingGroups"-->
                    <!--                                        @deleteOneCustomisation="id => deleteOneCustomisation(id)"-->
                    <!--                                        :key="'custom-product-form-'+index"-->
                    <!--                                    />-->
                    <!--                                </template>-->
                    <!--                            </div>-->

                    <!--                            <button-->
                    <!--                                type="submit"-->
                    <!--                                class="bg-green-500 rounded px-2 py-1"-->
                    <!--                                :disabled="formCustomisations.processing"-->
                    <!--                            >-->
                    <!--                                {{ formCustomisations.processing ? 'Saving...' : 'Save all' }}-->
                    <!--                            </button>-->
                    <!--                        </form>-->
                </section>
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
