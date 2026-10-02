<script setup>
    /**
     * The bill of materials for a whole batch - every project on it, in one table.
     *
     * Read-only on purpose, and so not a second BomEditModal: that modal is where a material list is
     * uploaded, clarified, customised and deleted from, and every one of those is a per-project job
     * that only the project's owner may do. This answers a different question - what steel is on this
     * batch - which is why it has a Project column and no buttons.
     */
    //General Imports
    import {computed} from "vue";

    //Component Imports
    import Modal from "@/Layouts/Modal.vue";

    //Shared methods
    import shared from "@/Shared/shared.js";

    //Props
    const props = defineProps({
        //What the card is called, for the heading: "Batch 12", or "Open batch" for the pending one
        title: String,
        /**
         * The payload is fetched when the modal opens. Null while it is in flight, so the table can
         * say it is loading rather than showing the same empty state as a batch with no materials.
         */
        bom: Object,
        //Say so when that fetch failed, which is not the same as an empty batch either
        loadFailed: Boolean,
    });

    //Derived state
    const rows = computed(() => props.bom?.rows ?? []);
    const loading = computed(() => !props.bom && !props.loadFailed);

    //Methods
    /*
     * The three below are BomEditModal's, to the letter: the same row drawn two different ways on two
     * screens would read as two different material lists.
     */
    function displayLength(row){
        //Bundled items are counted, not measured, so they have no length to print
        return row.nesting_algo !== "BUNDLE"
            ? parseFloat(row.length_required).toLocaleString()
            : "";
    }

    function displayQuantity(row){
        const quantity = parseFloat(row.sub_qty);

        return Number.isInteger(quantity) ? quantity.toLocaleString() : quantity.toFixed(2);
    }

    function unitDisplay(row){
        return (row.nesting_algo === "METERAGE" || row.nesting_algo === "AREA") ? "mm" : "";
    }

    /*
     * A row with no matched product is either awaiting a custom product or waiting on a clarification
     * its owner has to answer. Both are jobs for the per-project BOM, so this says what it knows.
     */
    function productLabel(row){
        return row.product_label ?? "no matching product yet";
    }
</script>

<template>
    <Modal @closeModal="$emit('closeModal')">
        <div class="px-4 sm:px-6 w-[90vw] max-w-5xl">
            <h3 id="modal-title" class="text-2xl font-bold text-center text-gray-900">
                Bill of Materials
            </h3>
            <p class="mt-1 text-sm text-center text-gray-500">
                {{ title }}
                <template v-if="bom?.projectCount">
                    ·
                    {{ bom.projectCount }}
                    {{ bom.projectCount === 1 ? 'project' : 'projects' }}
                </template>
            </p>

            <p v-if="loading" class="py-16 text-center text-gray-500">
                <i class="fa-solid fa-circle-notch fa-spin"></i>
                Loading the material list...
            </p>

            <p v-else-if="loadFailed" class="py-16 text-center text-gray-600">
                We could not load this batch's material list. Close this and try again.
            </p>

            <p v-else-if="rows.length === 0" class="py-16 text-center text-gray-600">
                There are no materials on this batch.
            </p>

            <div v-else class="mt-5 overflow-hidden border border-gray-200 rounded-lg">
                <div class="relative overflow-auto max-h-[55vh]">
                    <table class="min-w-full text-left divide-y divide-gray-200">
                        <thead class="sticky top-0 bg-gray-50">
                            <tr>
                                <!--
                                    The column this table exists for: a batch is several jobs bought as
                                    one, so a row nobody can trace back to a project is unreadable.
                                -->
                                <th scope="col" class="px-4 py-3.5 text-sm font-normal text-left text-gray-500">
                                    Project
                                </th>
                                <th scope="col" class="px-4 py-3.5 text-sm font-normal text-left text-gray-500">
                                    Description
                                </th>
                                <th scope="col" class="px-4 py-3.5 text-sm font-normal text-left text-gray-500">
                                    Length
                                </th>
                                <th scope="col" class="px-4 py-3.5 text-sm font-normal text-left text-gray-500">
                                    Quantity
                                </th>
                                <th scope="col" class="px-4 py-3.5 text-sm font-normal text-left text-gray-500">
                                    Reference
                                </th>
                                <th scope="col" class="px-4 py-3.5 text-sm font-normal text-left text-gray-500">
                                    Status
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <tr v-for="row in rows" :key="row.id">
                                <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                    <span :title="row.project" class="text-gray-800">
                                        {{ shared.cropText(row.project) }}
                                    </span>
                                </td>
                                <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                    <!-- Cropped for the column, so the whole description stays on hover -->
                                    <span :title="row.description" class="font-medium text-gray-800">
                                        {{ shared.cropText(row.description) }}
                                    </span>
                                    <span class="block text-gray-500">({{ productLabel(row) }})</span>
                                </td>
                                <td class="px-4 py-4 text-sm font-medium text-gray-800 whitespace-nowrap">
                                    <span v-if="row.nesting_algo === 'AREA'">L: </span>{{ displayLength(row) }}<span class="text-xs">{{ unitDisplay(row) }}</span>
                                    <span v-if="row.nesting_algo === 'AREA'" class="block">
                                        W: {{ parseFloat(row.width_required).toLocaleString() }}<span class="text-xs">{{ unitDisplay(row) }}</span>
                                    </span>
                                </td>
                                <td class="px-4 py-4 text-sm font-medium text-gray-800 whitespace-nowrap">
                                    {{ displayQuantity(row) }}
                                </td>
                                <td class="px-4 py-4 text-sm italic font-medium text-gray-800 whitespace-nowrap">
                                    {{ row.assembly_mark ? ('"'+row.assembly_mark+'"') : '' }}
                                </td>
                                <td class="px-4 py-4 text-sm italic font-medium text-gray-800 whitespace-nowrap">
                                    {{ row.status }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </Modal>
</template>
