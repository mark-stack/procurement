<script setup>
    /**
     * The bill of materials for a whole batch - every project on it, in one table.
     *
     * The table is read-only on purpose, and so this is not a second BomEditModal: that modal is
     * where a material list is uploaded, clarified, customised and deleted a row at a time, and every
     * one of those is a per-project job that only the project's owner may do. This answers a
     * different question - what steel is on this batch - which is why it has a Project column.
     *
     * The one thing it does change is the uploads behind that steel, and for the reason the row-level
     * editing stays where it is: taking a wrong revision off the open batch means recognising one
     * spreadsheet's rows by eye in a table carrying several projects', which is not a thing anybody
     * should be asked to do. Removing the file is one decision about one upload, and the server
     * refuses it the moment that steel is on a batch or has been quoted - see
     * MaterialListFile::isDeletable.
     */
    //General Imports
    import {computed, ref} from "vue";
    import {router, usePage} from "@inertiajs/vue3";

    //Component Imports
    import Modal from "@/Layouts/Modal.vue";
    import ConfirmModal from "@/Components/Modals/ConfirmModal.vue";

    //Shared methods
    import shared from "@/Shared/shared.js";
    import useConfirm from "@/Shared/useConfirm.js";

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

    //Variables
    //Asks the page to fetch the batch again - the table, the file list and the card's cut count
    const emit = defineEmits(['closeModal', 'refresh']);
    //Which file's delete is in flight, so only that row's button says so
    const deletingId = ref(null);

    //Shared methods
    const {confirmDialog, askToConfirm, confirmDialogAccepted, confirmDialogCancelled} = useConfirm();

    //Derived state
    const rows = computed(() => props.bom?.rows ?? []);
    const loading = computed(() => !props.bom && !props.loadFailed);
    const files = computed(() => props.bom?.files ?? []);
    //Rows no file accounts for: imported before uploads were kept, or from an example list
    const rowsWithoutFile = computed(() => props.bom?.rowsWithoutFile ?? 0);

    /*
     * The server's refusal, where it refused. It answers back() with an error rather than a 403
     * because the button is drawn from the same three rules - a mismatch between them is a thing the
     * person pressing it should be told about, not a thing the browser should swallow.
     */
    const deleteError = computed(() => usePage().props.errors?.materialListFile);

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

    /*
     * The file list's own three.
     */
    function readableSize(bytes){
        if(!bytes){
            return '';
        }

        return bytes < 1024 * 1024
            ? Math.max(1, Math.round(bytes / 1024)) + ' KB'
            : (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function rowLabel(file){
        return file.rowCount.toLocaleString() + (file.rowCount === 1 ? ' material' : ' materials');
    }

    //The day it was uploaded, not the hour: this is a list of revisions, not an audit log
    function uploadedLabel(file){
        const parts = [];

        if(file.uploadedBy){
            parts.push(shared.capitalizeWords(file.uploadedBy));
        }

        if(file.uploadedAt){
            parts.push(new Date(file.uploadedAt).toLocaleDateString(undefined, {
                day: 'numeric',
                month: 'short',
                year: 'numeric',
            }));
        }

        return parts.join(' · ');
    }

    //A new tab rather than a fetch: this is a streamed download off the private disk
    function downloadFile(file){
        window.open(route('material.list.file.download', file.id), '_blank');
    }

    /**
     * Take the upload, and everything that came out of it, off the job.
     *
     * Confirmed first and with the count in the question, because this is the one button on an
     * otherwise read-only screen and what it removes is not the file - it is that many lengths of
     * steel off the batch. The server refuses it again on its own terms; see the controller.
     */
    function removeFile(file){
        askToConfirm({
            title: 'Remove ' + file.filename + '?',
            message: 'This takes the file and the ' + rowLabel(file) + ' it imported off '
                + file.project + '. Nothing of it stays on the batch.',
            note: 'You can upload the file again afterwards.',
            confirmLabel: 'Remove file',
            tone: 'danger',
            onConfirmed: () => {
                deletingId.value = file.id;

                router.delete(route('material.list.file.destroy', file.id), {
                    preserveScroll: true,
                    //The table, the file list and the card's cut count all move together
                    onSuccess: () => emit('refresh'),
                    onFinish: () => deletingId.value = null,
                });
            },
        });
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

            <!--
                Everything the fetch brought back, in one branch: the files and the table are two
                halves of the same answer, and each has its own empty state inside here.
            -->
            <template v-else>

            <!--
                The uploads behind the table below.
                Above it rather than under it, because this is the half somebody can act on, and a
                row of buttons found by scrolling past two hundred lengths of steel is a row of
                buttons nobody finds. Drawn whenever the fetch landed - a batch with files and no
                materials left is exactly the state a half-finished delete leaves behind, and it
                should be visible.
            -->
            <section v-if="files.length > 0" class="mt-5">
                <h4 class="text-xs font-semibold tracking-wide text-gray-500 uppercase">
                    Uploaded files
                </h4>

                <!--
                    Two across, because a batch gathers a file per job and this list sits above the
                    table it explains - a dozen uploads stacked one per row push the materials off
                    the screen. One column on a narrow window, where the name and the line under it
                    need the width.
                -->
                <ul class="grid gap-1.5 mt-2 sm:grid-cols-2">
                    <li
                        v-for="file in files"
                        :key="file.id"
                        class="p-2 bg-white border border-gray-200 rounded-lg"
                    >
                        <div class="flex items-center gap-2">
                            <i class="flex-none text-gray-400 fa-regular fa-file-excel"></i>

                            <!--
                                The name, and under it which job it was uploaded against - a batch
                                carries several, and "beams.xlsx" means nothing without that.
                            -->
                            <div class="flex-1 min-w-0">
                                <button
                                    v-if="file.downloadable"
                                    type="button"
                                    @click="downloadFile(file)"
                                    class="block w-full text-sm text-left text-blue-700 underline truncate hover:text-blue-800"
                                    :title="'Download ' + file.filename"
                                >
                                    {{ file.filename }}
                                </button>
                                <!--
                                    A row can outlive its file: the disk refused it on upload, or was
                                    cleared out from under it. Still listed, because its materials are
                                    on the batch and removing them is still the thing to offer.
                                -->
                                <span
                                    v-else
                                    :title="file.filename + ' is no longer stored, so it cannot be opened'"
                                    class="block text-sm text-gray-700 truncate"
                                >
                                    {{ file.filename }}
                                </span>

                                <span class="block text-xs text-gray-500 truncate">
                                    {{ file.project }} · {{ rowLabel(file) }}
                                    <template v-if="uploadedLabel(file)">
                                        · {{ uploadedLabel(file) }}
                                    </template>
                                    <template v-if="readableSize(file.size_bytes)">
                                        · {{ readableSize(file.size_bytes) }}
                                    </template>
                                </span>
                            </div>

                            <button
                                v-if="file.deletable"
                                type="button"
                                @click="removeFile(file)"
                                :disabled="deletingId === file.id"
                                class="flex-none rounded px-1.5 py-0.5 text-xs font-semibold text-red-700 hover:bg-red-50 disabled:cursor-not-allowed disabled:text-gray-400"
                                :title="'Remove ' + file.filename + ' and its materials'"
                            >
                                {{ deletingId === file.id ? 'Removing...' : 'Remove' }}
                            </button>
                        </div>

                        <!--
                            And when it cannot go, why - in place of the button rather than beside a
                            disabled one, because "already on order" is the answer to the question
                            somebody is about to ask and a greyed-out button is not.
                        -->
                        <p v-if="!file.deletable" class="mt-1 text-xs italic text-gray-500">
                            {{ file.undeletableReason }}
                        </p>
                    </li>
                </ul>

                <!--
                    The table is the whole batch and this list is only what came off a spreadsheet, so
                    say when the two do not add up. Everything imported before uploads started being
                    kept is in here, and so are the example lists.
                -->
                <p v-if="rowsWithoutFile > 0" class="mt-2 text-xs text-gray-500">
                    {{ rowsWithoutFile.toLocaleString() }}
                    {{ rowsWithoutFile === 1 ? 'material was' : 'materials were' }}
                    imported before uploaded files were kept, so
                    {{ rowsWithoutFile === 1 ? 'it has' : 'they have' }}
                    no file here. Remove
                    {{ rowsWithoutFile === 1 ? 'it' : 'them' }}
                    from the project's own Bill of Materials.
                </p>

                <p v-if="deleteError" class="mt-2 text-xs font-medium text-red-600">
                    {{ deleteError }}
                </p>
            </section>

            <p v-if="rows.length === 0" class="py-16 text-center text-gray-600">
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
                                <!--
                                    And whose job it is. The project name alone does not say that on a
                                    batch carrying four colleagues' work, and this table is read to
                                    find out who to go and ask about a row.

                                    It replaced the assembly mark, which is a reference into the
                                    drawing the material came off - useful while working on one job,
                                    and unplaceable here without first knowing which job it belongs
                                    to. The per-project BOM still prints it.
                                -->
                                <th scope="col" class="px-4 py-3.5 text-sm font-normal text-left text-gray-500">
                                    Project Manager
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
                                <!--
                                    Capitalised the way the uploader's name is in the files list
                                    above, those being the same kind of fact about the same people -
                                    names are typed in at registration and arrive however they were
                                    typed. Empty where that account has since been deleted.
                                -->
                                <td class="px-4 py-4 text-sm font-medium text-gray-800 whitespace-nowrap">
                                    {{ row.project_manager ? shared.capitalizeWords(row.project_manager) : '' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            </template>
        </div>

        <!-- Teleported out from under this modal - see ConfirmModal -->
        <ConfirmModal
            v-if="confirmDialog"
            :title="confirmDialog.title"
            :message="confirmDialog.message"
            :note="confirmDialog.note"
            :confirmLabel="confirmDialog.confirmLabel"
            :tone="confirmDialog.tone"
            @confirm="confirmDialogAccepted()"
            @cancel="confirmDialogCancelled()"
        />
    </Modal>
</template>
