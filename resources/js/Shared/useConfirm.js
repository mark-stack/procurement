import {ref} from "vue";

/**
 * Drives a ConfirmModal in place of window.confirm().
 *
 * A page calls askToConfirm() with the copy to show and the work to run once the
 * user agrees, binds confirmDialog to a <ConfirmModal v-if="confirmDialog">, and
 * wires its two events back to confirmDialogAccepted / confirmDialogCancelled.
 *
 * Unlike window.confirm() this does not block, so anything that used to run after
 * the confirmation has to move into onConfirmed.
 */
export default function useConfirm(){
    //Whatever is currently being asked about, null when nothing is
    const confirmDialog = ref(null);

    /**
     * @param {{title: string, message: string, confirmLabel?: string, cancelLabel?: string, tone?: string, onConfirmed: function, onCancelled?: function}} dialog
     */
    function askToConfirm(dialog){
        confirmDialog.value = dialog;
    }

    function confirmDialogAccepted(){
        const onConfirmed = confirmDialog.value?.onConfirmed;

        //Close first, so the work cannot leave the dialog on screen
        confirmDialog.value = null;
        onConfirmed?.();
    }

    function confirmDialogCancelled(){
        const onCancelled = confirmDialog.value?.onCancelled;

        confirmDialog.value = null;
        onCancelled?.();
    }

    return {confirmDialog, askToConfirm, confirmDialogAccepted, confirmDialogCancelled};
}
