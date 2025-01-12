<script setup>
    //General Imports
    import {Link, Head, useForm, usePage} from '@inertiajs/vue3';
    import {ref} from "vue";
    import moment from "moment";

    //Component Imports
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

    //Props
    const props = defineProps({
        suppliers: Object,
        byCategory: Object,
        business: Object,
    });

    //Form
    const formSupplierCreate = useForm({
        name: null,
        supplier_categories: setupCategoriesForm(),
    });
    const formSupplierDelete = useForm({});

    //Shared data
    const isAdmin = usePage().props.auth.isAdmin;

    //Variables
    const editSupplier = ref(null);
    const autoSuggestions = ref([]);
    const autoSuggestionsExactMatch = ref(false);

    //Methods
    function setupCategoriesForm(){
        let keys = Object.keys(props.byCategory);
        let array = {};

        console.log(keys);
        keys.forEach(key => {
            array[key] = false;
        });

        return array;
    }

    function submit(){
        //Edit mode
        if(editSupplier.value){
            let url = route("suppliers.update",[editSupplier.value.id,props.business.id]);
            formSupplierCreate.put(url, {
                preserveScroll: true,
                onSuccess: () => {
                    console.log('success');
                    formSupplierCreate.reset();
                    editSupplier.value = null;
                },
                onError: errors => {
                    console.log('errors',errors);
                },
            });
        }
        //Create mode
        else{
            let url = route("suppliers.store",props.business.id);
            formSupplierCreate.post(url, {
                preserveScroll: true,
                onSuccess: () => {
                    console.log('success');
                    formSupplierCreate.reset();
                },
                onError: errors => {
                    console.log('errors',errors);
                },
            });
        }
    }
    function submitDelete(id){
        let url = route("suppliers.destroy",id);
        formSupplierDelete.delete(url, {
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
        const userConfirmed = confirm("Are you sure you want to remove this supplier?");
        if (userConfirmed) {
            // User clicked "OK"
            submitDelete(id);
        }
    }

    function editMode(supplier){
        editSupplier.value = supplier;

        //Populate form
        formSupplierCreate.name = supplier.name;
        formSupplierCreate.supplier_categories = supplier.categoriesForm;
    }

    function autoComplete(){
        //Over 3 characters
        if(formSupplierCreate.name.length >= 3){
            autoSuggestions.value = [];
            autoSuggestionsExactMatch.value = false;
            Object.values(props.suppliers.data).forEach(supplier => {
                //Partial match
                if(supplier.name.toLowerCase().includes(formSupplierCreate.name.toLowerCase())){
                    autoSuggestions.value.push([
                        supplier.name,
                        supplier.id,
                    ]);
                }
                //Exact match
                if(supplier.name.toLowerCase() === formSupplierCreate.name.toLowerCase()){
                    autoSuggestions.value = [];
                    autoSuggestionsExactMatch.value = true;
                }
            });
        }
        else{
            autoSuggestions.value = [];
        }
    }

    function showDeleteButton(supplier){
        //Case #1 (admin and supplier is not used)
        let case1 = isAdmin && !supplier.isUsed;

        //Case #2 (user)
        let case2 = !isAdmin;

        return case1 || case2;
    }
</script>

<template>
    <Head title="Suppliers" />

    <AuthenticatedLayout>
        <div class="py-12">
            <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
                <section
                    class="dark:bg-gray-900 rounded-xl"
                    :class="editSupplier ? 'bg-yellow-50' : 'bg-white'"
                >
                    <div class="px-6 pt-8 pb-8 mx-auto text-center">
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100">
                            {{editSupplier ? ('Edit ' + editSupplier.name) : 'Add Supplier'}} <span v-if="isAdmin">for {{business.domain}}</span>
                        </h1>
                        <p
                            v-if="editSupplier"
                            @click="editSupplier = null; formSupplierCreate.reset();"
                            class="text-blue-500 text-sm underline mt-2"
                            style="cursor: pointer;"
                        >
                            Back to New Supplier
                        </p>
                        <div class="mt-8 space-y-2 sm:space-y-0 sm:flex-row sm:justify-center">
                            <form @submit.prevent="submit()">
                                <div class="grid grid-cols-6 gap-x-2">
                                    <!-- Name -->
                                    <div class="col-span-2">
                                        <!-- input -->
                                        <input
                                            v-model="formSupplierCreate.name"
                                            type="text"
                                            class="w-full px-4 py-2 text-gray-700 bg-white border rounded-md dark:bg-gray-900 dark:text-gray-300 dark:border-gray-600 focus:border-blue-400 dark:focus:border-blue-300 focus:outline-none focus:ring focus:ring-blue-300 focus:ring-opacity-40"
                                            placeholder="Name"
                                            required
                                            @input="autoComplete()"
                                        >
                                        <div
                                            v-if="formSupplierCreate.errors.name"
                                            class="text-red-500 text-sm"
                                        >
                                            {{ formSupplierCreate.errors.name }}
                                        </div>
                                    </div>

                                    <!-- categories -->
                                    <div class="col-span-3">
                                        <div class="grid grid-cols-2">
                                            <div v-for="(data,label) in byCategory" class="flex gap-x-2">
                                                <input
                                                    v-model="formSupplierCreate.supplier_categories[label]"
                                                    :id="label"
                                                    type="checkbox"
                                                    class="mt-1"
                                                />
                                                <label :for="label">{{label}}</label>
                                            </div>
                                        </div>
                                        <div
                                            v-if="formSupplierCreate.errors.supplier_categories"
                                            class="text-red-500 text-sm"
                                        >
                                            {{ formSupplierCreate.errors.supplier_categories }}
                                        </div>
                                    </div>


                                    <!-- submit -->
                                    <button
                                        type="submit"
                                        :disabled="formSupplierCreate.processing || autoSuggestionsExactMatch"
                                        style="height:40px"
                                        class="px-4 py-2 text-sm font-medium tracking-wide text-white capitalize transition-colors duration-300 transform bg-blue-700 rounded-md hover:bg-blue-600 focus:outline-none focus:bg-blue-600"
                                    >
                                        {{editSupplier ? 'Update' : 'Create'}}
                                    </button>
                                </div>

                                <!-- auto suggestions-->
                                <div>
                                    <p v-if="autoSuggestionsExactMatch" class="mt-2 text-orange-500 text-left">
                                        Supplier exists
                                    </p>

                                    <p class="mt-3">
                                        <ul>
                                            <li
                                                v-for="suggestion in autoSuggestions"
                                                style="cursor: pointer;"
                                                class="text-sm text-left"
                                            >
                                                {{ suggestion[0] }}
                                            </li>
                                        </ul>
                                    </p>
                                </div>
                            </form>
                        </div>
                    </div>
                </section>

                <section class="bg-white dark:bg-gray-900 rounded-xl mt-5">
                    <div class="px-6 pt-8 pb-8 mx-auto">
                        <div class="grid grid-cols-2 gap-x-5">
                            <!-- by category -->
                            <div>
                                <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-4">
                                    By Category
                                </h1>
                                <div v-for="(data,label) in byCategory" class="mb-4">
                                    <div>
                                        <h3 class="font-semibold">{{label}}</h3>
                                        <small class="text-gray-500">{{data.includedProductsString}}</small>
                                    </div>
                                    <div class="grid grid-cols-3">
                                        <div v-for="supplier in data.suppliersArray">
                                            {{supplier}}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- by supplier -->
                            <div>
                                <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-4">
                                    By Supplier
                                </h1>
                                <div v-for="supplier in suppliers.data" class="mb-3 flex gap-x-3">
                                    <button
                                        v-if="showDeleteButton(supplier)"
                                        class="text-red-500 font-extrabold"
                                        @click="deleteConfirmation(supplier.id)"
                                    >
                                        <i class="fa-regular fa-circle-xmark"></i>
                                    </button>
                                    <button @click="editMode(supplier)">
                                        <i class="fa-regular fa-pen-to-square"></i>
                                    </button>
                                    <div>
                                        <span class="block">{{supplier.name}}</span>
                                        <span class="block text-xs">{{supplier.categoriesAsCommaString}}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
