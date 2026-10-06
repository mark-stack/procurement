<script setup>
    //General Imports
    import {computed, ref, watch} from "vue";
    import {useForm} from "@inertiajs/vue3";

    //Component Imports
    import Modal from "@/Layouts/Modal.vue";
    import InputError from "@/Components/InputError.vue";

    //Props
    const props = defineProps({
        /**
         * The product being edited, or null when one is being created.
         */
        product: Object,
        /**
         * Starting values for a NEW product, carried over from a locked one being corrected. Kept
         * separate from `product`: a new product has no usage and no id, so nothing about it is
         * locked and it posts to store rather than update - which is the entire point of offering
         * this as the way out of a locked spec.
         */
        prefill: Object,
        options: Object,
        categoryDefinitions: Object,
    });

    //Variables
    const emit = defineEmits(['close', 'saved', 'duplicate']);

    const isNew = computed(() => !props.product);

    //Form
    const form = useForm(startingValues());

    function startingValues(){
        const p = props.product ?? props.prefill;

        return {
            description: p?.description ?? "",
            product_category: p?.product_category ?? "",
            material: p?.material ?? "",
            grade: p?.grade ?? "",
            surface: p?.surface ?? "",
            //Tri-state, sent as-is: null means nobody has answered, which is not the same as "no"
            certificates: p ? p.certificates : null,
            nominal_length: p?.nominal_length ?? "",
            precise_length: p?.precise_length ?? "",
            nominal_width: p?.nominal_width ?? "",
            precise_width: p?.precise_width ?? "",
            nominal_height: p?.nominal_height ?? "",
            precise_height: p?.precise_height ?? "",
            wall: p?.wall ?? "",
            pack_size_1: p?.pack_size_1 ?? "",
            pack_size_2: p?.pack_size_2 ?? "",
            pack_size_3: p?.pack_size_3 ?? "",
            kg_per_m: p?.kg_per_m ?? "",
            baseline_supplier: p?.baseline_supplier ?? "",
            accepted_reason: p?.accepted_reason ?? "",
            deprecated: p?.deprecated ?? false,
        };
    }

    //Computed
    const definition = computed(() => props.categoryDefinitions[form.product_category] ?? null);

    /**
     * The columns that identify a product of the chosen category. Editing one of these on a
     * product something is already built on would detach that work rather than correct it, so the
     * server refuses it - and the form says so before it is attempted.
     */
    const specColumns = computed(() => definition.value?.specColumns ?? []);

    const usage = computed(() => props.product?.usage ?? null);
    const specLocked = computed(() => usage.value?.specLocked === true);

    /**
     * Why this row is on the catalogue's trust report, if it is - the reason keys defined on
     * App\Services\CatalogueTrust.
     *
     * A product being created carries none: there is nothing to accept about a row that does not
     * exist yet, and the form's own rules already refuse most of what would put one here.
     */
    const trust = computed(() => props.product?.trust ?? []);

    /*
     * The acceptance box is offered to a flagged row, and to one that already carries an acceptance
     * - otherwise a reason recorded earlier would become uneditable the moment the row was
     * corrected, with no way to clear the note that no longer applies.
     */
    const canAccept = computed(() => trust.value.length > 0 || (props.product?.accepted_reason ?? "") !== "");

    const isSpecColumn = (column) => specColumns.value.includes(column);
    const isLocked = (column) => specLocked.value && isSpecColumn(column);

    /**
     * Both are properties of the category, not of the row, so they are shown rather than entered.
     * Every one of the 1,150 rows the spreadsheet held already agreed with its category on both.
     */
    const derived = computed(() => ({
        nesting_algo: definition.value?.nesting_algo ?? "—",
        nominal_units: definition.value?.nominal_units ?? "—",
    }));

    //A category with no implementation cannot be classified, labelled or nested at all
    const unsupportedCategory = computed(() => definition.value?.unsupported === true);

    //Measurements, and which of them this category is actually described by
    const measurements = [
        {key: "nominal_height", label: "Nominal height"},
        {key: "nominal_width", label: "Nominal width"},
        {key: "nominal_length", label: "Nominal length"},
        {key: "precise_height", label: "Precise height"},
        {key: "precise_width", label: "Precise width"},
        {key: "precise_length", label: "Precise length"},
        {key: "wall", label: "Wall thickness"},
        {key: "kg_per_m", label: "Mass per metre (kg/m)"},
    ];

    const packSizes = ["pack_size_1", "pack_size_2", "pack_size_3"];

    //Methods
    function submit(){
        if(isNew.value){
            form.post(route("admin.materials.store"), {
                preserveScroll: true,
                onSuccess: () => emit("saved"),
            });

            return;
        }

        form.patch(route("admin.materials.update", props.product.id), {
            preserveScroll: true,
            onSuccess: () => emit("saved"),
        });
    }

    /**
     * The way out of a locked spec: this product stops being offered for new work, and a corrected
     * copy is created beside it. Everything already built on the old spec still resolves to it,
     * which is what it was actually built to.
     */
    function deprecateAndCopy(){
        /*
         * accepted_reason is deliberately not carried over. It says why the ORIGINAL row was being
         * kept as it stands, and the whole point of this button is that it no longer is.
         */
        emit("duplicate", {...form.data(), accepted_reason: "", deprecated: false});
    }

    /*
     * Switching category changes which columns identify the product, so a measurement the new
     * category does not use has to be cleared rather than left behind where nothing displays it.
     */
    watch(() => form.product_category, (category, previous) => {
        if(!previous || !category){
            return;
        }

        const stillNamed = props.categoryDefinitions[category]?.specColumns ?? [];
        const wasNamed = props.categoryDefinitions[previous]?.specColumns ?? [];

        wasNamed
            .filter(column => !stillNamed.includes(column) && measurements.some(m => m.key === column))
            .forEach(column => form[column] = "");
    });

    //A blank on a column the product is matched on is what makes it unmatchable, so it is named
    const blankSpecColumns = computed(() => specColumns.value.filter(column => {
        if(column === "product_category" || column === "nominal_units"){
            return false;
        }

        return form[column] === "" || form[column] === null;
    }));
</script>

<template>
    <Modal labelledby="material-modal-title" @closeModal="$emit('close')">
        <div class="px-6" style="width:min(56rem, calc(100vw - 2rem))">
            <h3 id="material-modal-title" class="text-lg font-semibold text-gray-900">
                {{ isNew ? "Add a product to the catalogue" : "Edit " + (product.label || "product #" + product.id) }}
            </h3>

            <!-- What already depends on this product, and therefore what may still be done to it -->
            <div v-if="usage" class="mt-3 text-sm">
                <p :class="specLocked ? 'text-amber-800 bg-amber-50 border-amber-300' : 'text-gray-600 bg-gray-50 border-gray-200'" class="px-3 py-2 border rounded-lg">
                    {{ usage.summary }}<template v-if="specLocked">. The columns that identify it are locked below.</template>
                </p>
            </div>

            <!-- Values the catalogue holds that are no longer legal to save -->
            <p
                v-if="product?.invalidValues?.length"
                class="px-3 py-2 mt-2 text-sm border rounded-lg border-red-300 bg-red-50 text-red-900"
            >
                Inherited from the spreadsheet and not a valid value:
                <b>{{ product.invalidValues.join(", ") }}</b>.
                Saving requires correcting it.
            </p>

            <form @submit.prevent="submit()" class="mt-4">
                <!-- Identity -->
                <fieldset class="pt-3 border-t border-gray-200">
                    <legend class="text-xs font-semibold tracking-wider text-gray-400 uppercase">
                        Identity
                        <span v-if="specLocked" class="ml-1 text-amber-700 normal-case">(locked - in use)</span>
                    </legend>

                    <div class="grid gap-4 mt-2 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-gray-700">Description</span>
                            <!-- Not part of the spec, so it stays editable however much is built on the product -->
                            <input
                                v-model="form.description"
                                type="text"
                                class="w-full mt-1 border-gray-300 rounded-md shadow-sm"
                                placeholder="200PFC 9m"
                            />
                            <InputError :message="form.errors.description" class="mt-1"/>
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium text-gray-700">Product category</span>
                            <select
                                v-model="form.product_category"
                                :disabled="isLocked('product_category')"
                                class="w-full mt-1 border-gray-300 rounded-md shadow-sm disabled:bg-gray-100 disabled:text-gray-500"
                                required
                            >
                                <option value="" disabled>Choose one</option>
                                <option v-for="category in options.categories" :key="category" :value="category">
                                    {{ category }}
                                </option>
                            </select>
                            <InputError :message="form.errors.product_category" class="mt-1"/>
                        </label>

                        <label v-for="field in [
                            {key: 'material', label: 'Material', list: options.materials},
                            {key: 'grade', label: 'Grade', list: options.grades},
                            {key: 'surface', label: 'Surface', list: options.surfaces},
                        ]" :key="field.key" class="block">
                            <span class="text-sm font-medium text-gray-700">{{ field.label }}</span>
                            <select
                                v-model="form[field.key]"
                                :disabled="isLocked(field.key)"
                                class="w-full mt-1 border-gray-300 rounded-md shadow-sm disabled:bg-gray-100 disabled:text-gray-500"
                            >
                                <!--
                                    A blank option only for a product that already has one. The
                                    catalogue inherited rows with no grade at all and their mass per
                                    metre still has to be correctable, but nothing new starts out
                                    unmatchable.
                                -->
                                <option v-if="!isNew" value="">(blank)</option>
                                <option v-for="value in field.list" :key="value" :value="value">{{ value }}</option>
                            </select>
                            <InputError :message="form.errors[field.key]" class="mt-1"/>
                        </label>
                    </div>
                </fieldset>

                <!-- Derived from the category -->
                <fieldset class="pt-3 mt-5 border-t border-gray-200">
                    <legend class="text-xs font-semibold tracking-wider text-gray-400 uppercase">
                        Set by the category
                    </legend>
                    <p class="mt-1 text-xs text-gray-500">
                        Both follow from the product category, so they are not typed here - a PLATE
                        saved as METERAGE would nest as a bar while looking perfectly ordinary.
                    </p>
                    <div class="grid gap-4 mt-2 sm:grid-cols-2">
                        <p class="text-sm text-gray-700">
                            Nesting algorithm: <b>{{ derived.nesting_algo }}</b>
                        </p>
                        <p class="text-sm text-gray-700">
                            Measurement unit: <b>{{ derived.nominal_units }}</b>
                        </p>
                    </div>
                    <p v-if="unsupportedCategory" class="px-3 py-2 mt-2 text-sm border rounded-lg border-red-300 bg-red-50 text-red-900">
                        {{ form.product_category }} has no implementation, so nothing can classify a
                        BOM line into it, build its label or nest it. Products saved under it will
                        never be matched.
                    </p>
                </fieldset>

                <!-- Measurements -->
                <fieldset class="pt-3 mt-5 border-t border-gray-200">
                    <legend class="text-xs font-semibold tracking-wider text-gray-400 uppercase">
                        Measurements
                    </legend>

                    <div class="grid gap-4 mt-2 sm:grid-cols-4">
                        <label v-for="field in measurements" :key="field.key" class="block">
                            <span class="text-sm font-medium text-gray-700">
                                {{ field.label }}
                                <!-- Which of these identify the product is the whole reason some lock -->
                                <i
                                    v-if="isSpecColumn(field.key)"
                                    class="ml-1 text-xs fa-solid fa-fingerprint"
                                    :class="specLocked ? 'text-amber-600' : 'text-gray-400'"
                                    :title="specLocked
                                        ? 'Identifies this product, and it is in use - locked'
                                        : 'Identifies this product'"
                                ></i>
                            </span>
                            <input
                                v-model="form[field.key]"
                                :disabled="isLocked(field.key)"
                                type="number"
                                step="any"
                                min="0"
                                class="w-full mt-1 border-gray-300 rounded-md shadow-sm disabled:bg-gray-100 disabled:text-gray-500"
                            />
                            <InputError :message="form.errors[field.key]" class="mt-1"/>
                        </label>
                    </div>

                    <p v-if="blankSpecColumns.length" class="mt-2 text-xs text-amber-700">
                        Blank, but identifies this product:
                        <b>{{ blankSpecColumns.join(", ") }}</b>.
                        A piece spec carrying a value here will not match it.
                    </p>
                </fieldset>

                <!-- Commercial -->
                <fieldset class="pt-3 mt-5 border-t border-gray-200">
                    <legend class="text-xs font-semibold tracking-wider text-gray-400 uppercase">
                        Purchasing
                    </legend>
                    <p class="mt-1 text-xs text-gray-500">
                        None of these identify the product, so they stay editable however much is
                        built on it. A corrected weight or pack size takes effect on the next nest.
                    </p>

                    <div class="grid gap-4 mt-2 sm:grid-cols-4">
                        <label v-for="(key, index) in packSizes" :key="key" class="block">
                            <span class="text-sm font-medium text-gray-700">Pack size {{ index + 1 }}</span>
                            <input
                                v-model="form[key]"
                                type="number"
                                min="1"
                                step="1"
                                class="w-full mt-1 border-gray-300 rounded-md shadow-sm"
                            />
                            <InputError :message="form.errors[key]" class="mt-1"/>
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium text-gray-700">Mill certificates</span>
                            <!--
                                Three values, not two. where('certificates', true) and
                                where('certificates', false) both exclude null, which is how products
                                silently dropped out of the mill certificate sense check - so "nobody
                                said" has to be distinguishable from "no".
                            -->
                            <select
                                v-model="form.certificates"
                                class="w-full mt-1 border-gray-300 rounded-md shadow-sm"
                            >
                                <option :value="null">Unanswered</option>
                                <option :value="true">Yes</option>
                                <option :value="false">No</option>
                            </select>
                            <InputError :message="form.errors.certificates" class="mt-1"/>
                        </label>
                    </div>

                    <label class="block mt-4">
                        <span class="text-sm font-medium text-gray-700">Baseline supplier reference</span>
                        <input
                            v-model="form.baseline_supplier"
                            type="text"
                            class="w-full mt-1 border-gray-300 rounded-md shadow-sm"
                            placeholder="https://supplier.example/reference-guide.pdf"
                        />
                        <InputError :message="form.errors.baseline_supplier" class="mt-1"/>
                    </label>

                    <!--
                        The other half of a catalogue review: a row that is flagged and is going to
                        stay as it is. The catalogue's only stainless hex bolt carries its grade in
                        its description and always will; seven LVL rows have no grade because nobody
                        grades LVL that way. Without somewhere to say so the report never empties,
                        and a report that cannot reach zero stops being read.

                        Who accepted it and when is not asked for here - saving this is a product
                        edit like any other, and RecordsChanges already writes down both.
                    -->
                    <div v-if="canAccept" class="px-3 py-3 mt-4 border rounded-lg border-amber-200 bg-amber-50/60">
                        <p class="text-sm font-medium text-amber-900">
                            This row is on the trust report
                        </p>

                        <ul class="mt-1 ml-4 text-xs list-disc text-amber-800">
                            <li v-for="reason in trust" :key="reason">{{ reason.replace(/_/g, " ") }}</li>
                        </ul>

                        <label class="block mt-2">
                            <span class="text-sm font-medium text-gray-700">
                                Accepted because
                                <span class="font-normal text-gray-500">(leave empty to keep it outstanding)</span>
                            </span>
                            <textarea
                                v-model="form.accepted_reason"
                                rows="2"
                                maxlength="1000"
                                placeholder="e.g. LVL is not graded this way, so the blank is correct"
                                class="w-full mt-1 text-sm border-gray-300 rounded-md shadow-sm"
                            ></textarea>
                            <InputError :message="form.errors.accepted_reason" class="mt-1"/>
                        </label>
                    </div>

                    <label class="flex items-start gap-2 mt-4">
                        <input v-model="form.deprecated" type="checkbox" class="mt-1 rounded"/>
                        <span class="text-sm text-gray-700">
                            <b>Deprecated</b> - stop offering this for new work. Everything already
                            built on it keeps resolving to it.
                        </span>
                    </label>
                </fieldset>
            </form>
        </div>

        <template #footer>
            <button
                type="button"
                class="w-full px-4 py-2 text-base font-medium text-white bg-blue-600 border border-transparent rounded-md shadow-sm hover:bg-blue-700 sm:ml-3 sm:w-auto sm:text-sm disabled:opacity-50"
                :disabled="form.processing"
                @click="submit()"
            >
                {{ form.processing ? "Saving..." : (isNew ? "Add product" : "Save changes") }}
            </button>

            <!--
                Offered only when it is the actual way forward: the spec is locked and there is
                something to correct. It opens a new product prefilled from this one.
            -->
            <button
                v-if="specLocked"
                type="button"
                class="w-full px-4 py-2 mt-3 text-base font-medium text-amber-900 bg-white border border-amber-400 rounded-md shadow-sm hover:bg-amber-50 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm"
                @click="deprecateAndCopy()"
            >
                Correct as a new product
            </button>

            <button
                type="button"
                data-modal-autofocus
                class="w-full px-4 py-2 mt-3 text-base font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm"
                @click="$emit('close')"
            >
                Cancel
            </button>
        </template>
    </Modal>
</template>
