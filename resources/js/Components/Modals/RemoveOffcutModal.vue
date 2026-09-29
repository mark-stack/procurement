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
         * The row being taken out of inventory. Its mark and section are shown back before
         * anything is confirmed: the list this was opened from can hold two 2400mm offcuts of the
         * same section that differ only by the letters stamped on them, so the one certainty
         * worth giving is which piece of steel this is about.
         */
        offcut: {
            type: Object,
            required: true,
        },
        /**
         * [{value, label, hint, requiresNote}] - worded server side, in App\Enums\OffcutRemovalEnums,
         * so the picker and the record of what was picked cannot drift apart.
         */
        reasons: {
            type: Array,
            required: true,
        },
    });

    //Variables
    const emit = defineEmits(['removed','cancel']);
    const noteInput = ref(null);

    //Form
    const form = useForm({
        reason: null,
        note: '',
    });

    //Computed
    const chosen = computed(() => props.reasons.find(reason => reason.value === form.reason) ?? null);

    //The server insists on a note for "Other", so say so before the round trip rather than after it
    const noteRequired = computed(() => chosen.value?.requiresNote === true);

    const canSubmit = computed(() => {
        if(form.processing || form.reason === null){
            return false;
        }

        return !noteRequired.value || form.note.trim() !== '';
    });

    //Methods
    function submit(){
        if(!canSubmit.value){
            return;
        }

        form.post(route('offcuts.remove', props.offcut.id), {
            preserveScroll: true,
            onSuccess: () => emit('removed'),
        });
    }

    //A reason picked by keyboard should land the caret where the sentence continues
    watch(() => noteRequired.value, (required) => {
        if(required){
            noteInput.value?.focus();
        }
    });
</script>

<template>
    <Modal labelledby="remove-offcut-title" @closeModal="$emit('cancel')">
        <div class="px-4 sm:px-6" style="max-width:min(34rem, calc(100vw - 2rem))">
            <h3
                id="remove-offcut-title"
                class="text-lg font-semibold leading-6 text-gray-900 dark:text-gray-100"
            >
                Take this offcut out of inventory
            </h3>

            <!--
                The steel itself, before the question. Mark first because that is what is written
                on the end of the bar and what somebody standing at the rack is reading back.
            -->
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                <span class="font-mono font-semibold">{{ offcut.unique_mark }}</span>
                &mdash; {{ (offcut.label || '').trim() }}, {{ offcut.length }}mm
            </p>

            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                No nest will offer this piece again, and the next batch that needs this section will
                buy the steel instead of promising material that is not in the yard. Nothing is
                deleted: it keeps its mark, its certificates stay on anything cut from it, and you
                can put it back from the Removed list.
            </p>

            <fieldset class="mt-4">
                <legend class="text-sm font-medium text-gray-700 dark:text-gray-200">
                    What happened to it?
                </legend>

                <div class="mt-2 space-y-1">
                    <label
                        v-for="reason in reasons"
                        :key="reason.value"
                        class="flex gap-x-3 items-start rounded-md border p-2.5 cursor-pointer transition-colors"
                        :class="form.reason === reason.value
                            ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/30 dark:border-blue-500'
                            : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800'"
                    >
                        <input
                            v-model="form.reason"
                            type="radio"
                            name="offcut-removal-reason"
                            :value="reason.value"
                            class="mt-1 text-blue-600 border-gray-300 dark:bg-gray-900 dark:border-gray-600"
                        >
                        <span>
                            <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">
                                {{ reason.label }}
                            </span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">
                                {{ reason.hint }}
                            </span>
                        </span>
                    </label>
                </div>

                <InputError class="mt-2" :message="form.errors.reason"/>
            </fieldset>

            <div class="mt-4">
                <label
                    for="offcut-removal-note"
                    class="block text-sm font-medium text-gray-700 dark:text-gray-200"
                >
                    Note
                    <span class="font-normal text-gray-500 dark:text-gray-400">
                        {{ noteRequired ? '(required)' : '(optional)' }}
                    </span>
                </label>

                <!--
                    Who took it and what for is the part that settles an argument a month later,
                    and it is the part only the person recording the removal knows.
                -->
                <textarea
                    id="offcut-removal-note"
                    ref="noteInput"
                    v-model="form.note"
                    rows="2"
                    maxlength="255"
                    placeholder="e.g. Dave cut it up for the Jarrah St handrails"
                    class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm focus:border-blue-500 focus:ring-blue-500"
                ></textarea>

                <InputError class="mt-1" :message="form.errors.note"/>
            </div>
        </div>

        <template #footer>
            <button
                type="button"
                :disabled="!canSubmit"
                class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-red-600 text-base font-medium text-white hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 disabled:opacity-50 disabled:cursor-not-allowed sm:ml-3 sm:w-auto sm:text-sm"
                @click="submit()"
            >
                {{ form.processing ? 'Removing...' : 'Remove from inventory' }}
            </button>
            <!-- Cancel takes the focus, so a stray Enter cannot confirm -->
            <button
                type="button"
                data-modal-autofocus
                class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 dark:border-gray-600 shadow-sm px-4 py-2 bg-white dark:bg-gray-900 text-base font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:w-auto sm:text-sm"
                @click="$emit('cancel')"
            >
                Cancel
            </button>
        </template>
    </Modal>
</template>
