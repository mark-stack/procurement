<script setup>
    //General Imports
    import {Head, Link, router, usePage} from "@inertiajs/vue3";
    import {computed, nextTick, ref, watch} from "vue";

    //Component Imports
    import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
    import MaterialEditModal from "@/Components/Modals/MaterialEditModal.vue";
    import MaterialImportModal from "@/Components/Modals/MaterialImportModal.vue";
    import CatalogueReviewModal from "@/Components/Modals/CatalogueReviewModal.vue";
    import ConfirmModal from "@/Components/Modals/ConfirmModal.vue";
    import useConfirm from "@/Shared/useConfirm.js";

    //Props
    const props = defineProps({
        products: Object,
        filters: Object,
        options: Object,
        categoryDefinitions: Object,
        totals: Object,

        /**
         * What cannot be relied on in here: {products, outstanding, accepted, reasons: [{reason,
         * label, hint, outstanding, accepted}]}. Worded server side, in App\Services\CatalogueTrust,
         * so the panel and the record a review writes cannot drift apart.
         */
        trust: Object,

        //The last few reviews, newest first. Empty when nobody has ever recorded one
        reviews: Array,
    });

    //Shared Methods
    const {confirmDialog, askToConfirm, confirmDialogAccepted, confirmDialogCancelled} = useConfirm();

    //Variables
    const search = ref(props.filters.search);
    const category = ref(props.filters.category);
    const status = ref(props.filters.status);

    /*
     * Which trust finding the table is narrowed to, or "" for the whole catalogue. Not a select
     * alongside the three above: it is set by clicking a count on the panel, so the number somebody
     * read and the rows they land on are the same question asked twice.
     */
    const trustFilter = ref(props.filters.trust);

    const reviewing = ref(false);

    /**
     * The product open in the modal. `null` closed, `{}` creating from scratch, and a plain object
     * of form values when a locked product is being corrected as a new one.
     */
    const editing = ref(null);
    const prefill = ref(null);
    const importing = ref(false);

    //Computed
    const isEmptyCatalogue = computed(() => props.totals.active === 0 && props.totals.deprecated === 0);

    //The most recent review, which is the one the heading answers "when" with
    const lastReview = computed(() => props.reviews[0] ?? null);

    //The reviews behind the latest, shown as a cadence rather than as a log
    const earlierReviews = computed(() => props.reviews.slice(1));

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
            trust: trustFilter.value,
        }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    /**
     * Narrow the table to one trust finding, or back out of it.
     *
     * Status goes back to "active" with it, because the report only ever covers active rows - a
     * filter showing "the 30 products with no mass" while the status select said "deprecated"
     * would show nothing at all and look broken.
     */
    function filterByTrust(reason){
        trustFilter.value = trustFilter.value === reason ? "" : reason;

        filtersMovedBySelf = true;

        if(trustFilter.value !== ""){
            status.value = "active";
        }

        applyFilters();

        //Released after the watcher has had its chance to see the move and ignore it
        nextTick(() => filtersMovedBySelf = false);
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

    /*
     * filterByTrust() may move the status select as well as the filter, and it navigates itself -
     * without this the watcher would fire a second identical request off the back of that move.
     */
    let filtersMovedBySelf = false;
    watch([category, status], () => {
        if(filtersMovedBySelf){
            return;
        }

        applyFilters();
    });

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

    /**
     * Reason key => its wording, off the panel. One source for both, so a row's badge and the count
     * that led somebody to it say the same thing.
     */
    const reasonWording = computed(() => Object.fromEntries(
        props.trust.reasons.map(reason => [reason.reason, reason]),
    ));

    /**
     * The findings to badge on one row.
     *
     * invalid_value is left out: it has its own badge that names the offending COLUMNS, which is
     * the only form of it anybody can act on - "a column holding the wrong kind of value" without
     * saying which column is a worse message than the one already there.
     */
    function trustBadges(product){
        return (product.trust ?? [])
            .filter(reason => reason !== "invalid_value")
            .map(reason => reasonWording.value[reason])
            .filter(Boolean);
    }

    //"TIMBER_MERCHANT" as somebody would say it. Shown only beside a non-steel row, where "which
    //merchant" is the reason the row is costed differently from everything around it
    function merchantLabel(supplierGroup){
        return supplierGroup
            .toLowerCase()
            .replace(/_/g, " ")
            .replace(/^./, character => character.toUpperCase());
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

                    <!--
                        Whether these figures can be relied on.

                        At the top, above the catalogue rather than on a page of its own: kg/m here
                        is what every tonne price, offcut valuation and scrap write-off in the
                        application is derived through, and a product with no mass looks exactly
                        like one with a mass on every other screen there is.
                    -->
                    <section
                        v-if="!isEmptyCatalogue"
                        class="px-4 py-4 mt-6 border rounded-lg"
                        :class="trust.outstanding > 0 ? 'border-amber-300 bg-amber-50/60' : 'border-gray-200 bg-gray-50'"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 class="text-sm font-semibold text-gray-900">Review and trust</h2>

                                <p v-if="lastReview" class="mt-1 text-sm text-gray-600">
                                    Last reviewed <b>{{ lastReview.reviewed_at_label }}</b>
                                    by {{ lastReview.by }}, over {{ lastReview.products_reviewed }} products
                                    <template v-if="lastReview.outstanding > 0">
                                        with {{ lastReview.outstanding }} outstanding
                                    </template>
                                    <template v-else>
                                        with nothing outstanding
                                    </template>.
                                </p>

                                <!--
                                    Said plainly rather than left blank. An unreviewed catalogue is
                                    the state every catalogue starts in, and it is the one thing an
                                    audit of a measuring instrument asks about first.
                                -->
                                <p v-else class="mt-1 text-sm text-gray-600">
                                    <b>Nobody has recorded a review of this catalogue.</b>
                                    Its masses per metre decide every tonne price and every offcut
                                    valuation in the application.
                                </p>

                                <p v-if="lastReview?.note" class="mt-1 text-sm text-gray-500">
                                    &ldquo;{{ lastReview.note }}&rdquo;
                                </p>
                            </div>

                            <button
                                type="button"
                                class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50"
                                @click="reviewing = true"
                            >
                                <i class="mr-1 fa-solid fa-clipboard-check"></i>
                                Record a review
                            </button>
                        </div>

                        <!--
                            Each finding is the filter that shows it. A count nobody can act on from
                            where they read it is a count nobody acts on.
                        -->
                        <div class="flex flex-wrap gap-2 mt-4">
                            <button
                                v-for="reason in trust.reasons"
                                :key="reason.reason"
                                type="button"
                                class="px-3 py-1.5 text-xs text-left border rounded-md"
                                :class="[
                                    trustFilter === reason.reason ? 'ring-2 ring-blue-400' : '',
                                    reason.outstanding > 0
                                        ? 'border-amber-300 bg-white text-amber-900 hover:bg-amber-50'
                                        : 'border-gray-200 bg-white text-gray-400 hover:bg-gray-50',
                                ]"
                                :title="reason.hint"
                                @click="filterByTrust(reason.reason)"
                            >
                                <b>{{ reason.outstanding }}</b> {{ reason.label }}
                                <!--
                                    Accepted rows stay counted, in their own number. A list that
                                    quietly dropped what somebody decided to live with could never
                                    say what the decision was.
                                -->
                                <span v-if="reason.accepted" class="text-gray-400">
                                    (+{{ reason.accepted }} accepted)
                                </span>
                            </button>
                        </div>

                        <p class="mt-3 text-xs text-gray-500">
                            {{ trust.outstanding }} of {{ trust.products }} active products carry
                            something unresolved, {{ trust.accepted }} have been looked at and kept
                            deliberately. Deprecated products are not counted &mdash; nothing resolves
                            a mass from one.
                            <button
                                v-if="trustFilter"
                                type="button"
                                class="ml-1 text-blue-600 underline"
                                @click="filterByTrust('')"
                            >
                                Show the whole catalogue
                            </button>
                            <button
                                v-else-if="trust.outstanding > 0"
                                type="button"
                                class="ml-1 text-blue-600 underline"
                                @click="filterByTrust('any')"
                            >
                                Show every flagged row
                            </button>
                        </p>

                        <!-- The cadence, not a log. "Reviewed regularly" is a claim about a series -->
                        <p v-if="earlierReviews.length" class="mt-2 text-xs text-gray-400">
                            Earlier reviews:
                            <span v-for="(review, index) in earlierReviews" :key="review.id">
                                <template v-if="index">, </template>{{ review.reviewed_at_label }} ({{ review.by }})
                            </span>
                        </p>
                    </section>

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
                                    <th scope="col" class="px-4 py-3 font-normal">Material</th>
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

                                        <!--
                                            Why this row is on the trust report. Amber rather than
                                            red: none of these make the row wrong, they make what is
                                            derived from it an assumption.
                                        -->
                                        <span
                                            v-for="badge in trustBadges(product)"
                                            :key="badge.reason"
                                            class="inline-block px-1.5 py-0.5 mt-1 ml-1 text-xs font-semibold rounded"
                                            :class="product.accepted_reason ? 'text-gray-600 bg-gray-100' : 'text-amber-800 bg-amber-100'"
                                            :title="badge.hint"
                                        >
                                            {{ badge.label.toLowerCase() }}
                                        </span>

                                        <!--
                                            Somebody has been here and decided to keep it as it is.
                                            Shown on the row rather than only in the edit form, so
                                            a list of flagged rows reads as "these three are
                                            settled, that one is not".
                                        -->
                                        <p v-if="product.accepted_reason" class="mt-1 text-xs italic text-gray-500">
                                            Accepted: {{ product.accepted_reason }}
                                        </p>
                                    </td>
                                    <!--
                                        What this is made of, said the way a person says it. The
                                        column it comes from is a join key written the way a
                                        database wants it - PLAIN_CARBON_STEEL down 1,100 rows says
                                        nothing, because nearly all of them are. What a reader is
                                        scanning for is the handful that are not steel, because
                                        those are priced through a different merchant and weigh
                                        something else entirely.
                                    -->
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <span
                                            v-if="product.material_label"
                                            :class="product.is_steel ? '' : 'font-medium text-amber-800'"
                                            :title="product.material"
                                        >
                                            {{ product.material_label }}
                                        </span>
                                        <span v-else class="text-gray-400">—</span>

                                        <span
                                            v-if="product.supplier_group && !product.is_steel"
                                            class="block text-xs text-gray-500"
                                        >
                                            {{ merchantLabel(product.supplier_group) }}
                                        </span>
                                    </td>

                                    <td class="px-4 py-3">
                                        <p>{{ product.product_category }}</p>
                                        <!--
                                            Material has its own column now, so it is not repeated
                                            here - what is left is what distinguishes two products
                                            of the same category and material.
                                        -->
                                        <p class="text-xs text-gray-500">
                                            {{ [product.grade, product.surface].filter(Boolean).join(" / ") || "—" }}
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
                                    <td colspan="9" class="px-4 py-8 text-center text-gray-500">
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

    <CatalogueReviewModal
        v-if="reviewing"
        :trust="trust"
        @recorded="reviewing = false"
        @cancel="reviewing = false"
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
