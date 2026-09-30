<script setup>
    //General Imports
    import {computed, ref, watch} from "vue";
    import {useForm} from "@inertiajs/vue3";

    //Component Imports
    import Modal from "@/Layouts/Modal.vue";

    //Props
    const props = defineProps({
        show: Boolean,
        /**
         * One row of the quotes/orders table: {info, formOrderUpdate, materialCertificateFiles}.
         * Null between openings.
         */
        row: Object,
        supplierName: String,
    });

    //Forms
    const formUpload = useForm({
        certificates: [],
    });

    /*
     * The written reference and nothing else. orders.update leaves alone any field the request does
     * not carry, so keeping the PO number out of this form is what stops saving a reference from
     * writing back a PO number that was read off the row before somebody else changed it.
     */
    const formReference = useForm({
        material_cert_numbers: null,
    });

    const formDeleteFile = useForm({});

    //Variables
    const emit = defineEmits(['closeModal','refresh']);
    const fileInput = ref(null);
    const deletingId = ref(null);

    //Computed
    const orderId = computed(() => props.row?.formOrderUpdate?.order_id ?? null);
    const files = computed(() => props.row?.materialCertificateFiles ?? []);
    const savedReference = computed(() => props.row?.formOrderUpdate?.material_cert_numbers ?? null);

    /**
     * Either one on its own is enough. The point of saying so out loud is that the old cell offered
     * one box labelled "Material Certs" and people typed a filename into it, believing the PDF had
     * gone somewhere.
     */
    const isCertified = computed(() => files.value.length > 0 || !!savedReference.value);

    const referenceChanged = computed(
        () => (formReference.material_cert_numbers ?? '') !== (savedReference.value ?? '')
    );

    //Methods
    function resetFromRow(){
        formUpload.reset();
        formUpload.clearErrors();

        formReference.material_cert_numbers = savedReference.value;
        formReference.clearErrors();
    }

    function chooseFiles(){
        fileInput.value?.click();
    }

    function filesPicked(event){
        const picked = Array.from(event.target.files ?? []);

        if(picked.length === 0){
            return;
        }

        formUpload.certificates = picked;

        /*
         * Uploaded straight away rather than held until a Save. A file sitting in an unsubmitted
         * input looks attached and is not, which is the same misunderstanding this whole screen
         * exists to clear up.
         */
        formUpload.post(route('material.certificates.store', orderId.value), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                formUpload.reset();
                emit('refresh');
            },
            //Let the same file be picked again after a failure
            onFinish: () => {
                if(fileInput.value){
                    fileInput.value.value = '';
                }
            },
        });
    }

    function removeFile(file){
        deletingId.value = file.id;

        formDeleteFile.delete(route('material.certificates.destroy', file.id), {
            preserveScroll: true,
            onSuccess: () => emit('refresh'),
            onFinish: () => deletingId.value = null,
        });
    }

    function downloadFile(file){
        window.open(route('material.certificates.download', file.id), '_blank');
    }

    function saveReference(){
        formReference.put(route('orders.update', orderId.value), {
            preserveScroll: true,
            onSuccess: () => emit('refresh'),
        });
    }

    function clearReference(){
        formReference.material_cert_numbers = null;
        saveReference();
    }

    function readableSize(bytes){
        if(!bytes){
            return '';
        }

        return bytes < 1024 * 1024
            ? Math.max(1, Math.round(bytes / 1024)) + ' KB'
            : (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    //Watchers
    //A fresh fetch replaces the row object, so the text box has to be re-seeded from it
    watch(() => props.row, () => resetFromRow(), {immediate: true});
    watch(() => props.show, (isOpen) => {
        if(isOpen){
            resetFromRow();
        }
    });
</script>

<template>
    <Modal
        v-if="show"
        :fakeModal="false"
        labelledby="material-certs-title"
        @closeModal="$emit('closeModal')"
    >
        <!-- header -->
        <div class="px-5 pb-3 pt-2">
            <h3 id="material-certs-title" class="text-xl font-medium leading-6 text-gray-900">
                Material certificates
            </h3>
            <p class="mt-1 text-sm text-gray-500">
                {{ supplierName }}
            </p>
        </div>

        <!-- body -->
        <div
            :style="{width: 'min(620px, calc(100vw - 2rem))'}"
            class="max-h-[70vh] overflow-y-auto px-5 pb-5"
        >
            <!--
                Says the thing the old single text box could not. Two ways in, either is enough, and
                the difference between them is whether the certificate itself is held here or
                somewhere else.
            -->
            <p class="rounded-lg bg-gray-50 p-3 text-xs leading-relaxed text-gray-600">
                Keep the certificate here, or record where it is filed. Either one marks this
                delivery traceable — you do not need both.
            </p>

            <!-- attach files -->
            <section class="mt-4">
                <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                    Attach the certificate
                </h4>
                <p class="mt-1 text-xs text-gray-500">
                    The PDF or scan the merchant sent. Stored against this order and downloadable by
                    anyone in your business.
                </p>

                <!-- already attached -->
                <ul v-if="files.length > 0" class="mt-2.5 space-y-1.5">
                    <li
                        v-for="file in files"
                        :key="file.id"
                        class="flex items-center gap-2 rounded-lg border border-gray-200 bg-white p-2"
                    >
                        <i class="fa-regular fa-file-lines flex-none text-gray-400"></i>
                        <button
                            type="button"
                            @click="downloadFile(file)"
                            class="min-w-0 flex-1 truncate text-left text-sm text-blue-700 underline hover:text-blue-800"
                            :title="'Download ' + file.filename"
                        >
                            {{ file.filename }}
                        </button>
                        <span class="flex-none text-xs tabular-nums text-gray-400">
                            {{ readableSize(file.size_bytes) }}
                        </span>
                        <button
                            type="button"
                            @click="removeFile(file)"
                            :disabled="deletingId === file.id"
                            class="flex-none rounded px-1.5 py-0.5 text-xs font-semibold text-red-700 hover:bg-red-50 disabled:cursor-not-allowed disabled:text-gray-400"
                            :title="'Remove ' + file.filename"
                        >
                            {{ deletingId === file.id ? 'Removing...' : 'Remove' }}
                        </button>
                    </li>
                </ul>

                <!-- add more -->
                <input
                    ref="fileInput"
                    type="file"
                    multiple
                    accept=".pdf,.png,.jpg,.jpeg,.webp,.heic,.tif,.tiff"
                    class="hidden"
                    @change="filesPicked"
                />
                <button
                    type="button"
                    @click="chooseFiles()"
                    :disabled="formUpload.processing"
                    class="mt-2.5 inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:text-gray-400"
                >
                    <i class="fa-solid fa-paperclip text-xs"></i>
                    {{ formUpload.processing
                        ? 'Uploading...'
                        : (files.length > 0 ? 'Attach another' : 'Choose files') }}
                </button>
                <p class="mt-1.5 text-xs text-gray-400">
                    PDF or image, up to 20MB each.
                </p>

                <p v-if="formUpload.errors.certificates" class="mt-1.5 text-xs font-medium text-red-600">
                    {{ formUpload.errors.certificates }}
                </p>
                <!-- Laravel keys per-file failures by index, so surface the first one -->
                <p v-if="formUpload.errors['certificates.0']" class="mt-1.5 text-xs font-medium text-red-600">
                    {{ formUpload.errors['certificates.0'] }}
                </p>
            </section>

            <!-- or -->
            <div class="my-5 flex items-center gap-3">
                <span class="h-px flex-1 bg-gray-200"></span>
                <span class="text-xs font-semibold uppercase tracking-wide text-gray-400">or</span>
                <span class="h-px flex-1 bg-gray-200"></span>
            </div>

            <!-- reference -->
            <section>
                <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                    Record a reference
                </h4>
                <p class="mt-1 text-xs text-gray-500">
                    For certificates kept elsewhere — heat or cast numbers, a certificate number, or
                    where the paper copy is filed. Text only; this does not hold a file.
                </p>

                <label for="material-cert-reference" class="sr-only">Certificate reference</label>
                <input
                    id="material-cert-reference"
                    v-model="formReference.material_cert_numbers"
                    type="text"
                    class="mt-2.5 block h-10 w-full rounded-lg border-gray-300 text-sm text-gray-900 shadow-sm placeholder:text-gray-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/30"
                    placeholder="e.g. Heat 214887 / cert 4471-B"
                    @keyup.enter="referenceChanged && saveReference()"
                />

                <div class="mt-2 flex items-center gap-3">
                    <button
                        type="button"
                        @click="saveReference()"
                        :disabled="!referenceChanged || formReference.processing"
                        class="inline-flex items-center rounded-lg bg-blue-700 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-800 disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-400 disabled:shadow-none"
                    >
                        {{ formReference.processing ? 'Saving...' : 'Save reference' }}
                    </button>
                    <button
                        v-if="savedReference && !referenceChanged"
                        type="button"
                        @click="clearReference()"
                        class="text-xs font-semibold text-gray-500 underline hover:text-gray-700"
                    >
                        Clear
                    </button>
                </div>

                <p v-if="formReference.errors.material_cert_numbers" class="mt-1.5 text-xs font-medium text-red-600">
                    {{ formReference.errors.material_cert_numbers }}
                </p>
            </section>

            <!--
                The board hides "Move to done" behind a missing-certs warning on steel, so the state
                this order is actually in is worth stating here rather than leaving to be inferred
                from two empty sections.
            -->
            <p
                v-if="isCertified"
                class="mt-5 flex gap-2 rounded-lg border border-green-200 bg-green-50 p-2.5 text-xs leading-relaxed text-green-800"
            >
                <i class="fa-solid fa-circle-check mt-0.5 flex-none text-green-600"></i>
                <span>This delivery is recorded as traceable.</span>
            </p>
            <p
                v-else
                class="mt-5 flex gap-2 rounded-lg border border-orange-200 bg-orange-50 p-2.5 text-xs leading-relaxed text-orange-800"
            >
                <i class="fa-solid fa-triangle-exclamation mt-0.5 flex-none text-orange-500"></i>
                <span>
                    Nothing recorded yet. Steel offcuts cut from this delivery will have no
                    certificate trail behind them.
                </span>
            </p>
        </div>

        <template #footer>
            <button
                type="button"
                @click="$emit('closeModal')"
                class="mt-3 inline-flex w-full justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-base font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 sm:ml-3 sm:mt-0 sm:w-auto sm:text-sm"
            >
                Done
            </button>
        </template>
    </Modal>
</template>
