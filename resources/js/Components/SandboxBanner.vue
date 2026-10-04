<script setup>
    //General Imports
    import {Link, router, usePage} from '@inertiajs/vue3';
    import {computed, ref} from 'vue';

    //Component Imports
    import ConfirmModal from '@/Components/Modals/ConfirmModal.vue';

    //Shared Methods
    import useConfirm from '@/Shared/useConfirm.js';

    //Props
    //...

    //Form
    //...

    //Shared data
    //Whether this user is in their own sandbox, and how much is in it - see HandleInertiaRequests
    const sandbox = computed(() => usePage().props.sandbox);
    const active = computed(() => sandbox.value?.active === true);
    const projectCount = computed(() => sandbox.value?.projects ?? 0);
    const batchCount = computed(() => sandbox.value?.batches ?? 0);

    //Variables
    const clearing = ref(false);

    //Shared Methods
    const {confirmDialog, askToConfirm, confirmDialogAccepted, confirmDialogCancelled} = useConfirm();

    //Computed
    //What the clear button is about to destroy, said in the user's own numbers rather than "everything"
    const contents = computed(() => {
        if(projectCount.value === 0 && batchCount.value === 0){
            return 'Nothing has been made in test mode yet.';
        }

        const projects = `${projectCount.value} test ${projectCount.value === 1 ? 'project' : 'projects'}`;
        const batches = `${batchCount.value} ${batchCount.value === 1 ? 'batch' : 'batches'}`;

        return `${projects} and ${batches} in here.`;
    });

    //Methods
    function confirmClear(){
        askToConfirm({
            title: 'Clear everything in test mode?',
            message: `This deletes ${projectCount.value} ${projectCount.value === 1 ? 'project' : 'projects'} and ${batchCount.value} ${batchCount.value === 1 ? 'batch' : 'batches'}, along with every material list, quote, order, offcut and nesting made against them. It cannot be undone.`,
            note: 'Your real projects and your price book are not touched.',
            confirmLabel: 'Clear everything',
            tone: 'danger',
            onConfirmed: clearEverything,
        });
    }

    function clearEverything(){
        if(clearing.value){
            return;
        }

        clearing.value = true;

        router.delete(route('sandbox.clear'), {
            preserveScroll: true,
            //onFinish, not onSuccess: a refusal has to release the button too
            onFinish: () => clearing.value = false,
        });
    }
</script>

<template>
    <!--
        Deliberately loud, and deliberately the full width of the page. A user who cannot tell
        their sandbox from their real board is the one failure this feature must not have, so
        this is a bar in the same family as the impersonation and billing banners rather than a
        tag tucked into a corner of the board.
    -->
    <div
        v-if="active"
        role="alert"
        class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm font-medium text-white bg-violet-700"
    >
        <p>
            <span class="px-2 py-0.5 mr-2 text-xs font-bold tracking-wider uppercase rounded bg-white/20">
                Test mode
            </span>
            Nothing here is real. Projects and batches you create are yours alone - no colleague sees
            them, and nobody is reminded about them.
            <span class="font-normal text-violet-100">{{ contents }}</span>
        </p>

        <div class="flex items-center gap-2 shrink-0">
            <button
                type="button"
                :disabled="clearing || (projectCount === 0 && batchCount === 0)"
                class="px-3 py-1 font-bold text-violet-800 bg-white rounded hover:bg-violet-50 disabled:opacity-50 disabled:cursor-not-allowed"
                @click="confirmClear"
            >
                {{ clearing ? 'Clearing...' : 'Clear everything' }}
            </button>
            <Link
                :href="route('sandbox.leave')"
                method="post"
                as="button"
                type="button"
                class="px-3 py-1 font-bold rounded ring-1 ring-white/60 hover:bg-white/10"
            >
                Back to real projects
            </Link>
        </div>
    </div>

    <ConfirmModal
        v-if="confirmDialog"
        :title="confirmDialog.title"
        :message="confirmDialog.message"
        :note="confirmDialog.note"
        :confirm-label="confirmDialog.confirmLabel"
        :tone="confirmDialog.tone"
        @confirm="confirmDialogAccepted"
        @cancel="confirmDialogCancelled"
    />
</template>
