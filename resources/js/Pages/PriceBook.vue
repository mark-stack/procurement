<script setup>
    //General Imports
    import {useForm, usePage} from "@inertiajs/vue3";
    import {computed, onMounted, ref} from "vue";
    import shared from '@/Shared/shared';

    //Component Imports
    import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";

    //Props
    const props = defineProps({
        products: Object,
    });

    //Form
    const formBulkActions = useForm({
        selectedProductIds: [],
    });

    //Variables
    const screenHeight = window.innerHeight;
    const allChecked = ref(false);

    //Shared data

    //Methods
    function toggleCheckbox(id){
        const index = formBulkActions.selectedProductIds.indexOf(id);
        if (index > -1) {
            // If the number exists, remove it
            formBulkActions.selectedProductIds.splice(index, 1);
        }
        else {
            // If the number doesn't exist, add it
            formBulkActions.selectedProductIds.push(id);
        }
    }

    function cropText(text, maxLength = 25) {
        if (text.length > maxLength) {
            return text.substring(0, maxLength) + "...";
        }
        return text;
    }

    function toggleMasterCheckbox(){
        //Uncheck all
        if(allChecked.value){
            allChecked.value = false;
            formBulkActions.selectedProductIds = [];
        }
        //Check all
        else if(allChecked.value === false){
            allChecked.value = true;
            formBulkActions.selectedProductIds = getAllProductIds();
        }
    }

    function getAllProductIds(){
        let result = [];
        Object.values(props.products).forEach(item => {
            result.push(item.id);
        });

        return result;
    }

    function displayUnits(row,baseValue){
        let displayUnits = "";
        if(baseValue){
            displayUnits = row.nominal_units;
        }

        if(baseValue && row.nominal_units === "METERS"){
            displayUnits = " m";
        }

        if(baseValue && row.nominal_units === "MILLIMETERS"){
            displayUnits = " mm";
        }

        return displayUnits;
    }
</script>

<template>
    <Head title="Import" />

    <AuthenticatedLayout>
        <div class="py-5">
            <!-- table -->
            <section class="container max-w-7xl mx-auto mt-2">
                <div class="flex flex-col">
                    <div class="overflow-x-auto">
                        <div class="inline-block min-w-full py-2 align-middle">
                            <div class="overflow-hidden border border-gray-200 dark:border-gray-700 md:rounded-lg">
                                <div>
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
                                                        <label for="masterCheckbox">Description</label>
                                                    </div>
                                                </th>

                                                <th scope="col" class="sticky top-0 py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                    <div class="flex items-center gap-x-3">
                                                        <span>Product</span>
                                                    </div>
                                                </th>

                                                <th scope="col" class="sticky top-0 py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                    <div class="flex items-center gap-x-3">
                                                        <span>Material</span>
                                                    </div>
                                                </th>

                                                <th scope="col" class="sticky top-0 py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                    <div class="flex items-center gap-x-3">
                                                        <span>Grade</span>
                                                    </div>
                                                </th>

                                                <th scope="col" class="sticky top-0 py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                    <div class="flex items-center gap-x-3">
                                                        <span>Surface</span>
                                                    </div>
                                                </th>

                                                <th scope="col" class="sticky top-0 py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                    <div class="flex items-center gap-x-3">
                                                        <span>Length (nominal)</span>
                                                    </div>
                                                </th>

                                                <th scope="col" class="sticky top-0 py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                    <div class="flex items-center gap-x-3">
                                                        <span>Width (nominal)</span>
                                                    </div>
                                                </th>

                                                <th scope="col" class="sticky top-0 py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                    <div class="flex items-center gap-x-3">
                                                        <span>Height (nominal)</span>
                                                    </div>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200 dark:divide-gray-700 dark:bg-gray-900">
                                            <tr v-for="row in products">
                                                <!-- description -->
                                                <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                    <div class="inline-flex items-center gap-x-3">
                                                        <input
                                                            :id="'check'+row.id"
                                                            type="checkbox"
                                                            :checked="formBulkActions.selectedProductIds.includes(row.id)"
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
                                                <!-- product -->
                                                <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                    <div class="inline-flex items-center gap-x-3">
                                                        <div class="flex items-center gap-x-2">
                                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="m0 0h512v512h-512z"/><path d="m39.557 19 283.883 254h149.003l-283.883-254h-149.002zm-14.557 11.13v25.847l286 255.893v-25.846zm64 107.263v34.584l286 255.893v-84.843l-64-13.002zm-11.445 48.497-42.9 10.723 287.79 257.498 42.9-10.723-287.789-257.498zm-52.555 26.24v23.847l286 255.893v-23.847zm304 78.87v21.973l64 16v126.054l-64 16v21.973h158v-21.973l-64-16v-126.054l64-16v-21.973zm112 135.865v14.108l21.88 5.47z" fill="#fff"/></svg>
                                                            <div>
                                                                <h2 class="font-medium text-gray-800 dark:text-white ">
                                                                    {{ row.product }}
                                                                </h2>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <!-- material -->
                                                <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                    <div class="inline-flex items-center gap-x-3">
                                                        <div class="flex items-center gap-x-2">
                                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="m0 0h512v512h-512z"/><path d="m39.557 19 283.883 254h149.003l-283.883-254h-149.002zm-14.557 11.13v25.847l286 255.893v-25.846zm64 107.263v34.584l286 255.893v-84.843l-64-13.002zm-11.445 48.497-42.9 10.723 287.79 257.498 42.9-10.723-287.789-257.498zm-52.555 26.24v23.847l286 255.893v-23.847zm304 78.87v21.973l64 16v126.054l-64 16v21.973h158v-21.973l-64-16v-126.054l64-16v-21.973zm112 135.865v14.108l21.88 5.47z" fill="#fff"/></svg>
                                                            <div>
                                                                <h2 class="font-medium text-gray-800 dark:text-white ">
                                                                    {{ row.material }}
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
                                                                    {{ row.grade }}
                                                                </h2>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <!-- surface -->
                                                <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                    <div class="inline-flex items-center gap-x-3">
                                                        <div class="flex items-center gap-x-2">
                                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="m0 0h512v512h-512z"/><path d="m39.557 19 283.883 254h149.003l-283.883-254h-149.002zm-14.557 11.13v25.847l286 255.893v-25.846zm64 107.263v34.584l286 255.893v-84.843l-64-13.002zm-11.445 48.497-42.9 10.723 287.79 257.498 42.9-10.723-287.789-257.498zm-52.555 26.24v23.847l286 255.893v-23.847zm304 78.87v21.973l64 16v126.054l-64 16v21.973h158v-21.973l-64-16v-126.054l64-16v-21.973zm112 135.865v14.108l21.88 5.47z" fill="#fff"/></svg>
                                                            <div>
                                                                <h2 class="font-medium text-gray-800 dark:text-white ">
                                                                    {{ row.surface }}
                                                                </h2>
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
                                                                    {{ row.nominal_length }}{{displayUnits(row,row.nominal_length)}}
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
                                                                    {{ row.nominal_width }}{{displayUnits(row,row.nominal_width)}}
                                                                </h2>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <!-- height -->
                                                <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                    <div class="inline-flex items-center gap-x-3">
                                                        <div class="flex items-center gap-x-2">
                                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="m0 0h512v512h-512z"/><path d="m39.557 19 283.883 254h149.003l-283.883-254h-149.002zm-14.557 11.13v25.847l286 255.893v-25.846zm64 107.263v34.584l286 255.893v-84.843l-64-13.002zm-11.445 48.497-42.9 10.723 287.79 257.498 42.9-10.723-287.789-257.498zm-52.555 26.24v23.847l286 255.893v-23.847zm304 78.87v21.973l64 16v126.054l-64 16v21.973h158v-21.973l-64-16v-126.054l64-16v-21.973zm112 135.865v14.108l21.88 5.47z" fill="#fff"/></svg>
                                                            <div>
                                                                <h2 class="font-medium text-gray-800 dark:text-white ">
                                                                    {{ row.nominal_height }}{{displayUnits(row,row.nominal_height)}}
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

