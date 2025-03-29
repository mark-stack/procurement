<script setup>
    //General Imports
    import {useForm, usePage} from "@inertiajs/vue3";

    //Component Imports
    import Modal from "@/Layouts/Modal.vue";
    import {computed, ref, toRefs, watch} from "vue";

    //Props
    const props = defineProps({
        width: String,
        editProject: Object,
        bomData: Object,
        refreshNewProject: Boolean,
        projectAfterUpload: Object,
    });

    //Forms
    const formProjectCreate = useForm({
        name: null,
        reference: null,
        date_materials_required: null,
        tentative: false,
        excel: [],
    });
    const projectAfterUploadRef = ref(props.projectAfterUpload?.id);
    let formClarifications = thisDownloadedBomData(props.bomData)
        ? (useForm(Object.assign({}, thisDownloadedBomData(props.bomData).partialProductMatches, {deletedIds:[]})))
        : null;
    let formCustomisations = thisDownloadedBomData(props.bomData)
        ? (useForm(Object.assign({}, thisDownloadedBomData(props.bomData).requiresCustom, {deletedIds:[]})))
        : null;

    //Shared data
    const projectFlashed = computed(() => usePage().props.flash.project);

    //Variables
    const emit = defineEmits(['closeModalOnSuccess','redownload']);
    const showClarifications = ref(hasClarifications());
    const showUserCustomProducts = ref(hasUserCustomProducts());
    const business = usePage().props.auth.business;
    const freezeView = ref(false);


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
        //Edit mode
        if(props.editProject){
            let url = route("projects.update",props.editProject.id);
            formProjectCreate.put(url, {
                preserveScroll: true,
                onSuccess: () => {
                    formProjectCreate.reset();

                    //Close modal
                    freezeView.value = false;
                    emit('closeModalOnSuccess');
                },
                onError: errors => {
                    console.log('errors',errors);
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
                    formProjectCreate.reset();

                    //Download BOM data
                    reloadAndDownloadModal(projectFlashed.value);
                },
                onError: errors => {
                    console.log('errors',errors);
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

    function backToNewProject(){
        //Clear the form
        formProjectCreate.reset();

        //todo cancel edit
    }

    function removeFile(fileName){
        let newFilesList = [];
        Object.values(formProjectCreate.excel).forEach(file => {
            if(file.name !== fileName){
                newFilesList.push(file);
            }
        });

        formProjectCreate.excel = newFilesList;
    }

    function addFiles(files){
        //Add files where unique names
        Object.values(files).forEach(file => {
            let exists = formProjectCreate.excel.find(uploadedFile => uploadedFile.name === file.name);
            if(!exists){
                formProjectCreate.excel.push(file);
            }
        });
    }

    function reloadAndDownloadModal(projectFlashed){
        /**
         * Download BOM data like partialProductMatches, nesting, quotes, etc
         */

        freezeView.value = true;

        console.log("project flashed",projectFlashed);

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
                console.log("success response after 'submitClarifications'");
                reloadAndDownloadModal(props.projectAfterUpload);
            },
            onError: errors => {
                console.log('errors',errors);
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
        }
    });

    const { refreshNewProject } = toRefs(props);
    watch(refreshNewProject, (newVal) => {
        //freezeView.value = false;

        showClarifications.value = hasClarifications();
        showUserCustomProducts.value = hasUserCustomProducts();
        projectAfterUploadRef.value = props.projectAfterUpload;

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
            if(formCustomisations > 0){
                freezeView.value = false;
            }
        }
    });
</script>

<template>
    <Modal :fakeModal="false" redirect="current">
        <div :style="'width:'+width+'px'">

            <div class="dark:bg-gray-900 rounded-xl">
                <!-- Loading -->
                <div
                    v-if="freezeView"
                    class="flex items-center justify-center text-center italic text-lg"
                    style="height:300px"
                >
                    Loading: Take a 10 second meditation
                </div>

                <!-- Add project -->
                <div
                    v-else-if="isAdd()"
                    style="height:300px"
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
                                    <label class="text-gray-700 dark:text-gray-200 ml-1">Project Name</label>
                                    <input
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
                                <div>
                                    <label class="text-gray-700 dark:text-gray-200 ml-1">Upload 1 or more BOM Excel files</label>
                                    <br>
                                    <div
                                        v-if="formProjectCreate.excel.length > 0"
                                        class="pl-1 pt-3 pb-3 font-semibold"
                                    >
                                        <div
                                            v-for="(file,index) in formProjectCreate.excel"
                                            class="grid grid-cols-5"
                                            :key="index"
                                        >
                                            <p class="col-span-4">
                                                {{file.name}}
                                            </p>
                                            <p
                                                class="text-right"
                                                @click="removeFile(file.name)"
                                                :style="(formProjectCreate.processing || freezeView) ? 'pointer-events: none;' : ''"
                                            >
                                                <i class="fa-solid fa-xmark text-red-500"></i>
                                            </p>
                                        </div>
                                    </div>
                                    <label class="text-blue-700 font-semibold hover:text-blue-900">
                                        + upload BOM
                                        <input
                                            type="file"
                                            class="hidden"
                                            multiple
                                            accept=".xls,.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                            @input="addFiles($event.target.files)"
                                            style="cursor: pointer;"
                                            :disabled="formProjectCreate.processing || freezeView"
                                        />
                                    </label>
                                    <div
                                        v-if="formProjectCreate.errors.excel"
                                        class="text-sm text-red-500 mt-2"
                                    >
                                        {{ formProjectCreate.errors.excel }}
                                    </div>
                                </div>
                                <!-- submit button -->
                                <div>
                                    <button
                                        type="submit"
                                        :disabled="formProjectCreate.processing || freezeView"
                                        style="height:40px"
                                        class="w-full px-4 py-2 text-sm font-medium tracking-wide text-white capitalize transition-colors duration-300 transform bg-blue-700 rounded-md hover:bg-blue-600 focus:outline-none focus:bg-blue-600"
                                    >
                                        <span v-if="editProject">Updat{{(formProjectCreate.processing || freezeView) ? 'ing...' : 'e'}}</span>
                                        <span v-else>{{(formProjectCreate.processing || freezeView) ? 'Extracting...' : 'Extract materials'}}</span>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Edit project -->
                <div
                    v-else-if="isEdit()"
                    style="height:300px"
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
                                    <label class="text-gray-700 dark:text-gray-200 ml-1">Project Name</label>
                                    <input
                                        v-model="formProjectCreate.name"
                                        type="text"
                                        class="w-full px-4 py-2 text-gray-700 bg-white border rounded-md dark:bg-gray-900 dark:text-gray-300 dark:border-gray-600 focus:border-blue-400 dark:focus:border-blue-300 focus:outline-none focus:ring focus:ring-blue-300 focus:ring-opacity-40"
                                        placeholder="Name"
                                        required
                                    >
                                    <div v-if="formProjectCreate.errors.name" class="text-sm text-red-500">{{ formProjectCreate.errors.name }}</div>
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
                    style="height:300px"
                    class="pl-5 overflow-y-auto"
                >
                    <h2 class="font-bold text-lg">Clarify {{ thisDownloadedBomData(bomData).project.name }} materials</h2>
                    <form @submit.prevent="submitClarifications()">
                        <template v-for="(item,index) in formClarifications">
                            <div v-if="isNumeric(index) && !isDeletedClarification(item.data.id)" class="mt-5">
                                <p class="italic font-bold text-left">"{{item.data.description}}" <span class="text-red-500 ml-2" style="cursor: pointer;" @click="deleteOneClarification(item.data.id)"><i class="fa-solid fa-xmark"></i></span></p>
                                <div class="grid grid-cols-2 text-left">
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
                    style="height:400px"
                >
                    [customisation]
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
    </Modal>
</template>
