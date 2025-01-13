<script setup>
    //General Imports
    import { Link, Head, useForm} from '@inertiajs/vue3';
    import {ref} from "vue";

    //Component Imports
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
    import KanbanNeedsImportingCard from "@/Components/KanbanNeedsImportingCard.vue";
    import KanbanReadyForNestingCard from "@/Components/KanbanReadyForNestingCard.vue";
    import KanbanGeneralBatchCard from "@/Components/KanbanGeneralBatchCard.vue";
    import KanbanModal from "@/Components/KanbanModal.vue";

    //Props
    const props = defineProps({
        projects: Object,
        batches: Object,
        archivedProjects: Object,
    });

    //Forms
    const formProjectCreate = useForm({
        name: null,
        awarded: true,
        date_materials_required: null,
        reference: null,
        tentative: true,
    });
    const formProjectDelete = useForm({});
    const formQuoteStore = useForm({});
    const formOrdersStore = useForm({
        batch_id: null,
    });

    //Shared data
    //...

    //Variables
    const editProject = ref(null);
    const showArchivedProjects = ref(false);
    const showModal = ref(false);
    const modalData = ref(null);

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

                //Hide archived projects if now zero items
                if(props.archivedProjects.data.length === 0){
                    showArchivedProjects.value = false;
                }
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

    // function checkBoxActions(){
    //     /**
    //         If awarded = false, clear "reference" and "date_materials_required"
    //      */
    //     let awardedToggledTo = !formProjectCreate.awarded;
    //     if(awardedToggledTo === false){
    //         formProjectCreate.reset("reference","date_materials_required");
    //     }
    // }

    function backToNewProject(){
        //Clear the form
        formProjectCreate.reset();

        editProject.value = null;
    }

    function quoteNow(){
        let url = route("quotes.store");

        formQuoteStore.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log('success');
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }

    function orderNow(batch){
        let url = route("orders.store");

        formOrdersStore.batch_id = null;
        formOrdersStore.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log('success');
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <div class="py-3">
            <div class="mx-auto max-w-7xl">
                <section
                    class="dark:bg-gray-900 rounded-xl"
                    :class="editProject ? 'bg-yellow-50' : 'bg-white'"
                >
                    <div class="px-6 pt-4 pb-4 mx-auto text-center shadow-xl">
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100">
                            {{editProject ? ('Edit "' + formProjectCreate.name + '" ') : 'New'}} Project
                        </h1>
                        <p
                            v-if="editProject"
                            @click="backToNewProject()"
                            class="text-blue-500 text-sm underline mt-2"
                            style="cursor: pointer;"
                        >
                            Back to New Project
                        </p>


                        <div class="max-w-5xl pb-2 mx-auto">
                            <form @submit.prevent="submit()" class="text-left">
                                <div class="grid grid-cols-1 sm:grid-cols-10 gap-6 mt-4">
                                    <!-- Name -->
                                    <div class="col-span-4">
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
<!--                                    <div class="pt-7">-->
<!--                                        <label for="awarded" class="ml-2">You've been awarded the project?</label>-->
<!--                                        <input-->
<!--                                            id="awarded"-->
<!--                                            v-model="formProjectCreate.awarded"-->
<!--                                            type="checkbox"-->
<!--                                            class="ml-2"-->
<!--                                            @click="checkBoxActions()"-->
<!--                                        >-->
<!--                                    </div>-->

                                    <!-- Project reference -->
                                    <div
                                        v-if="formProjectCreate.awarded"
                                        class="col-span-2"
                                    >
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
                                    <div
                                        v-if="formProjectCreate.awarded"
                                        class="col-span-2"
                                    >
                                        <label class="text-gray-700 dark:text-gray-200 ml-2">Materials required by</label>
                                        <input
                                            v-model="formProjectCreate.date_materials_required"
                                            type="date"
                                            class="w-full px-4 py-2 text-gray-700 bg-white border rounded-md sm:mx-2 dark:bg-gray-900 dark:text-gray-300 dark:border-gray-600 focus:border-blue-400 dark:focus:border-blue-300 focus:outline-none focus:ring focus:ring-blue-300 focus:ring-opacity-40"
                                            required
                                        >
                                        <div v-if="formProjectCreate.errors.date_materials_required" class="text-sm text-red-500">{{ formProjectCreate.errors.date_materials_required }}</div>
                                    </div>

                                    <!-- submit button -->
                                    <div class="col-span-2">
                                        <button
                                            type="submit"
                                            :disabled="formProjectCreate.processing"
                                            style="height:40px"
                                            class="w-full mt-6 px-4 py-2 text-sm font-medium tracking-wide text-white capitalize transition-colors duration-300 transform bg-blue-700 rounded-md sm:mx-2 hover:bg-blue-600 focus:outline-none focus:bg-blue-600"
                                        >
                                            {{editProject ? 'Update' : 'Create'}}
                                        </button>
                                    </div>

                                </div>
                            </form>
                        </div>
                    </div>
                </section>

                <section class="mt-5 mb-20">

                    <!-- kanban -->
                    <div class="grid grid-cols-5 gap-x-3">
                        <!-- Needs BOM Import -->
                        <div>
                            <!-- header -->
                            <div class="border-b-2 border-gray-500">
                                <h2 class="font-bold text-center">Need to import BOM</h2>
                            </div>
                            <!-- body -->
                            <div class="grid grid-cols-1 gap-y-2 pt-3">
                                <!-- card -->
                                <template v-for="project in projects['BOM_REQUIRED'].data">
                                    <KanbanNeedsImportingCard
                                        :projects="[project]"
                                        @toggleArchive="p => toggleArchive(p)"
                                        @editMode="p => editMode(p)"
                                    />
                                </template>
                            </div>
                        </div>
                        <!-- Ready for auto nesting -->
                        <div>
                            <!-- header -->
                            <div class="border-b-2 border-gray-500">
                                <h2 class="font-bold text-center">Nesting</h2>
                            </div>
                            <!-- body -->
                            <div class="grid grid-cols-1 gap-y-2 pt-3">
                                <!-- card -->
                                <KanbanReadyForNestingCard
                                    v-if="projects['BOM_IMPORTED'].data.length > 0"
                                    :projects="projects['BOM_IMPORTED'].data"
                                    @toggleArchive="p => toggleArchive(p)"
                                    @editMode="p => editMode(p)"
                                    @quoteNow="quoteNow()"
                                    @orderNow="orderNow()"
                                />
                            </div>
                        </div>
                        <!-- Quoted -->
                        <div>
                            <!-- header -->
                            <div class="border-b-2 border-gray-500">
                                <h2 class="font-bold text-center">Quoting</h2>
                            </div>
                            <!-- body -->
                            <div class="grid grid-cols-1 gap-y-2 pt-3">
                                <!-- card -->
                                <KanbanGeneralBatchCard
                                    v-for="batch in batches['QUOTED']"
                                    :batch="batch['batch']"
                                    :projects="batch['projects'].data"
                                    :otherData="batch['otherData']"
                                    :modalData="batch['modalData']"
                                    type="QUOTES"
                                    @toggleArchive="p => toggleArchive(p)"
                                    @editMode="p => editMode(p)"
                                    @showModal="data => {modalData = data; showModal = true;}"
                                />
                            </div>
                        </div>
                        <!-- Ordered -->
                        <div>
                            <!-- header -->
                            <div class="border-b-2 border-gray-500">
                                <h2 class="font-bold text-center">Ordering</h2>
                            </div>
                            <!-- body -->
                            <div class="grid grid-cols-1 gap-y-2 pt-3">
                                <!-- card -->
                                <KanbanGeneralBatchCard
                                    v-for="batch in batches['ORDERED']"
                                    :batch="batch['batch']"
                                    :projects="batch['projects'].data"
                                    :otherData="batch['otherData']"
                                    :modalData="batch['modalData']"
                                    type="ORDERS"
                                    @toggleArchive="p => toggleArchive(p)"
                                    @editMode="p => editMode(p)"
                                    @showModal="data => {modalData = data; showModal = true;}"
                                />
                            </div>
                        </div>
                        <!-- Materials Received -->
                        <div>
                            <!-- header -->
                            <div class="border-b-2 border-gray-500">
                                <h2 class="font-bold text-center">Delivered</h2>
                            </div>
                            <!-- body -->
                            <div class="grid grid-cols-1 gap-y-2 pt-3">
                                <!-- card -->
                                <KanbanGeneralBatchCard
                                    v-for="batch in batches['DELIVERED']"
                                    :batch="batch['batch']"
                                    :projects="batch['projects'].data"
                                    :otherData="batch['otherData']"
                                    :modalData="batch['modalData']"
                                    type="DELIVERED"
                                    @toggleArchive="p => toggleArchive(p)"
                                    @editMode="p => editMode(p)"
                                    @showModal="data => {modalData = data; showModal = true;}"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- toggle archived projects -->
                    <div>
                        <button
                            v-if="archivedProjects.data.length > 0"
                            @click="showArchivedProjects = !showArchivedProjects"
                            class="text-center text-blue-500 underline mt-3 mb-2"
                        >
                            {{showArchivedProjects ? 'Hide' : 'Show'}} {{archivedProjects.data.length}} Archived Project{{archivedProjects.data.length > 1 ? 's' : ''}}
                        </button>
                        <div v-if="showArchivedProjects">
                            <table>
                                <tr>
                                   <th class="p-1">Name</th>
                                   <th class="p-1">Actions</th>
                                </tr>
                                <tr v-for="project in archivedProjects.data">
                                    <td class="p-1">{{project.name}}</td>
                                    <td class="p-1">
                                        <span style="cursor: pointer; " class="underline text-blue-500" @click="toggleArchive(project)">restore</span>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </section>


<!--                <section class="container mx-auto mt-5">-->
<!--                    <div class="flex items-center gap-x-3">-->
<!--                        <h2 class="text-lg font-medium text-gray-800 dark:text-white">Projects</h2>-->

<!--                        <span class="px-3 py-1 text-xs text-blue-600 bg-blue-100 rounded-full dark:bg-gray-800 dark:text-blue-400">{{projects["BOM_REQUIRED"].data.length}} projects</span>-->
<!--                    </div>-->

<!--                    <div class="flex flex-col mt-6">-->
<!--                        <div class="-mx-4 -my-2 overflow-x-auto sm:-mx-6 lg:-mx-8">-->
<!--                            <div class="inline-block min-w-full py-2 align-middle md:px-6 lg:px-8">-->
<!--                                <div class="overflow-y-auto border border-gray-200 dark:border-gray-700 md:rounded-lg">-->
<!--                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">-->
<!--                                        <thead class="bg-gray-50 dark:bg-gray-800">-->
<!--                                            <tr>-->
<!--                                                <th scope="col" class="py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">-->
<!--                                                    Project-->
<!--                                                </th>-->

<!--                                                <th scope="col" class="px-12 py-3.5 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">-->
<!--                                                    Awarded-->
<!--                                                </th>-->

<!--                                                <th scope="col" class="px-12 py-3.5 text-sm font-normal text-center text-gray-500 dark:text-gray-400">-->
<!--                                                    Imported Materials-->
<!--                                                </th>-->

<!--                                                <th scope="col" class="px-12 py-3.5 text-sm font-normal text-center text-gray-500 dark:text-gray-400">-->
<!--                                                    Quoted-->
<!--                                                </th>-->

<!--                                                <th scope="col" class="px-12 py-3.5 text-sm font-normal text-center text-gray-500 dark:text-gray-400">-->
<!--                                                    Ordered-->
<!--                                                </th>-->

<!--                                                <th scope="col" class="px-12 py-3.5 text-sm font-normal text-center text-gray-500 dark:text-gray-400">-->
<!--                                                    Actions-->
<!--                                                </th>-->
<!--                                            </tr>-->
<!--                                        </thead>-->
<!--                                        <tbody class="bg-white divide-y divide-gray-200 dark:divide-gray-700 dark:bg-gray-900">-->
<!--                                            <template v-for="project in projects['BOM_REQUIRED'].data">-->
<!--                                                <tr v-if="showRow(project)" :class="project.archive ? 'bg-red-50' : ''">-->
<!--                                                    <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">-->
<!--                                                        <div class="inline-flex items-center gap-x-3">-->
<!--                                                            <div class="flex items-center gap-x-2">-->
<!--                                                                <div>-->
<!--                                                                    <h2 class="font-medium text-gray-800 dark:text-white ">{{ project.name }}</h2>-->
<!--                                                                    <small>Ref: {{project.reference}}</small>-->
<!--                                                                </div>-->
<!--                                                            </div>-->
<!--                                                        </div>-->
<!--                                                    </td>-->
<!--                                                    <td class="px-8 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">-->
<!--                                                        <div-->
<!--                                                            :class="project.archive ? 'bg-gray-100/60' : (project.awarded ? 'bg-emerald-100/60' : 'bg-yellow-200/60')"-->
<!--                                                            class="inline-flex items-center px-3 py-1 rounded-full gap-x-2 dark:bg-gray-800"-->
<!--                                                        >-->
<!--                                                        <span-->
<!--                                                            :class="project.archive ? 'bg-gray-500' : (project.awarded ? 'bg-emerald-500' : 'bg-yellow-500')"-->
<!--                                                            class="h-1.5 w-1.5 rounded-full"-->
<!--                                                        ></span>-->

<!--                                                            <h2-->
<!--                                                                :class="project.archive ? 'text-gray-500' : (project.awarded ? 'text-emerald-500' : 'text-yellow-800')"-->
<!--                                                                class="text-sm font-semibold"-->
<!--                                                            >-->
<!--                                                                {{project.awarded ? 'Awarded' : 'Tender'}}-->
<!--                                                            </h2>-->
<!--                                                        </div>-->
<!--                                                    </td>-->
<!--                                                    <td class="px-4 py-4 text-sm whitespace-nowrap">-->
<!--                                                        <div class="flex justify-center items-center gap-x-6">-->
<!--                                                            <p-->
<!--                                                                v-if="project.archive"-->
<!--                                                                class="px-2 py-1 rounded border-2 bg-gray-300 border-gray-500"-->
<!--                                                            >-->
<!--                                                                {{project.hasRawMaterialQuotes ? 'Imported Materials' : 'Import Materials'}}-->
<!--                                                            </p>-->
<!--                                                            <Link-->
<!--                                                                v-else-->
<!--                                                                :href="route('products.store',project.id)"-->
<!--                                                                :class="project.hasRawMaterialQuotes ? 'text-emerald-500 bg-emerald-100 border-emerald-300 hover:bg-emerald-200' : 'text-orange-500 bg-orange-50 border-orange-300 hover:bg-orange-100'"-->
<!--                                                                class="px-2 py-1 rounded border-2 font-semibold"-->
<!--                                                            >-->
<!--                                                                {{project.hasRawMaterialQuotes ? 'Imported Materials' : 'Import Materials'}}-->
<!--                                                            </Link>-->
<!--                                                        </div>-->
<!--                                                    </td>-->
<!--                                                    <td-->
<!--                                                        class="font-bold px-4 py-4 text-sm whitespace-nowrap text-center"-->
<!--                                                        :class="project.percentageOfMaterialsQuoted < 70 ? 'text-orange-500' : ''"-->
<!--                                                    >-->
<!--                                                        {{project.percentageOfMaterialsQuoted}}%-->
<!--                                                    </td>-->
<!--                                                    <td-->
<!--                                                        class="font-bold px-4 py-4 text-sm whitespace-nowrap text-center"-->
<!--                                                        :class="project.percentageOfMaterialsOrdered < 70 ? 'text-orange-500' : ''"-->
<!--                                                    >-->
<!--                                                        {{project.percentageOfMaterialsOrdered}}%-->
<!--                                                    </td>-->
<!--                                                    <td class="px-4 py-4 text-sm whitespace-nowrap">-->
<!--                                                        <div class="flex justify-center items-center gap-x-6">-->
<!--                                                            <button-->
<!--                                                                @click="toggleArchive(project)"-->
<!--                                                                class="text-gray-500 transition-colors duration-200 dark:hover:text-red-500 dark:text-gray-300 hover:text-red-500 focus:outline-none"-->
<!--                                                            >-->
<!--                                                                {{project.archive ? 'Restore' : 'Archive'}}-->
<!--                                                            </button>-->
<!--                                                            <button v-if="!project.archive" @click="editMode(project)">-->
<!--                                                                Edit-->
<!--                                                            </button>-->
<!--                                                        </div>-->
<!--                                                    </td>-->
<!--                                                </tr>-->
<!--                                            </template>-->
<!--                                        </tbody>-->
<!--                                    </table>-->
<!--                                </div>-->
<!--                                <div-->
<!--                                    v-if="countArchivedProjects > 0"-->
<!--                                    @click="showArchivedProjects = !showArchivedProjects"-->
<!--                                    class="text-center text-blue-500 underline mt-3"-->
<!--                                >-->
<!--                                    {{showArchivedProjects ? 'Hide' : 'Show'}} {{countArchivedProjects}} Archived Project{{countArchivedProjects > 1 ? 's' : ''}}-->
<!--                                </div>-->
<!--                            </div>-->
<!--                        </div>-->
<!--                    </div>-->
<!--                </section>-->
            </div>
        </div>
    </AuthenticatedLayout>

    <!-- Modal -->
    <KanbanModal
        :showModal="showModal"
        :modalData="modalData"
        @closeModal="showModal = false"
    />
</template>
