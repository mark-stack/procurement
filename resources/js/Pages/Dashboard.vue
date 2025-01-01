<script setup>
    //General Imports
    import { Link, Head, useForm} from '@inertiajs/vue3';
    import {ref} from "vue";

    //Component Imports
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';


    //Props
    const props = defineProps({
        projects: Object,
        countArchivedProjects: Number,
    });

    //Form
    const formProjectCreate = useForm({
        name: null,
        awarded: false,
        date_materials_required: null,
        reference: null,
        tentative: true,
    });
    const formProjectDelete = useForm({});

    //Shared data
    //...

    //Variables
    const editProject = ref(null);
    const showArchivedProjects = ref(false);

    //Shared Methods
    //...

    //Methods
    function submit(){
        //Edit mode
        if(editProject.value){
            let url = route("projects.update",editProject.value.id);
            formProjectCreate.put(url, {
                preserveScroll: true,
                onSuccess: () => {
                    console.log('success');
                    formProjectCreate.reset();
                    editProject.value = null;
                },
                onError: errors => {
                    console.log('errors',errors);
                },
            });
        }
        //Create mode
        else{
            let url = route("projects.store");
            formProjectCreate.post(url, {
                preserveScroll: true,
                onSuccess: () => {
                    console.log('success');
                    formProjectCreate.reset();
                },
                onError: errors => {
                    console.log('errors',errors);
                },
            });
        }
    }
    function submitArchiveToggle(id){
        let url = route("projects.destroy",id);
        formProjectDelete.delete(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log('success');
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }

    function toggleArchive(project) {
        let msg = project.archive ? "Are you sure you want to restore this project?" : "Are you sure you want to archive this project? It can be restored later of you choose";
        const userConfirmed = confirm(msg);
        if (userConfirmed) {
            // User clicked "OK"
            submitArchiveToggle(project.id)
        }
    }

    function editMode(project){
        editProject.value = project;

        //Populate form
        formProjectCreate.name = project.name;
        formProjectCreate.awarded = project.awarded === 1;
        formProjectCreate.date_materials_required = project.date_materials_required;
        formProjectCreate.reference = project.reference;
        formProjectCreate.tentative = project.tentative;
    }

    function showRow(project){
        let showRow = true;

        if(showArchivedProjects.value === false){
            showRow = !project.archive;
        }


        return showRow;
    }

    function checkBoxActions(){
        /**
            If awarded = false, clear "reference" and "date_materials_required"
         */
        let awardedToggledTo = !formProjectCreate.awarded;
        if(awardedToggledTo === false){
            formProjectCreate.reset("reference","date_materials_required");
        }
    }
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <div class="py-3">
            <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
                <section
                    class="dark:bg-gray-900 rounded-xl"
                    :class="editProject ? 'bg-yellow-50' : 'bg-white'"
                >
                    <div class="px-6 pt-8 pb-8 mx-auto text-center shadow-xl">
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100">
                            {{editProject ? ('Edit "' + formProjectCreate.name + '" ') : 'New'}} Project
                        </h1>
                        <p
                            v-if="editProject"
                            @click="editProject = null"
                            class="text-blue-500 text-sm underline mt-2"
                            style="cursor: pointer;"
                        >
                            Back to New Project
                        </p>


                        <div class="max-w-5xl p-6 mx-auto">
                            <form @submit.prevent="submit()" class="text-left">
                                <div class="grid grid-cols-1 gap-6 mt-4 sm:grid-cols-2">
                                    <!-- Name -->
                                    <div>
                                        <label class="text-gray-700 dark:text-gray-200 ml-2">Project Name</label>
                                        <input
                                            v-model="formProjectCreate.name"
                                            type="text"
                                            class="w-full px-4 py-2 text-gray-700 bg-white border rounded-md sm:mx-2 dark:bg-gray-900 dark:text-gray-300 dark:border-gray-600 focus:border-blue-400 dark:focus:border-blue-300 focus:outline-none focus:ring focus:ring-blue-300 focus:ring-opacity-40"
                                            placeholder="Name"
                                            required
                                        >
                                        <div v-if="formProjectCreate.errors.name" class="text-sm text-red-500">{{ formProjectCreate.errors.name }}</div>
                                    </div>

                                    <!-- Awarded? -->
                                    <div class="pt-7">
                                        <label for="awarded" class="ml-2">You've been awarded the project?</label>
                                        <input
                                            id="awarded"
                                            v-model="formProjectCreate.awarded"
                                            type="checkbox"
                                            class="ml-2"
                                            @click="checkBoxActions()"
                                        >
                                    </div>

                                    <!-- Project reference -->
                                    <div v-if="formProjectCreate.awarded">
                                        <label class="text-gray-700 dark:text-gray-200 ml-2">Project reference</label>
                                        <input
                                            v-model="formProjectCreate.reference"
                                            type="text"
                                            class="w-full px-4 py-2 text-gray-700 bg-white border rounded-md sm:mx-2 dark:bg-gray-900 dark:text-gray-300 dark:border-gray-600 focus:border-blue-400 dark:focus:border-blue-300 focus:outline-none focus:ring focus:ring-blue-300 focus:ring-opacity-40"
                                            placeholder="Reference ID"
                                            required
                                        >
                                        <div v-if="formProjectCreate.errors.reference" class="text-sm text-red-500">{{ formProjectCreate.errors.reference }}</div>
                                    </div>

                                    <!-- Date materials required -->
                                    <div v-if="formProjectCreate.awarded">
                                        <label class="text-gray-700 dark:text-gray-200 ml-2">{{formProjectCreate.tentative ? 'Tentative d' : 'D'}}ate materials required</label>
                                        <input
                                            v-model="formProjectCreate.date_materials_required"
                                            type="date"
                                            class="w-full px-4 py-2 text-gray-700 bg-white border rounded-md sm:mx-2 dark:bg-gray-900 dark:text-gray-300 dark:border-gray-600 focus:border-blue-400 dark:focus:border-blue-300 focus:outline-none focus:ring focus:ring-blue-300 focus:ring-opacity-40"
                                            required
                                        >
                                        <div v-if="formProjectCreate.errors.date_materials_required" class="text-sm text-red-500">{{ formProjectCreate.errors.date_materials_required }}</div>
                                    </div>
                                </div>

                                <div class="flex justify-end mt-6">
                                    <button
                                        type="submit"
                                        :disabled="formProjectCreate.processing"
                                        class="px-4 py-2 text-sm font-medium tracking-wide text-white capitalize transition-colors duration-300 transform bg-blue-700 rounded-md sm:mx-2 hover:bg-blue-600 focus:outline-none focus:bg-blue-600"
                                    >
                                        {{editProject ? 'Update' : 'Create'}}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </section>

                <section class="container mx-auto mt-5">
                    <div class="flex items-center gap-x-3">
                        <h2 class="text-lg font-medium text-gray-800 dark:text-white">Projects</h2>

                        <span class="px-3 py-1 text-xs text-blue-600 bg-blue-100 rounded-full dark:bg-gray-800 dark:text-blue-400">{{projects.data.length}} projects</span>
                    </div>

                    <div class="flex flex-col mt-6">
                        <div class="-mx-4 -my-2 overflow-x-auto sm:-mx-6 lg:-mx-8">
                            <div class="inline-block min-w-full py-2 align-middle md:px-6 lg:px-8">
                                <div class="overflow-y-auto border border-gray-200 dark:border-gray-700 md:rounded-lg">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead class="bg-gray-50 dark:bg-gray-800">
                                            <tr>
                                                <th scope="col" class="py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                    Project
                                                </th>

                                                <th scope="col" class="px-12 py-3.5 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                    Awarded
                                                </th>

                                                <th scope="col" class="px-12 py-3.5 text-sm font-normal text-center text-gray-500 dark:text-gray-400">
                                                    Imported Materials
                                                </th>

                                                <th scope="col" class="px-12 py-3.5 text-sm font-normal text-center text-gray-500 dark:text-gray-400">
                                                    Quoted
                                                </th>

                                                <th scope="col" class="px-12 py-3.5 text-sm font-normal text-center text-gray-500 dark:text-gray-400">
                                                    Ordered
                                                </th>

                                                <th scope="col" class="px-12 py-3.5 text-sm font-normal text-center text-gray-500 dark:text-gray-400">
                                                    Actions
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200 dark:divide-gray-700 dark:bg-gray-900">
                                            <template v-for="project in projects.data">
                                                <tr v-if="showRow(project)" :class="project.archive ? 'bg-red-50' : ''">
                                                    <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                        <div class="inline-flex items-center gap-x-3">
                                                            <div class="flex items-center gap-x-2">
                                                                <div>
                                                                    <h2 class="font-medium text-gray-800 dark:text-white ">{{ project.name }}</h2>
                                                                    <small>Ref: {{project.reference}}</small>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="px-8 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                        <div
                                                            :class="project.archive ? 'bg-gray-100/60' : (project.awarded ? 'bg-emerald-100/60' : 'bg-yellow-200/60')"
                                                            class="inline-flex items-center px-3 py-1 rounded-full gap-x-2 dark:bg-gray-800"
                                                        >
                                                        <span
                                                            :class="project.archive ? 'bg-gray-500' : (project.awarded ? 'bg-emerald-500' : 'bg-yellow-500')"
                                                            class="h-1.5 w-1.5 rounded-full"
                                                        ></span>

                                                            <h2
                                                                :class="project.archive ? 'text-gray-500' : (project.awarded ? 'text-emerald-500' : 'text-yellow-800')"
                                                                class="text-sm font-semibold"
                                                            >
                                                                {{project.awarded ? 'Awarded' : 'Tender'}}
                                                            </h2>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 py-4 text-sm whitespace-nowrap">
                                                        <div class="flex justify-center items-center gap-x-6">
                                                            <p
                                                                v-if="project.archive"
                                                                class="px-2 py-1 rounded border-2 bg-gray-300 border-gray-500"
                                                            >
                                                                {{project.hasRawMaterialQuotes ? 'Imported Materials' : 'Import Materials'}}
                                                            </p>
                                                            <Link
                                                                v-else
                                                                :href="route('products.store',project.id)"
                                                                :class="project.hasRawMaterialQuotes ? 'text-emerald-500 bg-emerald-100 border-emerald-300 hover:bg-emerald-200' : 'text-orange-500 bg-orange-50 border-orange-300 hover:bg-orange-100'"
                                                                class="px-2 py-1 rounded border-2 font-semibold"
                                                            >
                                                                {{project.hasRawMaterialQuotes ? 'Imported Materials' : 'Import Materials'}}
                                                            </Link>
                                                        </div>
                                                    </td>
                                                    <td
                                                        class="font-bold px-4 py-4 text-sm whitespace-nowrap text-center"
                                                        :class="project.percentageOfMaterialsQuoted < 70 ? 'text-orange-500' : ''"
                                                    >
                                                        {{project.percentageOfMaterialsQuoted}}%
                                                    </td>
                                                    <td
                                                        class="font-bold px-4 py-4 text-sm whitespace-nowrap text-center"
                                                        :class="project.percentageOfMaterialsOrdered < 70 ? 'text-orange-500' : ''"
                                                    >
                                                        {{project.percentageOfMaterialsOrdered}}%
                                                    </td>
                                                    <td class="px-4 py-4 text-sm whitespace-nowrap">
                                                        <div class="flex justify-center items-center gap-x-6">
                                                            <button
                                                                @click="toggleArchive(project)"
                                                                class="text-gray-500 transition-colors duration-200 dark:hover:text-red-500 dark:text-gray-300 hover:text-red-500 focus:outline-none"
                                                            >
                                                                {{project.archive ? 'Restore' : 'Archive'}}
                                                            </button>
                                                            <button v-if="!project.archive" @click="editMode(project)">
                                                                Edit
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                                <div
                                    v-if="countArchivedProjects > 0"
                                    @click="showArchivedProjects = !showArchivedProjects"
                                    class="text-center text-blue-500 underline mt-3"
                                >
                                    {{showArchivedProjects ? 'Hide' : 'Show'}} {{countArchivedProjects}} Archived Project{{countArchivedProjects > 1 ? 's' : ''}}
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
