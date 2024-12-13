<script setup>
    //General Imports
    import { Link, Head, useForm} from '@inertiajs/vue3';
    import {ref} from "vue";

    //Component Imports
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';


    //Props
    const props = defineProps({
        projects: Object,
    });

    //Form
    const formProjectCreate = useForm({
        name: null,
        tendering_stage: true,
        reference: null,
    });
    const formProjectDelete = useForm({});

    //Shared data
    //...

    //Variables
    const editProject = ref(null);

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
    function submitDelete(id){
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

    function deleteConfirmation(id) {
        const userConfirmed = confirm("Are you sure you want to archive this project? Any quotes or products created from this project will be retained.");
        if (userConfirmed) {
            // User clicked "OK"
            submitDelete(id)
        }
    }

    function editMode(project){
        editProject.value = project;

        //Populate form
        formProjectCreate.name = project.name;
        formProjectCreate.tendering_stage = project.tendering_stage === 1;
        formProjectCreate.reference = project.reference;
    }
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <div class="py-12">
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                <section
                    class="dark:bg-gray-900 rounded-xl"
                    :class="editProject ? 'bg-yellow-50' : 'bg-white'"
                >
                    <div class="px-6 pt-8 pb-8 mx-auto text-center">
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100">
                            {{editProject ? 'Edit' : 'New'}} Project
                        </h1>
                        <p
                            v-if="editProject"
                            @click="editProject = null"
                            class="text-blue-500 text-sm underline mt-2"
                            style="cursor: pointer;"
                        >
                            Back to New Project
                        </p>
                        <div class="flex flex-col mt-8 space-y-2 sm:space-y-0 sm:flex-row sm:justify-center sm:-mx-2">
                            <form @submit.prevent="submit()">
                                <div>
                                    <!-- Name -->
                                    <input
                                        v-model="formProjectCreate.name"
                                        type="text"
                                        class="px-4 py-2 text-gray-700 bg-white border rounded-md sm:mx-2 dark:bg-gray-900 dark:text-gray-300 dark:border-gray-600 focus:border-blue-400 dark:focus:border-blue-300 focus:outline-none focus:ring focus:ring-blue-300 focus:ring-opacity-40"
                                        placeholder="Name"
                                        required
                                    >
                                    <div v-if="formProjectCreate.errors.name">{{ formProjectCreate.errors.name }}</div>

                                    <!-- Reference -->
                                    <input
                                        v-model="formProjectCreate.reference"
                                        type="text"
                                        class="px-4 py-2 text-gray-700 bg-white border rounded-md sm:mx-2 dark:bg-gray-900 dark:text-gray-300 dark:border-gray-600 focus:border-blue-400 dark:focus:border-blue-300 focus:outline-none focus:ring focus:ring-blue-300 focus:ring-opacity-40"
                                        placeholder="Reference ID"
                                        required
                                    >
                                    <div v-if="formProjectCreate.errors.reference">{{ formProjectCreate.errors.reference }}</div>

                                    <!-- submit -->
                                    <button
                                        type="submit"
                                        :disabled="formProjectCreate.processing"
                                        class="px-4 py-2 text-sm font-medium tracking-wide text-white capitalize transition-colors duration-300 transform bg-blue-700 rounded-md sm:mx-2 hover:bg-blue-600 focus:outline-none focus:bg-blue-600"
                                    >
                                        {{editProject ? 'Update' : 'Create'}}
                                    </button>
                                </div>

                                <div class="mt-4">
                                    <label for="tendering_stage">Tender phase?</label>
                                    <input
                                        id="tendering_stage"
                                        v-model="formProjectCreate.tendering_stage"
                                        type="checkbox"
                                        class="ml-2"
                                    >
                                </div>
                            </form>
                        </div>
                    </div>
                </section>

                <section class="container mx-auto mt-5">
                    <div class="flex items-center gap-x-3">
                        <h2 class="text-lg font-medium text-gray-800 dark:text-white">Projects</h2>

                        <span class="px-3 py-1 text-xs text-blue-600 bg-blue-100 rounded-full dark:bg-gray-800 dark:text-blue-400">{{projects.length}} projects</span>
                    </div>

                    <div class="flex flex-col mt-6">
                        <div class="-mx-4 -my-2 overflow-x-auto sm:-mx-6 lg:-mx-8">
                            <div class="inline-block min-w-full py-2 align-middle md:px-6 lg:px-8">
                                <div class="overflow-y-auto border border-gray-200 dark:border-gray-700 md:rounded-lg" style="height:300px">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead class="bg-gray-50 dark:bg-gray-800">
                                            <tr>
                                                <th scope="col" class="py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                    <div class="flex items-center gap-x-3">
                                                        <span>Name</span>
                                                    </div>
                                                </th>

                                                <th scope="col" class="px-12 py-3.5 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                    <button class="flex items-center gap-x-2">
                                                        <span>Status</span>
                                                    </button>
                                                </th>

                                                <th scope="col" class="px-12 py-3.5 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                    <button class="flex items-center gap-x-2">
                                                        <span>Materials</span>
                                                    </button>
                                                </th>

                                                <th scope="col" class="px-12 py-3.5 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                    <button class="flex items-center gap-x-2">
                                                        <span>Actions</span>
                                                    </button>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200 dark:divide-gray-700 dark:bg-gray-900">
                                            <tr v-for="project in projects">
                                                <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                    <div class="inline-flex items-center gap-x-3">
                                                        <div class="flex items-center gap-x-2">
                                                            <img class="object-cover w-10 h-10 rounded-full" src="https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?ixlib=rb-1.2.1&ixid=MnwxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8&auto=format&fit=crop&w=880&q=80" alt="">
                                                            <div>
                                                                <h2 class="font-medium text-gray-800 dark:text-white ">{{ project.name }}</h2>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-8 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                    <div
                                                        :class="project.tendering_stage ? 'bg-yellow-200/60' : 'bg-emerald-100/60'"
                                                        class="inline-flex items-center px-3 py-1 rounded-full gap-x-2 dark:bg-gray-800"
                                                    >
                                                        <span
                                                            :class="project.tendering_stage ? 'bg-yellow-500' : 'bg-emerald-500'"
                                                            class="h-1.5 w-1.5 rounded-full"
                                                        ></span>

                                                        <h2
                                                            :class="project.tendering_stage ? 'text-yellow-800' : 'text-emerald-500'"
                                                            class="text-sm font-semibold"
                                                        >
                                                            {{project.tendering_stage ? 'Tender' : 'Project'}}
                                                        </h2>
                                                    </div>
                                                </td>
                                                <td class="px-4 py-4 text-sm whitespace-nowrap">
                                                    <div class="flex justify-center items-center gap-x-6">
                                                        <Link
                                                            :href="route('products.store',project.id)"
                                                            class="bg-green-50 px-2 py-1 rounded border-2 border-green-300 hover:bg-green-100"
                                                        >
                                                            Bill of Materials
                                                        </Link>
                                                    </div>
                                                </td>
                                                <td class="px-4 py-4 text-sm whitespace-nowrap">
                                                    <div class="flex justify-center items-center gap-x-6">
                                                        <button
                                                            @click="deleteConfirmation(project.id)"
                                                            class="text-gray-500 transition-colors duration-200 dark:hover:text-red-500 dark:text-gray-300 hover:text-red-500 focus:outline-none"
                                                        >
                                                            Archive
                                                        </button>
                                                        <button @click="editMode(project)">
                                                            Edit
                                                        </button>
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
            </div>
        </div>
    </AuthenticatedLayout>
</template>
