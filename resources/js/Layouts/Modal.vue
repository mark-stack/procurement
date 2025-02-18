<script setup>
    //General Imports
    import {ref} from "vue";
    import {Link} from "@inertiajs/vue3";

    //Props
    const props = defineProps({
        fakeModal: Boolean,
        redirect: String,
    });

    //Variables
    const emit = defineEmits(['closeModal']);
    const clickCount = ref(0);
    const loadingButton = ref(false);

    //Methods
    //
</script>

<template>

    <!-- Modal (https://codepen.io/npmhieu/pen/mdxaEbE?editors=1000) -->
    <div id="basicModal">
        <div
            class="relative z-10"
            aria-labelledby="modal-title"
            aria-modal="true"
        >
            <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"></div>

            <div class="fixed z-10 inset-0 overflow-y-auto">
                <div class="flex items-end sm:items-center justify-center min-h-full p-4 text-center sm:p-0">
                    <!-- sm:max-w-3xl sm:w-full  -->
                    <div class="relative bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8">
                        <div class="bg-white pt-5 pb-4 sm:pb-4">
                            <!-- content -->
                            <slot/>
                        </div>
                        <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                            <Link
                                v-if="fakeModal"
                                type="button"
                                class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm"
                                :href="redirect === 'current' ? route('dashboard') : route('past.projects.index')"
                                @click="loadingButton = true"
                            >
                                {{ loadingButton ? 'Closing...' : 'Back to projects'}}
                            </Link>

                            <button
                                v-else
                                type="button"
                                class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm"
                                @click="$emit('closeModal'); clickCount = 0;"
                            >
                                Close
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
