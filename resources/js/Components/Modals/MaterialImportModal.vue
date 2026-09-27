<script setup>
    //General Imports
    import {computed, ref} from "vue";
    import {router, useForm} from "@inertiajs/vue3";

    //Component Imports
    import Modal from "@/Layouts/Modal.vue";

    //Props
    const props = defineProps({
        /**
         * What the last preview said the file would do, or null before anything has been previewed.
         * Nothing here has been written - that only happens when the plan is applied.
         */
        plan: Object,
    });

    //Variables
    const emit = defineEmits(['close']);

    //Form
    const previewForm = useForm({
        file: null,
        json: "",
        mode: "merge",
    });

    const applyForm = useForm({
        json: "",
        fingerprint: "",
    });

    const fileInput = ref(null);
    const showErrors = ref(true);
    const showChanges = ref(false);

    //Computed
    const counts = computed(() => props.plan?.counts ?? null);

    //A plan with any invalid row is not applied at all - a half-imported catalogue is worse than none
    const blocked = computed(() => (counts.value?.errors ?? 0) > 0);

    const nothingToDo = computed(() => counts.value
        && counts.value.create === 0
        && counts.value.update === 0
        && counts.value.deprecate === 0);

    /**
     * A real RHS row, as the shape to hand to an AI along with the ask. Every key here is one the
     * importer reads; the two it derives are deliberately absent.
     */
    const example = JSON.stringify({
        mode: "merge",
        products: [
            {
                description: "75x50x2.0 RHS 8m",
                product_category: "RHS",
                material: "PLAIN_CARBON_STEEL",
                grade: "GR350",
                surface: "NONE",
                certificates: true,
                nominal_height: "75",
                nominal_width: "50",
                nominal_length: "8000",
                wall: 2.0,
                kg_per_m: 3.72,
                pack_size_1: 1,
                baseline_supplier: "https://supplier.example/reference.pdf",
            },
        ],
    }, null, 2);

    //Methods
    function preview(){
        previewForm
            .transform(data => ({
                file: data.file,
                //Only one of the two is ever sent - a chosen file wins over pasted text
                json: data.file ? "" : withMode(data.json, data.mode),
            }))
            .post(route("admin.materials.import.preview"), {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    showErrors.value = true;
                    showChanges.value = false;
                },
            });
    }

    /**
     * The mode chosen in the UI wins over a "mode" the file happens to carry, because the radio is
     * the thing the admin just looked at. An exported file says "replace"; pasting a handful of new
     * products with it left on would deprecate the entire rest of the catalogue.
     */
    function withMode(json, mode){
        const trimmed = (json ?? "").trim();

        if(trimmed === ""){
            return trimmed;
        }

        try {
            const parsed = JSON.parse(trimmed);
            const envelope = Array.isArray(parsed) ? {products: parsed} : parsed;

            return JSON.stringify({...envelope, mode});
        }
        catch {
            //Let the server produce the parse error - it words it better than a browser alert
            return trimmed;
        }
    }

    function apply(){
        applyForm.json = props.plan.json;
        applyForm.fingerprint = props.plan.fingerprint;

        applyForm.post(route("admin.materials.import.apply"), {
            preserveScroll: true,
            onSuccess: () => emit("close"),
        });
    }

    function chooseFile(event){
        previewForm.file = event.target.files[0] ?? null;
    }

    /**
     * Two shapes of export, and the server decides the mode from which one it is.
     *
     * A "changed since" file is a MERGE file, never a replace one - importing a three-product subset
     * as a replace would deprecate the entire rest of the catalogue.
     */
    function exportCatalogue(since = null){
        //A plain navigation, not an Inertia visit: the response is a file, not a page
        window.location = route("admin.materials.export", since ? {since} : {});
    }

    //Default to a fortnight, which covers "the things I did this week and last"
    const since = ref(new Date(Date.now() - 14 * 86400000).toISOString().slice(0, 10));

    function copyExample(){
        navigator.clipboard?.writeText(example);
    }

    //Old value and new, shown as text, with a blank named rather than rendered as nothing
    function shown(value){
        return value === null || value === "" ? "(blank)" : String(value);
    }
</script>

<template>
    <Modal labelledby="material-import-title" @closeModal="$emit('close')">
        <div class="px-6" style="width:min(60rem, calc(100vw - 2rem))">
            <h3 id="material-import-title" class="text-lg font-semibold text-gray-900">
                Import products from JSON
            </h3>
            <p class="mt-1 text-sm text-gray-500">
                Nothing is written until you have seen what it would do and said yes.
            </p>

            <!-- Keeping two environments in step -->
            <div class="px-3 py-3 mt-4 text-sm border rounded-lg border-blue-200 bg-blue-50 text-blue-900">
                <p class="font-semibold">Syncing with another environment?</p>
                <p class="mt-1">
                    Export from whichever environment is right, then import the file into the other.
                    The preview lists every product that would be created, changed or deprecated
                    before any of it happens.
                </p>

                <!--
                    The everyday case first. A subset file is always a merge file - the server
                    decides that, because importing a handful of products as a replace would
                    deprecate the whole rest of the catalogue.
                -->
                <div class="flex flex-wrap items-center gap-2 mt-3">
                    <button
                        type="button"
                        class="px-2 py-1 text-xs font-medium bg-white border rounded border-blue-300 hover:bg-blue-100"
                        @click="exportCatalogue(since)"
                    >
                        <i class="fa-solid fa-download"></i>
                        Export what changed since
                    </button>
                    <input
                        v-model="since"
                        type="date"
                        class="px-2 py-1 text-xs border-blue-300 rounded"
                    />
                    <span class="text-xs">&rarr; imports as <b>merge</b>, for items you added or corrected</span>
                </div>

                <div class="flex flex-wrap items-center gap-2 mt-2">
                    <button
                        type="button"
                        class="px-2 py-1 text-xs font-medium bg-white border rounded border-blue-300 hover:bg-blue-100"
                        @click="exportCatalogue()"
                    >
                        <i class="fa-solid fa-download"></i>
                        Export the whole catalogue
                    </button>
                    <span class="text-xs">
                        &rarr; imports as <b>replace</b>. Needed to carry across a deprecation, or a
                        change to a column that identifies a product.
                    </span>
                </div>
            </div>

            <!-- Source -->
            <div class="grid gap-4 mt-5 sm:grid-cols-2">
                <div>
                    <span class="text-xs font-semibold tracking-wider text-gray-400 uppercase">JSON file</span>
                    <input
                        ref="fileInput"
                        type="file"
                        accept=".json,application/json"
                        class="w-full mt-1 text-sm"
                        @change="chooseFile"
                    />
                    <p v-if="previewForm.errors.file" class="mt-1 text-sm text-red-600">{{ previewForm.errors.file }}</p>
                </div>

                <div>
                    <span class="text-xs font-semibold tracking-wider text-gray-400 uppercase">Mode</span>
                    <label class="flex items-start gap-2 mt-1 text-sm">
                        <input v-model="previewForm.mode" type="radio" value="merge" class="mt-1"/>
                        <span>
                            <b>Merge</b> - create and update only. Products missing from the file are
                            left alone. Use this for a batch of new sections.
                        </span>
                    </label>
                    <label class="flex items-start gap-2 mt-2 text-sm">
                        <input v-model="previewForm.mode" type="radio" value="replace" class="mt-1"/>
                        <span>
                            <b>Replace</b> - the file is the whole catalogue. Anything absent from it
                            is <i>deprecated</i>, never deleted. Use this to sync environments.
                        </span>
                    </label>
                </div>
            </div>

            <div class="mt-4">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold tracking-wider text-gray-400 uppercase">
                        Or paste JSON
                    </span>
                    <button type="button" class="text-xs text-blue-600 underline" @click="copyExample()">
                        Copy an example to hand to an AI
                    </button>
                </div>
                <textarea
                    v-model="previewForm.json"
                    rows="6"
                    spellcheck="false"
                    class="w-full mt-1 font-mono text-xs border-gray-300 rounded-md shadow-sm"
                    :placeholder="example"
                ></textarea>
                <p class="mt-1 text-xs text-gray-500">
                    One object per product. <code>nesting_algo</code> and <code>nominal_units</code>
                    are set by the category, so leave them out - a file carrying them is refused.
                    A column left out is imported as blank, so a row is a whole product, not a patch.
                </p>
                <p v-if="previewForm.errors.json" class="mt-1 text-sm text-red-600">{{ previewForm.errors.json }}</p>
            </div>

            <!-- The plan -->
            <div v-if="plan" class="pt-4 mt-5 border-t border-gray-200">
                <h4 class="text-sm font-semibold text-gray-900">
                    What this file would do
                    <span class="ml-1 font-normal text-gray-500">({{ plan.mode }} mode)</span>
                </h4>

                <div class="grid gap-2 mt-2 sm:grid-cols-5">
                    <p class="px-3 py-2 text-sm border rounded-lg border-green-200 bg-green-50 text-green-900">
                        <b>{{ counts.create }}</b> new
                    </p>
                    <p class="px-3 py-2 text-sm border rounded-lg border-blue-200 bg-blue-50 text-blue-900">
                        <b>{{ counts.update }}</b> changed
                    </p>
                    <p class="px-3 py-2 text-sm border rounded-lg border-amber-200 bg-amber-50 text-amber-900">
                        <b>{{ counts.deprecate }}</b> deprecated
                    </p>
                    <p class="px-3 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg bg-gray-50">
                        <b>{{ counts.unchanged }}</b> unchanged
                    </p>
                    <p
                        class="px-3 py-2 text-sm border rounded-lg"
                        :class="counts.errors > 0
                            ? 'border-red-300 bg-red-50 text-red-900'
                            : 'border-gray-200 bg-gray-50 text-gray-600'"
                    >
                        <b>{{ counts.errors }}</b> invalid
                    </p>
                </div>

                <!-- Invalid rows. Nothing is applied while any remain. -->
                <div v-if="plan.errors.length" class="mt-3">
                    <button
                        type="button"
                        class="text-sm font-semibold text-red-700 underline"
                        @click="showErrors = !showErrors"
                    >
                        {{ showErrors ? "Hide" : "Show" }} the {{ plan.errors.length }} invalid
                        {{ plan.errors.length === 1 ? "row" : "rows" }}
                    </button>
                    <ul v-if="showErrors" class="mt-2 overflow-y-auto text-sm max-h-48">
                        <li
                            v-for="error in plan.errors"
                            :key="error.row"
                            class="px-3 py-2 mb-1 border rounded border-red-200 bg-red-50 text-red-900"
                        >
                            <b>Row {{ error.row }}</b>
                            <span v-if="error.label" class="italic"> "{{ error.label }}"</span>
                            <ul class="mt-1 ml-4 list-disc">
                                <li v-for="(message, index) in error.messages" :key="index">{{ message }}</li>
                            </ul>
                        </li>
                    </ul>
                </div>

                <!-- Every actual change, because "12 changed" is not something you can approve -->
                <div v-if="counts.create + counts.update + counts.deprecate > 0" class="mt-3">
                    <button
                        type="button"
                        class="text-sm text-blue-600 underline"
                        @click="showChanges = !showChanges"
                    >
                        {{ showChanges ? "Hide" : "Show" }} every change
                    </button>

                    <div v-if="showChanges" class="mt-2 overflow-y-auto text-sm max-h-64">
                        <p v-for="entry in plan.create" :key="'c' + entry.row" class="px-3 py-1 mb-1 border rounded border-green-200 bg-green-50">
                            <span class="font-semibold text-green-800">NEW</span>
                            {{ entry.label }}
                            <span class="text-xs text-gray-500">(row {{ entry.row }})</span>
                        </p>

                        <div v-for="entry in plan.update" :key="'u' + entry.row" class="px-3 py-1 mb-1 border rounded border-blue-200 bg-blue-50">
                            <p>
                                <span class="font-semibold text-blue-800">CHANGE</span>
                                {{ entry.label }}
                                <span class="text-xs text-gray-500">(#{{ entry.id }}, row {{ entry.row }})</span>
                            </p>
                            <ul class="ml-4 text-xs list-disc text-gray-700">
                                <li v-for="(change, column) in entry.changes" :key="column">
                                    {{ column }}: {{ shown(change.from) }} &rarr; <b>{{ shown(change.to) }}</b>
                                </li>
                            </ul>
                        </div>

                        <p v-for="entry in plan.deprecate" :key="'d' + entry.id" class="px-3 py-1 mb-1 border rounded border-amber-200 bg-amber-50">
                            <span class="font-semibold text-amber-800">DEPRECATE</span>
                            {{ entry.label }}
                            <span class="text-xs text-gray-500">(#{{ entry.id }}, absent from the file)</span>
                        </p>
                    </div>
                </div>

                <p v-if="blocked" class="px-3 py-2 mt-3 text-sm border rounded-lg border-red-300 bg-red-50 text-red-900">
                    Nothing will be imported while any row is invalid. A catalogue half way between
                    two versions of itself is worse than one that did not change.
                </p>
                <p v-else-if="nothingToDo" class="px-3 py-2 mt-3 text-sm text-gray-600 border border-gray-200 rounded-lg bg-gray-50">
                    This file matches the catalogue exactly. There is nothing to apply.
                </p>
            </div>
        </div>

        <template #footer>
            <button
                v-if="plan && !blocked && !nothingToDo"
                type="button"
                class="w-full px-4 py-2 text-base font-medium text-white bg-green-600 border border-transparent rounded-md shadow-sm hover:bg-green-700 sm:ml-3 sm:w-auto sm:text-sm disabled:opacity-50"
                :disabled="applyForm.processing"
                @click="apply()"
            >
                {{ applyForm.processing ? "Importing..." : "Apply this import" }}
            </button>

            <button
                type="button"
                class="w-full px-4 py-2 mt-3 text-base font-medium text-white bg-blue-600 border border-transparent rounded-md shadow-sm hover:bg-blue-700 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm disabled:opacity-50"
                :disabled="previewForm.processing"
                @click="preview()"
            >
                {{ previewForm.processing ? "Checking..." : (plan ? "Check again" : "Check this file") }}
            </button>

            <button
                type="button"
                data-modal-autofocus
                class="w-full px-4 py-2 mt-3 text-base font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm"
                @click="$emit('close')"
            >
                Close
            </button>
        </template>
    </Modal>
</template>
