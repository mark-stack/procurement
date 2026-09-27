<script setup>
    //General Imports
    import {Head, Link, router, usePage} from "@inertiajs/vue3";
    import {computed, ref, watch} from "vue";

    //Component Imports
    import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
    import MaterialEditModal from "@/Components/Modals/MaterialEditModal.vue";
    import MaterialImportModal from "@/Components/Modals/MaterialImportModal.vue";
    import ConfirmModal from "@/Components/Modals/ConfirmModal.vue";
    import useConfirm from "@/Shared/useConfirm.js";

    //Props
    const props = defineProps({
        products: Object,
        filters: Object,
        options: Object,
        categoryDefinitions: Object,
        totals: Object,
    });

    //Shared Methods
    const {confirmDialog, askToConfirm, confirmDialogAccepted, confirmDialogCancelled} = useConfirm();

    //Variables
    const search = ref(props.filters.search);
    const category = ref(props.filters.category);
    const status = ref(props.filters.status);

    /**
     * The product open in the modal. `null` closed, `{}` creating from scratch, and a plain object
     * of form values when a locked product is being corrected as a new one.
     */
    const editing = ref(null);
    const prefill = ref(null);
    const importing = ref(false);

    //Computed
    const isEmptyCatalogue = computed(() => props.totals.active === 0 && props.totals.deprecated === 0);

    /*
     * Flashed by the preview endpoint, which writes nothing. It survives a redirect back to this
     * page and nothing else, so a review abandoned halfway leaves no state behind.
     */
    const importPlan = computed(() => usePage().props.flash?.materialsImportPlan ?? null);

    //A preview that came back has something to show, so the modal opens itself rather than
    //leaving the plan sitting behind a closed dialog
    watch(importPlan, (plan) => {
        if(plan){
            importing.value = true;
        }
    });

    //Methods
    function applyFilters(){
        router.get(route("admin.materials.index"), {
            search: search.value,
            category: category.value,
            status: status.value,
        }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    /*
     * Debounced, so typing a section size does not fire a request per keystroke. The category and
     * status selects go straight through - one deliberate click each.
     */
    let searchTimer = null;
    watch(search, () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(applyFilters, 350);
    });
    watch([category, status], applyFilters);

    function edit(product){
        prefill.value = null;
        editing.value = product;
    }

    function create(){
        prefill.value = null;
        editing.value = {};
    }

    function closeModal(){
        editing.value = null;
        prefill.value = null;
    }

    /**
     * The way out of a locked spec. The corrected values are carried into a brand new product -
     * which has no usage, so nothing about it is locked - and the original is left for the admin to
     * deprecate once they are happy with the replacement.
     *
     * Deliberately two steps rather than one: deprecating the original before its replacement exists
     * would leave the catalogue with nothing of that spec in between.
     */
    function correctAsNew(values){
        prefill.value = values;
        editing.value = {};
    }

    function destroy(product){
        askToConfirm({
            title: "Delete this product?",
            message: `${product.label || "Product #" + product.id} is not used by any cut list, nested bar, `
                + `offcut, quote or order, so deleting it loses nothing. This cannot be undone.`,
            confirmLabel: "Delete product",
            tone: "danger",
            onConfirmed: () => router.delete(route("admin.materials.destroy", product.id), {
                preserveScroll: true,
            }),
        });
    }

    function toggleDeprecated(product){
        const turningOff = !product.deprecated;

        askToConfirm({
            title: turningOff ? "Deprecate this product?" : "Restore this product?",
            message: turningOff
                ? `${product.label || "Product #" + product.id} stops being offered for new nests, `
                    + `quotes and orders. Everything already built on it keeps resolving to it.`
                : `${product.label || "Product #" + product.id} becomes available again for new work.`,
            confirmLabel: turningOff ? "Deprecate" : "Restore",
            tone: turningOff ? "danger" : "primary",
            onConfirmed: () => router.patch(route("admin.materials.update", product.id), {
                //Every editable column, because this posts the same form the modal does
                ...formValues(product),
                deprecated: turningOff,
            }, {
                preserveScroll: true,
            }),
        });
    }

    /**
     * The product as the update endpoint expects it. Every editable column is `present`-validated,
     * so a partial body would be rejected for the columns it left out rather than leaving them alone.
     */
    function formValues(product){
        return {
            description: product.description,
            product_category: product.product_category,
            material: product.material,
            grade: product.grade,
            surface: product.surface,
            certificates: product.certificates,
            nominal_length: product.nominal_length,
            precise_length: product.precise_length,
            nominal_width: product.nominal_width,
            precise_width: product.precise_width,
            nominal_height: product.nominal_height,
            precise_height: product.precise_height,
            wall: product.wall,
            pack_size_1: product.pack_size_1,
            pack_size_2: product.pack_size_2,
            pack_size_3: product.pack_size_3,
            kg_per_m: product.kg_per_m,
            baseline_supplier: product.baseline_supplier,
            deprecated: product.deprecated,
        };
    }

    //The three measurements worth showing in a row, in the order a section is spoken about
    function dimensions(product){
        return [product.nominal_height, product.nominal_width, product.nominal_length]
            .filter(value => value !== null && value !== "")
            .join(" x ");
    }
</script>

<template>
    <Head title="Master Materials" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 mt-8 mb-12">
            <section class="bg-white rounded-xl">
                <div class="px-6 pt-8 pb-8">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h1 class="text-3xl font-semibold text-gray-800">Master Materials</h1>
                            <p class="mt-1 text-sm text-gray-500">
                                The platform product catalogue. Every BOM line is classified against
                                it, and every nest resolves its purchasable stock lengths from it.
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50"
                                @click="importing = true"
                            >
                                <i class="mr-1 fa-solid fa-file-import"></i>
                                Import / export JSON
                            </button>
                            <button
                                type="button"
                                class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md shadow-sm hover:bg-blue-700"
                                @click="create()"
                            >
                                <i class="mr-1 fa-solid fa-plus"></i>
                                Add product
                            </button>
                        </div>
                    </div>

                    <!--
                        An empty catalogue is why the nav item is red. A BOM import against one
                        extracts nothing at all and says nothing about why.
                    -->
                    <p
                        v-if="isEmptyCatalogue"
                        class="px-4 py-3 mt-4 text-sm border rounded-lg border-red-300 bg-red-50 text-red-900"
                    >
                        <b>The catalogue is empty.</b>
                        No BOM line can be classified until it has products in it. Load the 1,150
                        inherited rows with
                        <code class="px-1 bg-white rounded">php artisan db:seed --class=MasterMaterialsSeeder</code>,
                        import a JSON export from another environment, or add products here.
                    </p>

                    <!-- Filters -->
                    <div class="grid gap-3 mt-6 sm:grid-cols-4">
                        <label class="block sm:col-span-2">
                            <span class="text-xs font-semibold tracking-wider text-gray-400 uppercase">Search</span>
                            <input
                                v-model="search"
                                type="search"
                                placeholder="Description, category, grade or material"
                                class="w-full mt-1 border-gray-300 rounded-md shadow-sm"
                            />
                        </label>

                        <label class="block">
                            <span class="text-xs font-semibold tracking-wider text-gray-400 uppercase">Category</span>
                            <select v-model="category" class="w-full mt-1 border-gray-300 rounded-md shadow-sm">
                                <option value="">All categories</option>
                                <option v-for="name in options.categories" :key="name" :value="name">{{ name }}</option>
                            </select>
                        </label>

                        <label class="block">
                            <span class="text-xs font-semibold tracking-wider text-gray-400 uppercase">Status</span>
                            <select v-model="status" class="w-full mt-1 border-gray-300 rounded-md shadow-sm">
                                <option value="active">Active ({{ totals.active }})</option>
                                <option value="deprecated">Deprecated ({{ totals.deprecated }})</option>
                                <option value="all">All ({{ totals.active + totals.deprecated }})</option>
                            </select>
                        </label>
                    </div>

                    <!-- Catalogue -->
                    <div class="mt-6 overflow-x-auto border border-gray-200 rounded-lg">
                        <table class="min-w-full text-sm divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr class="text-left text-gray-500">
                                    <th scope="col" class="px-4 py-3 font-normal">Product</th>
                                    <th scope="col" class="px-4 py-3 font-normal">Spec</th>
                                    <th scope="col" class="px-4 py-3 font-normal">Size</th>
                                    <th scope="col" class="px-4 py-3 font-normal">kg/m</th>
                                    <th scope="col" class="px-4 py-3 font-normal">Packs</th>
                                    <th scope="col" class="px-4 py-3 font-normal">Certs</th>
                                    <th scope="col" class="px-4 py-3 font-normal">In use</th>
                                    <th scope="col" class="px-4 py-3 font-normal text-right">Edit</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <tr
                                    v-for="product in products.data"
                                    :key="product.id"
                                    :class="product.deprecated ? 'text-gray-400' : 'text-gray-700'"
                                >
                                    <td class="px-4 py-3">
                                        <p class="font-medium" :class="product.deprecated ? '' : 'text-gray-900'">
                                            {{ product.label }}
                                        </p>
                                        <p class="text-xs text-gray-500">{{ product.description || "(no description)" }}</p>
                                        <span
                                            v-if="product.deprecated"
                                            class="inline-block px-1.5 py-0.5 mt-1 text-xs font-semibold text-gray-600 bg-gray-100 rounded"
                                        >
                                            deprecated
                                        </span>
                                        <!-- Flagged, not hidden: a row with a grade in its material column builds the wrong label -->
                                        <span
                                            v-if="product.invalidValues.length"
                                            class="inline-block px-1.5 py-0.5 mt-1 ml-1 text-xs font-semibold text-red-700 bg-red-100 rounded"
                                            :title="'Not a valid value: ' + product.invalidValues.join(', ')"
                                        >
                                            bad {{ product.invalidValues.join(", ") }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <p>{{ product.product_category }}</p>
                                        <p class="text-xs text-gray-500">
                                            {{ [product.material, product.grade, product.surface].filter(Boolean).join(" / ") || "—" }}
                                        </p>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        {{ dimensions(product) || "—" }}
                                        <span v-if="product.wall" class="text-xs text-gray-500">
                                            ({{ product.wall }} wall)
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">{{ product.kg_per_m ?? "—" }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        {{ [product.pack_size_1, product.pack_size_2, product.pack_size_3].filter(Boolean).join(", ") || "—" }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <!-- null is not "no": it is what drops a product out of the certificate sense check -->
                                        <span v-if="product.certificates === true">Yes</span>
                                        <span v-else-if="product.certificates === false">No</span>
                                        <span v-else class="text-amber-600" title="Nobody has answered, so this product is excluded from the mill certificate sense check">
                                            unanswered
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span
                                            v-if="product.usage?.specLocked"
                                            class="text-xs text-amber-700"
                                            :title="product.usage.summary"
                                        >
                                            <i class="fa-solid fa-lock"></i>
                                            {{ product.usage.summary.replace("Used by ", "") }}
                                        </span>
                                        <span v-else-if="product.usage?.deletable" class="text-xs text-gray-400">
                                            no
                                        </span>
                                        <span v-else class="text-xs text-gray-500" :title="product.usage?.summary">
                                            linked only
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap">
                                        <button
                                            type="button"
                                            class="text-blue-600 hover:underline"
                                            @click="edit(product)"
                                        >
                                            Edit
                                        </button>
                                        <button
                                            type="button"
                                            class="ml-3 text-gray-500 hover:underline"
                                            @click="toggleDeprecated(product)"
                                        >
                                            {{ product.deprecated ? "Restore" : "Deprecate" }}
                                        </button>
                                        <!--
                                            Only for a product nothing refers to at all. Deleting one
                                            that pieces or bars are matched on raises no foreign key
                                            error - those tables have none - it just leaves that work
                                            matching nothing.
                                        -->
                                        <button
                                            v-if="product.usage?.deletable"
                                            type="button"
                                            class="ml-3 text-red-600 hover:underline"
                                            @click="destroy(product)"
                                        >
                                            Delete
                                        </button>
                                    </td>
                                </tr>

                                <tr v-if="products.data.length === 0">
                                    <td colspan="8" class="px-4 py-8 text-center text-gray-500">
                                        Nothing matches those filters.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div v-if="products.meta.last_page > 1" class="flex items-center justify-between mt-6">
                        <Link
                            v-if="products.links.prev"
                            :href="products.links.prev"
                            preserve-scroll
                            class="text-blue-500 underline"
                        >
                            Previous
                        </Link>
                        <span v-else class="text-gray-400">Previous</span>

                        <span class="text-sm text-gray-600">
                            {{ products.meta.from }}&ndash;{{ products.meta.to }} of {{ products.meta.total }}
                        </span>

                        <Link
                            v-if="products.links.next"
                            :href="products.links.next"
                            preserve-scroll
                            class="text-blue-500 underline"
                        >
                            Next
                        </Link>
                        <span v-else class="text-gray-400">Next</span>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>

    <!--
        :key forces a fresh form when the modal is reopened on another product, or reopened blank to
        correct a locked one - useForm holds its initial values for the life of the component.
    -->
    <MaterialEditModal
        v-if="editing"
        :key="'material-' + (editing.id ?? 'new') + '-' + (prefill ? 'prefilled' : 'blank')"
        :product="editing.id ? editing : null"
        :prefill="prefill"
        :options="options"
        :categoryDefinitions="categoryDefinitions"
        @close="closeModal()"
        @saved="closeModal()"
        @duplicate="correctAsNew"
    />

    <MaterialImportModal
        v-if="importing"
        :plan="importPlan"
        @close="importing = false"
    />

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
