<script setup>
    //General Imports
    import {useForm} from "@inertiajs/vue3";

    //Component Imports
    import Modal from "@/Layouts/Modal.vue";
    import {ref, toRefs, watch} from "vue";

    //Props
    const props = defineProps({
        width: String,
        editProject: Object,
    });

    //Forms
    const formProjectCreate = useForm({
        name: null,
        awarded: true,
        reference: null,
        date_materials_required: null,
        tentative: false,
    });

    //Shared data
    //

    //Variables
    const emit = defineEmits(['closeModalOnSuccess']);

    //Shared Methods
    //

    //Methods
    function submit(){
        //Edit mode
        if(props.editProject){
            let url = route("projects.update",props.editProject.id);
            formProjectCreate.put(url, {
                preserveScroll: true,
                onSuccess: () => {
                    formProjectCreate.reset();

                    //Close modal
                    emit('closeModalOnSuccess');
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
                    formProjectCreate.reset();

                    //Close modal
                    emit('closeModalOnSuccess');
                },
                onError: errors => {
                    console.log('errors',errors);
                },
            });
        }
    }

    function editMode(){
        let project = props.editProject;

        //Populate form
        formProjectCreate.name = project.name;
        formProjectCreate.awarded = project.awarded === 1;
        formProjectCreate.date_materials_required = project.date_materials_required;
        formProjectCreate.reference = project.reference;
        formProjectCreate.tentative = project.tentative;
    }

    function backToNewProject(){
        //Clear the form
        formProjectCreate.reset();

        //todo cancel edit
    }

    //Watcher
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
</script>

<template>
    <Modal :fakeModal="false">
        <div :style="'width:'+width+'px'">

            <div class="dark:bg-gray-900 rounded-xl">
                <div class="px-6 pt-4 pb-4 mx-auto text-center">
                    <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100">
                        {{editProject ? ('Edit "' + formProjectCreate.name + '" ') : 'Add New'}} Project
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

                                <!-- Project reference -->
                                <div v-if="formProjectCreate.awarded">
                                    <label class="text-gray-700 dark:text-gray-200 ml-1">Project reference</label>
                                    <input
                                        v-model="formProjectCreate.reference"
                                        type="text"
                                        class="w-full px-4 py-2 text-gray-700 bg-white border rounded-md dark:bg-gray-900 dark:text-gray-300 dark:border-gray-600 focus:border-blue-400 dark:focus:border-blue-300 focus:outline-none focus:ring focus:ring-blue-300 focus:ring-opacity-40"
                                        placeholder="Reference ID"
                                        required
                                    >
                                    <div v-if="formProjectCreate.errors.reference" class="text-sm text-red-500">{{ formProjectCreate.errors.reference }}</div>
                                </div>

                                <!-- Date materials required -->
                                <div v-if="formProjectCreate.awarded">
                                    <label class="text-gray-700 dark:text-gray-200 ml-1">Materials required by</label>
                                    <input
                                        v-model="formProjectCreate.date_materials_required"
                                        type="date"
                                        class="w-full px-4 py-2 text-gray-700 bg-white border rounded-md dark:bg-gray-900 dark:text-gray-300 dark:border-gray-600 focus:border-blue-400 dark:focus:border-blue-300 focus:outline-none focus:ring focus:ring-blue-300 focus:ring-opacity-40"
                                        required
                                    >
                                    <div v-if="formProjectCreate.errors.date_materials_required" class="text-sm text-red-500">{{ formProjectCreate.errors.date_materials_required }}</div>
                                </div>

                                <!-- submit button -->
                                <div>
                                    <button
                                        type="submit"
                                        :disabled="formProjectCreate.processing"
                                        style="height:40px"
                                        class="w-full px-4 py-2 text-sm font-medium tracking-wide text-white capitalize transition-colors duration-300 transform bg-blue-700 rounded-md hover:bg-blue-600 focus:outline-none focus:bg-blue-600"
                                    >
                                        <span v-if="editProject">Updat{{formProjectCreate.processing ? 'ing...' : 'e'}}</span>
                                        <span v-else>Creat{{formProjectCreate.processing ? 'ing...' : 'e'}}</span>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </Modal>
</template>
