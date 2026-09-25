<script setup>
    //General Imports
    import {Link, Head, useForm, usePage} from '@inertiajs/vue3';
    import {computed, nextTick, ref} from "vue";

    //Component Imports
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
    import ConfirmModal from "@/Components/Modals/ConfirmModal.vue";
    import useConfirm from "@/Shared/useConfirm.js";

    //Props
    const props = defineProps({
        suppliers: Object,
        byCategory: Object,
        business: Object,
        //True on /admin/suppliers/{business} - an admin looking at someone
        //else's suppliers, where the business cannot come from the session
        adminView: Boolean,
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
    const nameInput = ref(null);

    //Computed
    //Counts for the badge in each panel header
    const categoryCount = computed(() => Object.keys(props.byCategory).length);
    const supplierCount = computed(() => props.suppliers.data.length);
    //Categories with nobody to quote them - the reason to be on this page
    const uncoveredCount = computed(() =>
        Object.values(props.byCategory).filter(data => data.suppliersArray.length === 0).length
    );

    //Shared Methods
    const {confirmDialog, askToConfirm, confirmDialogAccepted, confirmDialogCancelled} = useConfirm();

    //Methods
    function setupCategoriesForm(){
        let keys = Object.keys(props.byCategory);
        let array = {};

        keys.forEach(key => {
            array[key] = false;
        });

        return array;
    }

    function submit(){
        //Edit mode
        if(editSupplier.value){
            let url = route("suppliers.update",editSupplier.value.id);
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
            let url = props.adminView
                ? route("admin.suppliers.store",props.business.id)
                : route("suppliers.store");
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

    function deleteConfirmation(supplier) {
        askToConfirm({
            title: "Remove this supplier?",
            message: `“${supplier.name}” will no longer be available to quote against.`,
            confirmLabel: "Remove supplier",
            tone: "danger",
            onConfirmed: () => submitDelete(supplier.id),
        });
    }

    function editMode(supplier){
        editSupplier.value = supplier;

        //Populate form
        formSupplierCreate.name = supplier.name;
        formSupplierCreate.supplier_categories = supplier.categoriesForm;

        focusName();
    }

    function cancelEdit(){
        editSupplier.value = null;
        formSupplierCreate.reset();

        autoSuggestions.value = [];
        autoSuggestionsExactMatch.value = false;
    }

    //The header action has nowhere to navigate to - the form is on this page,
    //so it puts the cursor in it rather than opening anything
    function focusName(){
        nextTick(() => nameInput.value?.focus());
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

    function categoryChips(supplier){
        return supplier.categoriesAsCommaString
            ? supplier.categoriesAsCommaString.split(',').filter(Boolean)
            : [];
    }
</script>

<template>
    <Head title="Suppliers" />

    <AuthenticatedLayout>
        <section class="mx-auto w-full max-w-6xl pb-10">

            <!-- back to the board -->
            <div class="pt-5">
                <Link
                    :href="route('projects.index')"
                    class="inline-flex items-center gap-1.5 rounded-lg py-1 text-xs font-semibold text-gray-500 transition-colors duration-150 hover:text-blue-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
                >
                    <i class="fa-solid fa-chevron-left text-[10px]"></i>
                    Projects
                </Link>
            </div>

            <!-- page header -->
            <header class="flex flex-wrap items-end justify-between gap-4 py-5">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-gray-900">
                        Suppliers
                    </h1>
                    <p class="mt-1 text-sm text-gray-500">
                        <span v-if="isAdmin">Suppliers for {{business.domain}}. </span>
                        Every category needs at least one supplier before it can be quoted.
                    </p>
                </div>
                <button
                    type="button"
                    @click="cancelEdit(); focusName();"
                    class="inline-flex items-center gap-2 rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors duration-150 hover:bg-blue-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
                >
                    <i class="fa-solid fa-plus text-xs"></i>
                    Add supplier
                </button>
            </header>

            <!-- uncovered categories warning -->
            <div
                v-if="uncoveredCount > 0"
                class="mb-4 flex gap-2 rounded-xl border border-orange-200 bg-orange-50 p-3 text-xs leading-relaxed text-orange-800"
            >
                <i class="fa-solid fa-triangle-exclamation mt-0.5 flex-none text-orange-500"></i>
                <span>
                    {{ uncoveredCount }} categor{{ uncoveredCount === 1 ? 'y has' : 'ies have' }} no supplier yet.
                    Nothing in {{ uncoveredCount === 1 ? 'it' : 'them' }} can be quoted until you add one.
                </span>
            </div>

            <!-- add / edit supplier -->
            <section
                class="overflow-hidden rounded-xl border bg-white shadow-sm"
                :class="editSupplier ? 'border-orange-200' : 'border-gray-200'"
            >
                <!-- panel header -->
                <header
                    class="flex flex-wrap items-center justify-between gap-2 border-b px-3 py-3"
                    :class="editSupplier ? 'border-orange-200 bg-orange-50' : 'border-gray-200 bg-gray-50'"
                >
                    <div class="flex min-w-0 items-center gap-2">
                        <span
                            class="flex h-6 w-6 flex-none items-center justify-center rounded-full text-[11px] ring-1 ring-inset"
                            :class="editSupplier
                                ? 'bg-white text-orange-700 ring-orange-200'
                                : 'bg-blue-50 text-blue-800 ring-blue-100'"
                        >
                            <i :class="editSupplier ? 'fa-regular fa-pen-to-square' : 'fa-solid fa-plus'" class="text-[10px]"></i>
                        </span>
                        <h2 class="truncate text-sm font-semibold uppercase tracking-wide text-gray-700">
                            {{ editSupplier ? ('Editing ' + editSupplier.name) : 'Add a supplier' }}
                        </h2>
                    </div>
                    <button
                        v-if="editSupplier"
                        type="button"
                        @click="cancelEdit()"
                        class="rounded-lg text-xs font-semibold text-orange-800 transition-colors duration-150 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-400 focus-visible:ring-offset-1"
                    >
                        Cancel edit
                    </button>
                </header>

                <!-- panel body -->
                <form @submit.prevent="submit()" class="p-4">
                    <div class="grid gap-4 md:grid-cols-12">
                        <!-- Name -->
                        <div class="md:col-span-4">
                            <label for="supplier-name" class="block text-xs font-semibold uppercase tracking-wide text-gray-500">
                                Business name
                            </label>
                            <input
                                id="supplier-name"
                                ref="nameInput"
                                v-model="formSupplierCreate.name"
                                type="text"
                                class="mt-1.5 block h-10 w-full rounded-lg border-gray-300 text-sm text-gray-900 shadow-sm transition-colors duration-150 placeholder:text-gray-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/30"
                                placeholder="e.g. Southern Steel"
                                required
                                @input="autoComplete()"
                            >
                            <p v-if="formSupplierCreate.errors.name" class="mt-1.5 text-xs font-medium text-red-600">
                                {{ formSupplierCreate.errors.name }}
                            </p>
                        </div>

                        <!-- categories -->
                        <div class="md:col-span-6">
                            <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500">
                                Categories they supply
                            </span>
                            <div class="mt-1.5 grid gap-x-4 gap-y-1.5 sm:grid-cols-2">
                                <div v-for="(data,label) in byCategory" :key="label" class="flex items-start gap-2">
                                    <input
                                        v-model="formSupplierCreate.supplier_categories[label]"
                                        :id="label"
                                        type="checkbox"
                                        class="mt-0.5 h-4 w-4 flex-none rounded border-gray-300 text-blue-700 focus:ring-2 focus:ring-blue-500/30"
                                    />
                                    <label :for="label" class="text-sm leading-tight text-gray-700">
                                        {{label}}
                                    </label>
                                </div>
                            </div>
                            <p v-if="formSupplierCreate.errors.supplier_categories" class="mt-1.5 text-xs font-medium text-red-600">
                                {{ formSupplierCreate.errors.supplier_categories }}
                            </p>
                        </div>

                        <!-- submit -->
                        <div class="flex items-end md:col-span-2">
                            <button
                                type="submit"
                                :disabled="formSupplierCreate.processing || autoSuggestionsExactMatch"
                                class="inline-flex h-10 w-full items-center justify-center gap-2 rounded-lg px-4 text-sm font-semibold shadow-sm transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2"
                                :class="(formSupplierCreate.processing || autoSuggestionsExactMatch)
                                    ? 'cursor-not-allowed border border-gray-200 bg-gray-50 text-gray-400 shadow-none'
                                    : 'bg-blue-700 text-white hover:bg-blue-800 focus-visible:ring-blue-500'"
                            >
                                {{ editSupplier ? 'Update' : 'Create' }}
                            </button>
                        </div>
                    </div>

                    <!-- auto suggestions -->
                    <div v-if="autoSuggestionsExactMatch || autoSuggestions.length > 0" class="mt-3">
                        <p
                            v-if="autoSuggestionsExactMatch"
                            class="flex gap-2 rounded-lg border border-orange-200 bg-orange-50 p-2.5 text-xs leading-relaxed text-orange-800"
                        >
                            <i class="fa-solid fa-triangle-exclamation mt-0.5 flex-none text-orange-500"></i>
                            <span>That supplier already exists.</span>
                        </p>
                        <div v-else>
                            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                Already added
                            </span>
                            <ul class="mt-1.5 flex flex-wrap gap-1.5">
                                <li
                                    v-for="suggestion in autoSuggestions"
                                    :key="suggestion[1]"
                                    class="rounded-md bg-gray-100 px-2 py-1 text-[11px] font-medium text-gray-600 ring-1 ring-inset ring-gray-200"
                                >
                                    {{ suggestion[0] }}
                                </li>
                            </ul>
                        </div>
                    </div>
                </form>
            </section>

            <!-- the two lists -->
            <div class="mt-4 grid gap-4 lg:grid-cols-2">

                <!-- by category -->
                <section class="flex flex-col overflow-hidden rounded-xl border border-gray-200 bg-gray-50 shadow-sm">
                    <header class="flex flex-none items-center justify-between gap-2 border-b border-gray-200 bg-white px-3 py-3">
                        <div class="flex min-w-0 items-center gap-2">
                            <span class="flex h-6 w-6 flex-none items-center justify-center rounded-full bg-blue-50 text-[11px] text-blue-800 ring-1 ring-inset ring-blue-100">
                                <i class="fa-solid fa-layer-group text-[10px]"></i>
                            </span>
                            <h2 class="truncate text-sm font-semibold uppercase tracking-wide text-gray-700">
                                By category
                            </h2>
                        </div>
                        <span
                            class="flex-none rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums"
                            :class="categoryCount > 0 ? 'bg-gray-200 text-gray-700' : 'bg-gray-100 text-gray-400'"
                        >
                            {{ categoryCount }}
                        </span>
                    </header>

                    <div class="space-y-3 p-3">
                        <div
                            v-for="(data,label) in byCategory"
                            :key="label"
                            class="rounded-xl border border-gray-200 bg-white p-3 transition-colors duration-150 hover:border-gray-300"
                        >
                            <h3 class="text-sm font-semibold text-gray-900">{{label}}</h3>
                            <p class="mt-0.5 text-xs leading-relaxed text-gray-500">
                                {{data.includedProductsString}}
                            </p>

                            <!-- suppliers covering it -->
                            <ul v-if="data.suppliersArray.length > 0" class="mt-2.5 flex flex-wrap gap-1.5">
                                <li
                                    v-for="supplier in data.suppliersArray"
                                    :key="supplier"
                                    class="rounded-md bg-gray-100 px-2 py-1 text-[11px] font-medium text-gray-600 ring-1 ring-inset ring-gray-200"
                                >
                                    {{supplier}}
                                </li>
                            </ul>
                            <p
                                v-else
                                class="mt-2.5 flex gap-2 rounded-lg border border-orange-200 bg-orange-50 p-2.5 text-xs leading-relaxed text-orange-800"
                            >
                                <i class="fa-solid fa-triangle-exclamation mt-0.5 flex-none text-orange-500"></i>
                                <span>Need to add suppliers</span>
                            </p>
                        </div>
                    </div>
                </section>

                <!-- by supplier -->
                <section class="flex flex-col overflow-hidden rounded-xl border border-gray-200 bg-gray-50 shadow-sm">
                    <header class="flex flex-none items-center justify-between gap-2 border-b border-gray-200 bg-white px-3 py-3">
                        <div class="flex min-w-0 items-center gap-2">
                            <span class="flex h-6 w-6 flex-none items-center justify-center rounded-full bg-blue-50 text-[11px] text-blue-800 ring-1 ring-inset ring-blue-100">
                                <i class="fa-solid fa-cubes text-[10px]"></i>
                            </span>
                            <h2 class="truncate text-sm font-semibold uppercase tracking-wide text-gray-700">
                                By supplier
                            </h2>
                        </div>
                        <span
                            class="flex-none rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums"
                            :class="supplierCount > 0 ? 'bg-gray-200 text-gray-700' : 'bg-gray-100 text-gray-400'"
                        >
                            {{ supplierCount }}
                        </span>
                    </header>

                    <div class="space-y-3 p-3">
                        <div
                            v-for="supplier in suppliers.data"
                            :key="supplier.id"
                            class="group flex items-start justify-between gap-3 rounded-xl border border-gray-200 bg-white p-3 transition-colors duration-150 hover:border-gray-300"
                        >
                            <div class="min-w-0">
                                <h3 class="truncate text-sm font-semibold text-gray-900" :title="supplier.name">
                                    {{supplier.name}}
                                </h3>
                                <ul v-if="categoryChips(supplier).length > 0" class="mt-1.5 flex flex-wrap gap-1.5">
                                    <li
                                        v-for="category in categoryChips(supplier)"
                                        :key="category"
                                        class="rounded-md bg-gray-100 px-2 py-1 text-[11px] font-medium text-gray-600 ring-1 ring-inset ring-gray-200"
                                    >
                                        {{category}}
                                    </li>
                                </ul>
                                <p v-else class="mt-1 text-xs text-gray-400">
                                    No categories
                                </p>
                            </div>

                            <div class="flex flex-none gap-1.5">
                                <button
                                    type="button"
                                    @click="editMode(supplier)"
                                    :title="'Edit ' + supplier.name"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-gray-300 bg-white text-xs text-gray-600 shadow-sm transition-colors duration-150 hover:border-orange-200 hover:bg-orange-50 hover:text-orange-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-400 focus-visible:ring-offset-1"
                                >
                                    <i class="fa-regular fa-pen-to-square"></i>
                                </button>
                                <button
                                    v-if="showDeleteButton(supplier)"
                                    type="button"
                                    @click="deleteConfirmation(supplier)"
                                    :title="'Remove ' + supplier.name"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-gray-300 bg-white text-xs text-gray-600 shadow-sm transition-colors duration-150 hover:border-red-200 hover:bg-red-50 hover:text-red-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-400 focus-visible:ring-offset-1"
                                >
                                    <i class="fa-regular fa-circle-xmark"></i>
                                </button>
                            </div>
                        </div>

                        <!-- nothing added yet -->
                        <div
                            v-if="supplierCount === 0"
                            class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 bg-white/60 px-4 py-8 text-center"
                        >
                            <i class="fa-solid fa-cubes text-xl text-gray-300"></i>
                            <p class="mt-3 text-xs leading-relaxed text-gray-500">
                                No suppliers yet. Add one above and it will appear here.
                            </p>
                        </div>
                    </div>
                </section>
            </div>
        </section>
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
