<script setup>
    //General Imports
    import {computed, nextTick, onBeforeUnmount, onMounted, ref, watch} from "vue";
    import {Link} from "@inertiajs/vue3";

    //Props
    const props = defineProps({
        fakeModal: Boolean,
        redirect: String,
        /**
         * Consumers that render this with v-show stay mounted while hidden, so the
         * escape key and the focus trap have to know when they are actually on
         * screen. Consumers using v-if can leave this alone.
         */
        open: {
            type: Boolean,
            default: true,
        },
        /**
         * Labelling. Pass ariaLabel for a modal whose heading changes between steps,
         * or labelledby pointing at the id of a visible heading.
         */
        ariaLabel: String,
        labelledby: {
            type: String,
            default: "modal-title",
        },
    });

    //Variables
    const emit = defineEmits(['closeModal']);
    const loadingButton = ref(false);
    const panel = ref(null);
    let previouslyFocused = null;

    //Computed
    //An explicit label wins, otherwise point at the heading id
    const labelledBy = computed(() => (props.ariaLabel ? undefined : props.labelledby));

    //A "fake" modal closes by navigating, so there is nothing to dismiss
    const dismissible = computed(() => !props.fakeModal);

    //Methods
    function requestClose(){
        if(dismissible.value){
            emit('closeModal');
        }
    }

    function onBackdropClick(event){
        //Only a click on the backdrop itself, not one bubbling out of the panel
        if(event.target === event.currentTarget){
            requestClose();
        }
    }

    function focusableChildren(){
        if(!panel.value){
            return [];
        }

        return Array.from(panel.value.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )).filter(el => el.offsetParent !== null || el.getClientRects().length > 0);
    }

    function onKeydown(event){
        if(!props.open){
            return;
        }

        if(event.key === 'Escape'){
            requestClose();
            return;
        }

        //Keep tabbing inside the dialog
        if(event.key === 'Tab'){
            const focusable = focusableChildren();
            if(focusable.length === 0){
                return;
            }

            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if(event.shiftKey && document.activeElement === first){
                event.preventDefault();
                last.focus();
            }
            else if(!event.shiftKey && document.activeElement === last){
                event.preventDefault();
                first.focus();
            }
        }
    }

    async function takeFocus(){
        previouslyFocused = document.activeElement;

        await nextTick();

        const focusable = focusableChildren();
        (focusable[0] ?? panel.value)?.focus();
    }

    function restoreFocus(){
        previouslyFocused?.focus?.();
        previouslyFocused = null;
    }

    /*
    Lifecycle
     */
    onMounted(() => {
        document.addEventListener('keydown', onKeydown);

        if(props.open){
            takeFocus();
        }
    });

    onBeforeUnmount(() => {
        document.removeEventListener('keydown', onKeydown);
        restoreFocus();
    });

    watch(() => props.open, (isOpen) => {
        isOpen ? takeFocus() : restoreFocus();
    });
</script>

<template>

    <!-- Modal (https://codepen.io/npmhieu/pen/mdxaEbE?editors=1000) -->
    <div id="basicModal">
        <div
            class="relative z-10"
            role="dialog"
            :aria-label="ariaLabel"
            :aria-labelledby="labelledBy"
            aria-modal="true"
        >
            <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"></div>

            <div class="fixed z-10 inset-0 overflow-y-auto">
                <div
                    class="flex items-end sm:items-center justify-center min-h-full p-4 text-center sm:p-0"
                    @click="onBackdropClick"
                >
                    <!-- sm:max-w-3xl sm:w-full  -->
                    <div
                        ref="panel"
                        tabindex="-1"
                        class="relative bg-white dark:bg-gray-900 rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 focus:outline-none"
                    >
                        <div class="bg-white dark:bg-gray-900 pt-5 pb-4 sm:pb-4">
                            <!-- content -->
                            <slot/>
                        </div>
                        <div class="bg-gray-50 dark:bg-gray-800 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                            <Link
                                v-if="fakeModal"
                                type="button"
                                class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 dark:border-gray-600 shadow-sm px-4 py-2 bg-white dark:bg-gray-900 text-base font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm"
                                :href="redirect === 'current' ? route('dashboard') : route('past.projects.index')"
                                @click="loadingButton = true"
                            >
                                {{ loadingButton ? 'Closing...' : 'Back to projects'}}
                            </Link>

                            <button
                                v-else
                                type="button"
                                class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 dark:border-gray-600 shadow-sm px-4 py-2 bg-white dark:bg-gray-900 text-base font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm"
                                @click="$emit('closeModal')"
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
