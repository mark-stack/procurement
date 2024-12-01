<script setup>
    //General Imports
    import {useForm} from "@inertiajs/vue3";
    import {onMounted, ref} from "vue";


    //Component Imports
    import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";

    //Props
    const props = defineProps({
        project: Object,
        materialListRows: Object,
    });

    //Form
    const formStore = useForm({
        csv: null,
    });
    const formBulkActions = useForm({
        selectedRawMaterialQuoteIds: [], //initialMapBulkActions(),
    });

    //Variables
    const isDragging = ref(false);
    const uploading = ref(false);
    const fileInput = ref(null);
    const allChecked = ref(false);

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
    });

    function getUnitDisplay(row,slash){
        let result = "";
        if(row.measurement_unit === 'SINGLE'){
            result = slash ? "/each" : "";
        }
        if(row.measurement_unit === 'METERS'){
            result = slash ? "/m" : "m";
        }
        if(row.measurement_unit === 'MILLIMETERS'){
            result = slash ? "/mm" : "mm";
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
</script>

<template>
    <Head title="Import" />

    <AuthenticatedLayout>
        <template #header>
            <h2
                class="text-xl font-semibold leading-tight text-gray-800"
            >
                Import Bill of Materials for '<i>{{project.name}}</i>'
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-5xl">
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
                                {{isDragging ? 'Drop it here!' : 'Click to Upload, or Drag & Drop your BOM in CSV format here'}}
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
            </div>

            <!-- table -->
            <section class="container max-w-5xl mx-auto mt-5">
                <div class="flex items-center gap-x-3">
                    <h2 class="text-lg font-medium text-gray-800 dark:text-white">
                        Bill of Materials
                    </h2>

                    <span class="px-3 py-1 text-xs text-blue-600 bg-blue-100 rounded-full dark:bg-gray-800 dark:text-blue-400">{{materialListRows.length}} rows</span>
                </div>

                <button
                    :disabled="formBulkActions.selectedRawMaterialQuoteIds.length == 0"
                    @click="submitBulkDelete()"
                    :class="formBulkActions.selectedRawMaterialQuoteIds.length == 0 ? 'text-gray-500' : ''"
                    class="bg-red-200 px-2 py-1 rounded"
                >
                    Delete Selected ({{formBulkActions.selectedRawMaterialQuoteIds.length}})
                </button>

                <div class="flex flex-col mt-6">
                    <div class="overflow-x-auto">
                        <div class="inline-block min-w-full py-2 align-middle">
                            <div class="overflow-hidden border border-gray-200 dark:border-gray-700 md:rounded-lg">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-800">
                                    <tr>
                                        <th scope="col" class="py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
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

                                        <th scope="col" class="py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                            <div class="flex items-center gap-x-3">
                                                <span>Actual length/qty</span>
                                            </div>
                                        </th>

                                        <th scope="col" class="py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                            <div class="flex items-center gap-x-3">
                                                <span>Sub Qty</span>
                                            </div>
                                        </th>

                                        <th scope="col" class="py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                            <div class="flex items-center gap-x-3">
                                                <span>Rate</span>
                                            </div>
                                        </th>

                                        <th scope="col" class="py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                            <div class="flex items-center gap-x-3">
                                                <span>Subtotal</span>
                                            </div>
                                        </th>

                                        <th scope="col" class="py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                            <div class="flex items-center gap-x-3">
                                                <span>Check if pre-nested</span>
                                            </div>
                                        </th>

                                        <th scope="col" class="px-12 py-3.5 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                            <button class="flex items-center gap-x-2">
                                                <span>Confirmed</span>
                                            </button>
                                        </th>

                                        <th scope="col" class="relative py-3.5 px-4">
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
                                        <!-- purchasable qty -->
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
                                        <!-- checkIfPreNested -->
                                        <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                            <div class="inline-flex items-center gap-x-3">
                                                <div class="flex items-center gap-x-2">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="m0 0h512v512h-512z"/><path d="m39.557 19 283.883 254h149.003l-283.883-254h-149.002zm-14.557 11.13v25.847l286 255.893v-25.846zm64 107.263v34.584l286 255.893v-84.843l-64-13.002zm-11.445 48.497-42.9 10.723 287.79 257.498 42.9-10.723-287.789-257.498zm-52.555 26.24v23.847l286 255.893v-23.847zm304 78.87v21.973l64 16v126.054l-64 16v21.973h158v-21.973l-64-16v-126.054l64-16v-21.973zm112 135.865v14.108l21.88 5.47z" fill="#fff"/></svg>
                                                    <div>
                                                        <h2 class="font-medium text-gray-800 dark:text-white ">
                                                            {{ row.checkIfPreNested ? 'Yes' : '' }}
                                                        </h2>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <!-- actions -->
                                        <td class="px-12 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                            <div
                                                :class="row.active ? 'bg-emerald-100/60' : 'bg-yellow-100/60'"
                                                class="inline-flex items-center px-3 py-1 rounded-full gap-x-2 dark:bg-gray-800"
                                            >
                                                        <span
                                                            :class="row.active ? 'bg-emerald-500' : 'bg-yellow-500'"
                                                            class="h-1.5 w-1.5 rounded-full"
                                                        ></span>

                                                <h2
                                                    :class="row.active ? 'text-emerald-500' : 'text-yellow-500'"
                                                    class="text-sm font-normal "
                                                >
                                                    {{row.active ? 'Yes' : 'No'}}
                                                </h2>
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

                <div class="flex items-center justify-between mt-6">
                    <a href="#" class="flex items-center px-5 py-2 text-sm text-gray-700 capitalize transition-colors duration-200 bg-white border rounded-md gap-x-2 hover:bg-gray-100 dark:bg-gray-900 dark:text-gray-200 dark:border-gray-700 dark:hover:bg-gray-800">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 rtl:-scale-x-100">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 15.75L3 12m0 0l3.75-3.75M3 12h18" />
                        </svg>

                        <span>
                previous
            </span>
                    </a>

                    <div class="items-center hidden lg:flex gap-x-3">
                        <a href="#" class="px-2 py-1 text-sm text-blue-500 rounded-md dark:bg-gray-800 bg-blue-100/60">1</a>
                        <a href="#" class="px-2 py-1 text-sm text-gray-500 rounded-md dark:hover:bg-gray-800 dark:text-gray-300 hover:bg-gray-100">2</a>
                        <a href="#" class="px-2 py-1 text-sm text-gray-500 rounded-md dark:hover:bg-gray-800 dark:text-gray-300 hover:bg-gray-100">3</a>
                        <a href="#" class="px-2 py-1 text-sm text-gray-500 rounded-md dark:hover:bg-gray-800 dark:text-gray-300 hover:bg-gray-100">...</a>
                        <a href="#" class="px-2 py-1 text-sm text-gray-500 rounded-md dark:hover:bg-gray-800 dark:text-gray-300 hover:bg-gray-100">12</a>
                        <a href="#" class="px-2 py-1 text-sm text-gray-500 rounded-md dark:hover:bg-gray-800 dark:text-gray-300 hover:bg-gray-100">13</a>
                        <a href="#" class="px-2 py-1 text-sm text-gray-500 rounded-md dark:hover:bg-gray-800 dark:text-gray-300 hover:bg-gray-100">14</a>
                    </div>

                    <a href="#" class="flex items-center px-5 py-2 text-sm text-gray-700 capitalize transition-colors duration-200 bg-white border rounded-md gap-x-2 hover:bg-gray-100 dark:bg-gray-900 dark:text-gray-200 dark:border-gray-700 dark:hover:bg-gray-800">
            <span>
                Next
            </span>

                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 rtl:-scale-x-100">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17.25 8.25L21 12m0 0l-3.75 3.75M21 12H3" />
                        </svg>
                    </a>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>

</template>

<style scoped>
    #dropzone {
        width: 100%;
        height: 150px;
        border: 2px dashed #ccc;
        border-radius: 10px;
        text-align: center;
        line-height: 150px;
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

