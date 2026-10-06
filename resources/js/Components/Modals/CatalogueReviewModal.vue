<script setup>
    //General Imports
    import {useForm} from "@inertiajs/vue3";

    //Component Imports
    import Modal from "@/Layouts/Modal.vue";
    import InputError from "@/Components/InputError.vue";

    //Props
    const props = defineProps({
        /**
         * The trust report as the screen is currently showing it: {products, outstanding, accepted,
         * reasons: [{reason, label, hint, outstanding, accepted}]}.
         *
         * Shown back inside the dialog on purpose. What gets written down is counted on the server
         * at the moment of the review, not taken from here - but somebody is about to put their name
         * to a reading, and they should see the reading they are putting it to.
         */
        trust: {
            type: Object,
            required: true,
        },
    });

    //Variables
    const emit = defineEmits(["recorded", "cancel"]);

    //Form
    const form = useForm({
        note: "",
    });

    //Methods
    function submit(){
        form.post(route("admin.materials.reviewed"), {
            preserveScroll: true,
            onSuccess: () => emit("recorded"),
        });
    }
</script>

<template>
    <Modal labelledby="catalogue-review-title" @closeModal="$emit('cancel')">
        <div class="px-4 sm:px-6" style="max-width:min(34rem, calc(100vw - 2rem))">
            <h3 id="catalogue-review-title" class="text-lg font-semibold leading-6 text-gray-900">
                Record a catalogue review
            </h3>

            <p class="mt-3 text-sm text-gray-500">
                This writes down that you have been through the catalogue today, and what was still
                outstanding when you did. It changes nothing about the products themselves &mdash;
                nothing is corrected, deprecated or held back by it.
            </p>

            <!--
                The reading being signed off. Counted again on the server when this is submitted, so
                a row corrected in another tab while this box was open is counted as corrected.
            -->
            <div class="px-4 py-3 mt-4 text-sm border border-gray-200 rounded-lg bg-gray-50">
                <p class="text-gray-700">
                    <b>{{ trust.products }}</b> active products,
                    <b>{{ trust.outstanding }}</b> of them carrying something unresolved.
                </p>

                <ul class="mt-2 space-y-0.5">
                    <li
                        v-for="reason in trust.reasons"
                        :key="reason.reason"
                        class="text-xs"
                        :class="reason.outstanding > 0 ? 'text-amber-800' : 'text-gray-400'"
                    >
                        {{ reason.outstanding }} &middot; {{ reason.label }}
                    </li>
                </ul>
            </div>

            <div class="mt-4">
                <label for="catalogue-review-note" class="block text-sm font-medium text-gray-700">
                    Note <span class="font-normal text-gray-500">(optional)</span>
                </label>

                <!--
                    Optional, and meant to stay optional. Looking is the record; an empty box should
                    never be the reason somebody does not press the button.
                -->
                <textarea
                    id="catalogue-review-note"
                    v-model="form.note"
                    rows="3"
                    maxlength="2000"
                    placeholder="e.g. Checked every CHS mass against the Southern Steel guide, corrected four"
                    class="block w-full mt-1 text-sm border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500"
                ></textarea>

                <InputError class="mt-1" :message="form.errors.note"/>
            </div>
        </div>

        <template #footer>
            <button
                type="button"
                :disabled="form.processing"
                class="inline-flex justify-center w-full px-4 py-2 text-base font-medium text-white bg-blue-600 border border-transparent rounded-md shadow-sm hover:bg-blue-700 disabled:opacity-50 sm:ml-3 sm:w-auto sm:text-sm"
                @click="submit()"
            >
                {{ form.processing ? "Recording..." : "Record review" }}
            </button>

            <button
                type="button"
                data-modal-autofocus
                class="inline-flex justify-center w-full px-4 py-2 mt-3 text-base font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 sm:mt-0 sm:w-auto sm:text-sm"
                @click="$emit('cancel')"
            >
                Cancel
            </button>
        </template>
    </Modal>
</template>
