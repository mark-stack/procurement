<script setup>
    //General Imports
    import {ref} from "vue";
    import {Link} from "@inertiajs/vue3";

    //Component Imports
    import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
    import CardButtonBlue from "@/Components/Buttons/CardButtonBlue.vue";

    //Props
    const props = defineProps({
        pastBatches: Object,
    });

    //Form
    //...

    //Shared data
    //...

    //Variables
    const loadingButton = ref(null);

    //Shared Methods
    //...

    //Methods
    function cropText(text, maxLength = 20) {
        if (text.length > maxLength) {
            return text.substring(0, maxLength) + "...";
        }
        return text;
    }
</script>

<template>
    <Head title="Past Projects" />

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
                                                        <label for="masterCheckbox">Projects</label>
                                                    </div>
                                                </th>

                                                <th scope="col" class="sticky top-0 py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                    <div class="flex items-center gap-x-3">
                                                        <span>Created by</span>
                                                    </div>
                                                </th>

                                                <th scope="col" class="sticky top-0 py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                    <div class="flex items-center gap-x-3">
                                                        <span>Orders</span>
                                                    </div>
                                                </th>

                                                <th scope="col" class="sticky top-0 py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                    <div class="flex items-center gap-x-3">
                                                        <span>Nesting</span>
                                                    </div>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200 dark:divide-gray-700 dark:bg-gray-900">
                                            <tr v-for="batch in pastBatches">
                                                <!-- created at -->
                                                <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                    <div class="inline-flex items-center gap-x-3">
                                                        <div class="flex items-center gap-x-2">
                                                            <div>
                                                                <p
                                                                    v-for="project in batch.projects"
                                                                    class="font-medium text-gray-800 dark:text-white"
                                                                >
                                                                    {{project.name}}
                                                                </p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <!-- project manager -->
                                                <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                    <div class="inline-flex items-center gap-x-3">
                                                        <div class="flex items-center gap-x-2">
                                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="m0 0h512v512h-512z"/><path d="m39.557 19 283.883 254h149.003l-283.883-254h-149.002zm-14.557 11.13v25.847l286 255.893v-25.846zm64 107.263v34.584l286 255.893v-84.843l-64-13.002zm-11.445 48.497-42.9 10.723 287.79 257.498 42.9-10.723-287.789-257.498zm-52.555 26.24v23.847l286 255.893v-23.847zm304 78.87v21.973l64 16v126.054l-64 16v21.973h158v-21.973l-64-16v-126.054l64-16v-21.973zm112 135.865v14.108l21.88 5.47z" fill="#fff"/></svg>
                                                            <div>
                                                                <h2 class="font-medium text-gray-800 dark:text-white ">
                                                                    {{ batch.projectManager }} ({{ batch.createdAt }})
                                                                </h2>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <!-- x -->
                                                <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                    <div class="inline-flex items-center gap-x-3">
                                                        <div class="flex items-center gap-x-2">
                                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="m0 0h512v512h-512z"/><path d="m39.557 19 283.883 254h149.003l-283.883-254h-149.002zm-14.557 11.13v25.847l286 255.893v-25.846zm64 107.263v34.584l286 255.893v-84.843l-64-13.002zm-11.445 48.497-42.9 10.723 287.79 257.498 42.9-10.723-287.789-257.498zm-52.555 26.24v23.847l286 255.893v-23.847zm304 78.87v21.973l64 16v126.054l-64 16v21.973h158v-21.973l-64-16v-126.054l64-16v-21.973zm112 135.865v14.108l21.88 5.47z" fill="#fff"/></svg>
                                                            <div>
                                                                <h2 class="font-medium text-gray-800 dark:text-white ">
                                                                    {{ batch.ordersQty }}
                                                                </h2>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <!-- x -->
                                                <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                    <div class="inline-flex items-center gap-x-3">
                                                        <div class="flex items-center gap-x-2">
                                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="m0 0h512v512h-512z"/><path d="m39.557 19 283.883 254h149.003l-283.883-254h-149.002zm-14.557 11.13v25.847l286 255.893v-25.846zm64 107.263v34.584l286 255.893v-84.843l-64-13.002zm-11.445 48.497-42.9 10.723 287.79 257.498 42.9-10.723-287.789-257.498zm-52.555 26.24v23.847l286 255.893v-23.847zm304 78.87v21.973l64 16v126.054l-64 16v21.973h158v-21.973l-64-16v-126.054l64-16v-21.973zm112 135.865v14.108l21.88 5.47z" fill="#fff"/></svg>
                                                            <div>
                                                                <h2 class="font-medium text-gray-800 dark:text-white ">
                                                                    <Link
                                                                        :href="route('batch.nesting',[batch.id,'past'])"
                                                                        class="w-full"
                                                                        @click="loadingButton = 'NESTING_DETAILS'"
                                                                    >
                                                                        <CardButtonBlue
                                                                            :label="loadingButton === 'NESTING_DETAILS' ? 'Calculating...' : 'Nesting details'"
                                                                            :highlight="false"
                                                                        />
                                                                    </Link>
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

</style>
