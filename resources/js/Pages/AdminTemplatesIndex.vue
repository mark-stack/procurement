<script setup>
    //General Imports
    import { Head, useForm } from '@inertiajs/vue3';
    import { ref } from "vue";

    //Component Imports
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
    import InputLabel from "@/Components/InputLabel.vue";
    import InputError from "@/Components/InputError.vue";
    import Checkbox from "@/Components/Checkbox.vue";
    import ConfirmModal from "@/Components/Modals/ConfirmModal.vue";
    import useConfirm from "@/Shared/useConfirm.js";

    //Props
    const props = defineProps({
        templates: Array,
        business: Object,
    });

    //Form
    /*
     * "screenshot" used to default to a 140KB base64 PNG pasted inline here. It shipped
     * in the bundle and, because the old rule was only "min:50", submitting without
     * touching the field silently stored that placeholder as the real screenshot.
     */
    const blankTemplate = {
        name: null,
        first_description_cell: null,
        first_material_cell: null,
        first_length_required_cell: null,
        first_width_required_cell: null,
        first_sub_qty_cell: null,
        screenshot: null,
        length_width_units: "m",
        active: false,
    };
    const formTemplate = useForm({ ...blankTemplate });
    const formTemplateDelete = useForm({});

    //Variables
    const editId = ref(null);

    //Shared Methods
    const {confirmDialog, askToConfirm, confirmDialogAccepted, confirmDialogCancelled} = useConfirm();

    //Methods
    function submit(){
        //Edit mode
        if(editId.value){
            submitUpdate();
        }
        //Create mode
        else{
            submitStore();
        }
    }
    function submitStore(){
        let url = route("admin.businesses.templates.store",props.business.id);
        formTemplate.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                resetForm();
            },
        });
    }
    function submitDelete(id){
        askToConfirm({
            title: "Delete this template?",
            message: "This removes it from the record of templates held for this business. Importing is driven by TableTemplates.php, so detection is unaffected.",
            confirmLabel: "Delete template",
            tone: "danger",
            onConfirmed: () => {
                let url = route("admin.businesses.templates.destroy",[props.business.id,id]);
                formTemplateDelete.delete(url, {
                    preserveScroll: true,
                });
            },
        });
    }
    function submitUpdate(){
        let url = route("admin.businesses.templates.update",[props.business.id,editId.value]);
        formTemplate.put(url, {
            preserveScroll: true,
            onSuccess: () => {
                resetForm();
            },
        });
    }
    function initiateUpdate(template){
        editId.value = template.id;

        /*
         * Every editable field, "active" included. Leaving "active" out meant it fell
         * back to the form default of false, so saving any edit deactivated the template.
         */
        formTemplate.name = template.name;
        formTemplate.first_description_cell = template.first_description_cell;
        formTemplate.first_material_cell = template.first_material_cell;
        formTemplate.first_length_required_cell = template.first_length_required_cell;
        formTemplate.first_width_required_cell = template.first_width_required_cell;
        formTemplate.first_sub_qty_cell = template.first_sub_qty_cell;
        formTemplate.screenshot = template.screenshot;
        formTemplate.length_width_units = template.length_width_units;
        formTemplate.active = template.active;

        formTemplate.clearErrors();
    }
    function resetForm(){
        //defaults() first, so reset() does not restore a previously edited template
        formTemplate.defaults({ ...blankTemplate });
        formTemplate.reset();
        formTemplate.clearErrors();
        editId.value = null;
    }
</script>

<template>
    <Head title="Templates" />

    <AuthenticatedLayout>
        <div class="py-12">
            <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
                <section
                    :class="editId ? 'bg-yellow-50 dark:bg-yellow-900/20' : 'bg-white dark:bg-gray-900'"
                >
                    <div class="px-6 py-16 mx-auto text-center">
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100">
                            {{editId ? 'Edit' : 'Record'}} Template for {{business.domain}}
                        </h1>
                        <p
                            v-if="editId"
                            class="max-w-md mx-auto mt-5 text-blue-500 underline cursor-pointer"
                            @click="resetForm()"
                        >
                            Back to Record
                        </p>
                        <p v-else class="max-w-md mx-auto mt-5 text-gray-500 dark:text-gray-400">
                            Keep a record of the Excel templates this business imports with.
                        </p>

                        <!--
                            Auto-detection is driven by config/TableTemplates.php, not by these
                            rows. Nothing here changes what a drag & drop will detect.
                        -->
                        <p class="max-w-xl mx-auto mt-3 text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-4 py-3 dark:bg-amber-900/20 dark:text-amber-300 dark:border-amber-800">
                            <strong>Reference only.</strong>
                            Import auto-detection is configured in <code>config/TableTemplates.php</code>.
                            Adding a row here records that a template exists &mdash; it does not make
                            it detect. That still needs a code change.
                        </p>

                        <div class="flex flex-col mt-4 space-y-3 sm:space-y-0 sm:flex-row sm:justify-center">
                            <form @submit.prevent="submit()">
                                <div>
                                    <div class="grid grid-cols-3 gap-3 mt-4">
                                        <!-- name -->
                                        <div class="col-span-1">
                                            <InputLabel value="Name*"/>
                                            <input
                                                v-model="formTemplate.name"
                                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                type="text"
                                                placeholder="Name"
                                                maxlength="255"
                                            />
                                            <InputError :message="formTemplate.errors.name"/>
                                        </div>

                                        <!-- first_description_cell -->
                                        <div>
                                            <InputLabel value="First description cell*"/>
                                            <input
                                                v-model="formTemplate.first_description_cell"
                                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                type="text"
                                                placeholder="e.g B7"
                                                maxlength="7"
                                            />
                                            <InputError :message="formTemplate.errors.first_description_cell"/>
                                        </div>

                                        <!-- first_material_cell -->
                                        <div>
                                            <InputLabel value="First material cell"/>
                                            <input
                                                v-model="formTemplate.first_material_cell"
                                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                type="text"
                                                placeholder="e.g B7"
                                                maxlength="7"
                                            />
                                            <InputError :message="formTemplate.errors.first_material_cell"/>
                                        </div>

                                        <!-- first_length_required_cell -->
                                        <!-- Optional: templates such as the Tekla reports have no width column -->
                                        <div>
                                            <InputLabel value="First length required cell"/>
                                            <input
                                                v-model="formTemplate.first_length_required_cell"
                                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                type="text"
                                                placeholder="e.g B9"
                                                maxlength="7"
                                            />
                                            <InputError :message="formTemplate.errors.first_length_required_cell"/>
                                        </div>

                                        <!-- first_width_required_cell -->
                                        <div>
                                            <InputLabel value="First width required cell"/>
                                            <input
                                                v-model="formTemplate.first_width_required_cell"
                                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                type="text"
                                                placeholder="e.g B9"
                                                maxlength="7"
                                            />
                                            <InputError :message="formTemplate.errors.first_width_required_cell"/>
                                        </div>

                                        <!-- first_sub_qty_cell -->
                                        <div>
                                            <InputLabel value="First sub qty cell*"/>
                                            <input
                                                v-model="formTemplate.first_sub_qty_cell"
                                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                type="text"
                                                placeholder="e.g B9"
                                                maxlength="7"
                                            />
                                            <InputError :message="formTemplate.errors.first_sub_qty_cell"/>
                                        </div>

                                        <!-- screenshot -->
                                        <div class="col-span-2">
                                            <InputLabel value="Screenshot (paste base64 string in 800x500px)*"/>
                                            <input
                                                v-model="formTemplate.screenshot"
                                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                type="text"
                                                placeholder="data:image/png;base64,..."
                                            />
                                            <InputError :message="formTemplate.errors.screenshot"/>
                                            <small>
                                                Use this link to convert: <a target="_blank" class="underline text-blue-500" href="https://codepen.io/GapRay/pen/MGjWqY">Codepen</a>
                                            </small>
                                        </div>

                                        <!-- length_width_units -->
                                        <div>
                                            <InputLabel value="Length/width units*"/>
                                            <select
                                                v-model="formTemplate.length_width_units"
                                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                            >
                                                <option value="m">Meters (m)</option>
                                                <option value="mm">Millimeters (mm)</option>
                                            </select>
                                            <InputError :message="formTemplate.errors.length_width_units"/>
                                        </div>

                                        <!-- active -->
                                        <!-- There was no input for this at all, so nothing could ever be activated -->
                                        <div class="col-span-3 flex items-center gap-x-2">
                                            <Checkbox
                                                :checked="formTemplate.active"
                                                @update:checked="formTemplate.active = $event"
                                            />
                                            <InputLabel value="Active"/>
                                            <InputError :message="formTemplate.errors.active"/>
                                        </div>
                                    </div>


                                    <!-- submit -->
                                    <button
                                        type="submit"
                                        :disabled="formTemplate.processing"
                                        class="w-full mt-3 px-4 py-2 text-sm font-medium tracking-wide text-white capitalize transition-colors duration-300 transform bg-blue-700 rounded-md hover:bg-blue-600 focus:outline-none focus:bg-blue-600 disabled:opacity-50"
                                    >
                                        <span v-if="editId">Updat{{formTemplate.processing ? 'ing...' : 'e'}}</span>
                                        <span v-else>Creat{{formTemplate.processing ? 'ing...' : 'e'}}</span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </section>


                <section class="container mx-auto mt-5">
                    <div class="flex items-center gap-x-3">
                        <h2 class="text-lg font-medium text-gray-800 dark:text-white">Templates</h2>

                        <span class="px-3 py-1 text-xs text-blue-600 bg-blue-100 rounded-full dark:bg-gray-800 dark:text-blue-400">{{templates.length}} templates</span>
                    </div>

                    <div class="flex flex-col mt-6">
                        <div class="-mx-4 -my-2 overflow-x-auto sm:-mx-6 lg:-mx-8">
                            <div class="inline-block min-w-full py-2 align-middle md:px-6 lg:px-8">
                                <div class="overflow-hidden border border-gray-200 dark:border-gray-700 md:rounded-lg">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead class="bg-gray-50 dark:bg-gray-800">
                                            <tr>
                                                <th scope="col" class="py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                    Name
                                                </th>

                                                <th scope="col" class="px-12 py-3.5 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                    Active
                                                </th>


                                                <th scope="col" class="relative py-3.5 px-4">
                                                    <span class="sr-only">Edit</span>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200 dark:divide-gray-700 dark:bg-gray-900">
                                            <tr v-for="template in templates" :key="template.id">
                                                <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                    <div class="flex items-center gap-x-3">
                                                        <!-- The screenshot is how an admin recognises a template, so show it at a readable size -->
                                                        <img class="object-cover object-left-top w-32 h-20 rounded border border-gray-200 dark:border-gray-700"
                                                             :src="template.screenshot"
                                                             :alt="template.name">
                                                        <h2 class="font-medium text-gray-800 dark:text-white">
                                                            {{ template.name }}
                                                        </h2>
                                                    </div>
                                                </td>
                                                <td class="px-12 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                    <div
                                                        :class="template.active ? 'bg-emerald-100/60' : 'bg-yellow-100/60'"
                                                        class="inline-flex items-center px-3 py-1 rounded-full gap-x-2 dark:bg-gray-800"
                                                    >
                                                        <span
                                                            :class="template.active ? 'bg-emerald-500' : 'bg-yellow-500'"
                                                            class="h-1.5 w-1.5 rounded-full"
                                                        ></span>

                                                        <h2
                                                            :class="template.active ? 'text-emerald-500' : 'text-yellow-500'"
                                                            class="text-sm font-normal "
                                                        >
                                                            {{template.active ? 'Yes' : 'No'}}
                                                        </h2>
                                                    </div>
                                                </td>
                                                <td class="px-4 py-4 text-sm whitespace-nowrap">
                                                    <div class="flex items-center gap-x-6">
                                                        <button
                                                            type="button"
                                                            @click="submitDelete(template.id)"
                                                            class="text-gray-500 transition-colors duration-200 dark:hover:text-red-500 dark:text-gray-300 hover:text-red-500 focus:outline-none"
                                                        >
                                                            <span class="sr-only">Delete template</span>
                                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                            </svg>
                                                        </button>

                                                        <button
                                                            type="button"
                                                            @click="initiateUpdate(template)"
                                                            class="text-gray-500 transition-colors duration-200 dark:hover:text-yellow-500 dark:text-gray-300 hover:text-yellow-500 focus:outline-none"
                                                        >
                                                            <span class="sr-only">Edit template</span>
                                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                                            </svg>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                            <tr v-if="templates.length === 0">
                                                <td colspan="3" class="px-4 py-6 text-sm text-center text-gray-500 dark:text-gray-400">
                                                    No templates recorded for this business yet.
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

    <ConfirmModal
        v-if="confirmDialog"
        :title="confirmDialog.title"
        :message="confirmDialog.message"
        :confirmLabel="confirmDialog.confirmLabel"
        :tone="confirmDialog.tone"
        @confirm="confirmDialogAccepted()"
        @cancel="confirmDialogCancelled()"
    />
</template>
