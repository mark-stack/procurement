<script setup>
    /**
     * The mill certificates for a batch, a merchant at a time.
     *
     * It carried the press that said the steel had arrived as well, both in the one place because
     * they were the one moment: somebody standing at the rack with a delivery in front of them and
     * an email from the merchant open. That mark is made on the order list now, a merchant at a time
     * like the file is, so this modal is the paperwork and nothing else - and the two are still the
     * one moment, in the one place, a block apart.
     *
     * A block per supplier group, and only the groups whose material comes with a certificate at all
     * - steel does, a bolt does not (products.certificates, the flag the BOM reads). A certificate
     * belongs to the merchant who supplied the steel, so filing one against the whole batch would
     * leave somebody chasing a heat number through a pile.
     *
     * These hang off the batch rather than off an order, which is the whole point of them: a shop
     * that rings the merchant has no order row for the file to belong to. They show in the order
     * list's Certificates tab for the same block, beside any that came in against a real order - see
     * BatchCertificateController and BatchOrderListController.
     *
     * Attaching one marks nothing. Plenty of merchants send the PDF days after the truck, which is
     * why the two were never conditional on each other even when they shared a modal.
     */
    //General Imports
    import {computed, ref, watch} from "vue";
    import {useForm} from "@inertiajs/vue3";
    import axios from "axios";

    //Component Imports
    import Modal from "@/Layouts/Modal.vue";

    //Shared methods
    import shared from "@/Shared/shared.js";

    //Props
    const props = defineProps({
        show: Boolean,
        //One card off the Nesting page, or null between openings
        batch: Object,
    });

    //Forms
    const formUpload = useForm({
        supplier_group: null,
        certificates: [],
    });

    const formDeleteFile = useForm({});

    //Variables
    const emit = defineEmits(['closeModal']);

    /*
     * One hidden file input per group, keyed by supplier group - the picker has to know which
     * merchant it is attaching for before the files are chosen, and a single shared input would mean
     * holding that answer in a second place.
     */
    const fileInputs = ref({});
    const groups = ref([]);
    const loading = ref(false);
    const loadFailed = ref(false);
    //Which group is mid-upload, so only its own button says so
    const uploadingGroup = ref(null);
    const deletingId = ref(null);
    const deleteError = ref(null);

    //Computed
    const batchId = computed(() => props.batch?.id ?? null);

    const projectNames = computed(() => (props.batch?.projects ?? [])
        .map(project => project.name)
        .join(', '));

    //Methods
    async function loadCertificates(){
        if(batchId.value === null){
            return;
        }

        loading.value = true;
        loadFailed.value = false;

        try{
            const response = await axios.get(route('batch.certificates.index', batchId.value));

            //Only accept the answer for the batch still on screen - a late reply belongs to nobody
            if(response.data.batch_id === batchId.value){
                groups.value = response.data.groups ?? [];
            }
        }catch(error){
            console.error('Error fetching data:', error);
            loadFailed.value = true;
        }

        loading.value = false;
    }

    function chooseFiles(group){
        fileInputs.value[group.supplierGroup]?.click();
    }

    function filesPicked(group, event){
        const picked = Array.from(event.target.files ?? []);

        if(picked.length === 0){
            return;
        }

        formUpload.supplier_group = group.supplierGroup;
        formUpload.certificates = picked;
        uploadingGroup.value = group.supplierGroup;

        /*
         * Uploaded straight away rather than held until a Save, the way the order side does it. A
         * file sitting in an unsubmitted input looks attached and is not.
         */
        formUpload.post(route('batch.certificates.store', batchId.value), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                formUpload.reset();
                loadCertificates();
            },
            //Let the same file be picked again after a failure
            onFinish: () => {
                uploadingGroup.value = null;

                if(fileInputs.value[group.supplierGroup]){
                    fileInputs.value[group.supplierGroup].value = '';
                }
            },
        });
    }

    function removeFile(file){
        deletingId.value = file.id;
        deleteError.value = null;

        formDeleteFile.delete(route('material.certificates.destroy', file.id), {
            preserveScroll: true,
            onSuccess: () => loadCertificates(),
            //The refusal is a sentence about what to do instead, so it is shown rather than swallowed
            onError: (errors) => deleteError.value = errors.certificate ?? null,
            onFinish: () => deletingId.value = null,
        });
    }

    function downloadFile(file){
        window.open(route('material.certificates.download', file.id), '_blank');
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
    watch(() => props.show, (isOpen) => {
        if(isOpen){
            formUpload.reset();
            formUpload.clearErrors();
            deleteError.value = null;
            groups.value = [];
            loadCertificates();
        }
    }, {immediate: true});
</script>

<template>
    <Modal
        v-if="show"
        :fakeModal="false"
        labelledby="batch-certs-title"
        @closeModal="$emit('closeModal')"
    >
        <!-- header -->
        <div class="px-5 pt-2 pb-3">
            <h3 id="batch-certs-title" class="text-xl font-medium leading-6 text-gray-900">
                Mill certificates
            </h3>
            <p class="mt-1 text-sm text-gray-500">
                Batch #{{ batch?.id }}<template v-if="projectNames"> · {{ projectNames }}</template>
            </p>
        </div>

        <!-- body -->
        <div
            :style="{width: 'min(620px, calc(100vw - 2rem))'}"
            class="max-h-[70vh] overflow-y-auto px-5 pb-5"
        >
            <p class="p-3 text-xs leading-relaxed text-gray-600 rounded-lg bg-gray-50">
                The certificates that came in with this batch's material, by the merchant who
                supplied it. Kept on the private disk, and shown on the same merchant's block in the
                order list.
            </p>

            <p v-if="loading" class="py-8 text-sm text-center text-gray-500">
                <i class="fa-solid fa-circle-notch fa-spin"></i>
                Loading...
            </p>

            <p v-else-if="loadFailed" class="py-8 text-sm text-center text-gray-600">
                We could not load this batch's certificates. Close this and try again.
            </p>

            <!--
                No merchant on this batch supplies anything that comes with a certificate - an
                all-fasteners job. Said out loud, because an empty modal reads as one that failed.
            -->
            <p v-else-if="groups.length === 0" class="py-8 text-sm text-center text-gray-600">
                Nothing on this batch comes with a mill certificate.
            </p>

            <!-- a block per merchant whose material comes with a certificate -->
            <section
                v-for="group in groups"
                v-else
                :key="group.supplierGroup"
                class="p-3 mt-4 border border-gray-200 rounded-xl"
            >
                <h4 class="text-xs font-semibold tracking-wide text-gray-500 uppercase">
                    {{ shared.supplierGroupLabel(group.supplierGroup) }}
                </h4>

                <ul v-if="group.certificates.length > 0" class="mt-2.5 space-y-1.5">
                    <li
                        v-for="certificate in group.certificates"
                        :key="certificate.id"
                        class="flex items-center gap-2 p-2 bg-white border border-gray-200 rounded-lg"
                    >
                        <i class="flex-none text-gray-400 fa-regular fa-file-lines"></i>

                        <div class="flex-1 min-w-0">
                            <button
                                type="button"
                                @click="downloadFile(certificate)"
                                class="block w-full text-sm text-left text-blue-700 underline truncate hover:text-blue-800"
                                :title="'Download ' + certificate.filename"
                            >
                                {{ certificate.filename }}
                            </button>
                            <!-- Who attached it and when, which is half of what a trail is for -->
                            <span class="block text-xs text-gray-500 truncate">
                                <template v-if="certificate.uploaded_by">
                                    {{ certificate.uploaded_by }} ·
                                </template>
                                {{ certificate.uploaded_at }}
                                <template v-if="readableSize(certificate.size_bytes)">
                                    · {{ readableSize(certificate.size_bytes) }}
                                </template>
                            </span>
                        </div>

                        <button
                            v-if="certificate.deletable"
                            type="button"
                            @click="removeFile(certificate)"
                            :disabled="deletingId === certificate.id"
                            class="flex-none rounded px-1.5 py-0.5 text-xs font-semibold text-red-700 hover:bg-red-50 disabled:cursor-not-allowed disabled:text-gray-400"
                            :title="'Remove ' + certificate.filename"
                        >
                            {{ deletingId === certificate.id ? 'Removing...' : 'Remove' }}
                        </button>
                    </li>
                </ul>

                <p v-else class="mt-2 text-xs text-gray-500">
                    Nothing attached yet.
                </p>

                <!-- add more, for this merchant -->
                <input
                    :ref="element => fileInputs[group.supplierGroup] = element"
                    type="file"
                    multiple
                    accept=".pdf,.png,.jpg,.jpeg,.webp,.heic,.tif,.tiff"
                    class="hidden"
                    @change="event => filesPicked(group, event)"
                />
                <button
                    v-if="batch?.canAttachCertificates !== false"
                    type="button"
                    @click="chooseFiles(group)"
                    :disabled="formUpload.processing"
                    class="mt-2.5 inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:text-gray-400"
                >
                    <i class="text-xs fa-solid fa-paperclip"></i>
                    {{ uploadingGroup === group.supplierGroup
                        ? 'Uploading...'
                        : (group.certificates.length > 0 ? 'Attach another' : 'Choose files') }}
                </button>

                <template v-if="uploadingGroup === null && formUpload.supplier_group === group.supplierGroup">
                    <p v-if="formUpload.errors.certificates" class="mt-1.5 text-xs font-medium text-red-600">
                        {{ formUpload.errors.certificates }}
                    </p>
                    <!-- Laravel keys per-file failures by index, so surface the first one -->
                    <p v-if="formUpload.errors['certificates.0']" class="mt-1.5 text-xs font-medium text-red-600">
                        {{ formUpload.errors['certificates.0'] }}
                    </p>
                </template>
            </section>

            <p v-if="deleteError" class="mt-2 text-xs font-medium text-red-600">
                {{ deleteError }}
            </p>

            <p v-if="!loading && !loadFailed && groups.length > 0" class="mt-2 text-xs text-gray-400">
                PDF or image, up to 20MB each. Pick several at once.
            </p>
        </div>

        <template #footer>
            <!--
                One button, because there is nothing to decide here: the files are saved as they are
                attached. Done rather than Cancel for that reason - closing changes nothing.
            -->
            <button
                type="button"
                @click="$emit('closeModal')"
                class="inline-flex justify-center w-full px-4 py-2 mt-3 text-base font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 sm:ml-3 sm:mt-0 sm:w-auto sm:text-sm"
            >
                Done
            </button>
        </template>
    </Modal>
</template>
