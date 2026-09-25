<script setup>
    //Component Imports
    import Modal from "@/Layouts/Modal.vue";
    import {computed} from "vue";

    //Props
    const props = defineProps({
        title: String,
        message: String,
        confirmLabel: {
            type: String,
            default: "Confirm",
        },
        cancelLabel: {
            type: String,
            default: "Cancel",
        },
        /**
         * "danger" for anything destructive, "primary" for the rest. Only decides
         * the icon and the confirm button's colour.
         */
        tone: {
            type: String,
            default: "danger",
        },
    });

    //Variables
    const emit = defineEmits(['confirm','cancel']);

    //Computed
    const isDanger = computed(() => props.tone === 'danger');
</script>

<template>
    <!--
        Teleported because this is often opened from inside another modal, whose
        panel is a transformed element and would otherwise trap the backdrop. The
        wrapper's stacking context keeps it above whatever opened it.
    -->
    <Teleport to="body">
    <div class="relative z-[60]">
    <Modal
        :labelledby="'confirm-modal-title'"
        @closeModal="$emit('cancel')"
    >
        <!-- Fits the viewport on a phone instead of overflowing a fixed 32rem -->
        <div class="px-4 sm:px-6 sm:flex sm:items-start" style="max-width:min(32rem, calc(100vw - 2rem))">
            <!-- icon -->
            <div
                class="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full sm:mx-0 sm:h-10 sm:w-10"
                :class="isDanger ? 'bg-red-100 dark:bg-red-900/40' : 'bg-blue-100 dark:bg-blue-900/40'"
            >
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke-width="1.5"
                    stroke="currentColor"
                    class="h-6 w-6"
                    :class="isDanger ? 'text-red-600 dark:text-red-400' : 'text-blue-600 dark:text-blue-400'"
                    aria-hidden="true"
                >
                    <path
                        v-if="isDanger"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"
                    />
                    <path
                        v-else
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z"
                    />
                </svg>
            </div>

            <!-- copy -->
            <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left">
                <h3
                    id="confirm-modal-title"
                    class="text-lg font-semibold leading-6 text-gray-900 dark:text-gray-100"
                >
                    {{ title }}
                </h3>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    {{ message }}
                </p>
            </div>
        </div>

        <template #footer>
            <button
                type="button"
                class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 text-base font-medium text-white focus:outline-none focus:ring-2 focus:ring-offset-2 sm:ml-3 sm:w-auto sm:text-sm"
                :class="isDanger
                    ? 'bg-red-600 hover:bg-red-700 focus:ring-red-500'
                    : 'bg-blue-600 hover:bg-blue-700 focus:ring-blue-500'"
                @click="$emit('confirm')"
            >
                {{ confirmLabel }}
            </button>
            <!-- Cancel takes the focus, so a stray Enter cannot confirm -->
            <button
                type="button"
                data-modal-autofocus
                class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 dark:border-gray-600 shadow-sm px-4 py-2 bg-white dark:bg-gray-900 text-base font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:w-auto sm:text-sm"
                @click="$emit('cancel')"
            >
                {{ cancelLabel }}
            </button>
        </template>
    </Modal>
    </div>
    </Teleport>
</template>
