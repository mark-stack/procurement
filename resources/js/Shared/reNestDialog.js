import shared from "@/Shared/shared.js";

/**
 * The question "Re-nest" asks before it fires, wherever it is pressed from.
 *
 * The most destructive button in the app, and its label does not say so - it reads as
 * "recalculate the nesting". It deletes the batch, every quote on it, every order, the order
 * approvals, and the offcuts and bars it cut, and none of that comes back. So it names what is
 * about to go, and it names it the same way on the board's Quoting card and in the open menu on
 * /nesting - two screens pressing the same DELETE, which is one piece of wording, not two.
 *
 * @param {number} batchId The batch being unpicked, which the message names
 * @param {Array<{name: string}>} projects Everything on it, so the batch is said as the work it is
 * @param {function} onConfirmed Run once the user agrees
 * @returns {{title: string, message: string, confirmLabel: string, tone: string, onConfirmed: function}}
 */
export default function reNestDialog(batchId, projects, onConfirmed){
    const projectNames = projects
        .map(project => shared.capitalizeWords(project.name))
        .join(", ");

    return {
        title: "Re-nest this batch?",
        message: `Batch ${batchId} (${projectNames}) goes back to Nesting. Its quotes, draft orders`
            + ` and the offcuts it produced are deleted. This cannot be undone.`,
        confirmLabel: "Re-nest",
        tone: "danger",
        onConfirmed: onConfirmed,
    };
}
