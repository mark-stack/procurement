import shared from "@/Shared/shared.js";

/**
 * The question "Start quoting" asks before it fires, wherever it is pressed from.
 *
 * Two screens carry that press now - the board's Nesting card and the open batch card on
 * /nesting - and it is the same press on both: a post to quotes.store, which nests everything
 * waiting into one batch under the presser's name. The warning about reaching across a
 * colleague's work is the whole reason it asks at all, so it is worded once here rather than
 * once per screen, where the two copies would quietly drift apart.
 *
 * @param {Array<{name: string, mine: boolean}>} projects Everything waiting, which is what gets nested
 * @param {function} onConfirmed Run once the user agrees
 * @returns {{title: string, message: string, note: string, confirmLabel: string, tone: string, onConfirmed: function}}
 */
export default function startQuotingDialog(projects, onConfirmed){
    const mine = projects.filter(project => project.mine);
    const theirs = projects.filter(project => !project.mine);

    const nameList = list => list
        .map(project => shared.capitalizeWords(project.name))
        .join(", ");

    const message = theirs.length > 0
        ? `${nameList(projects)} will be nested together into one batch. `
            + `That includes ${nameList(theirs)}, which ${theirs.length > 1 ? 'are' : 'is'} not yours - `
            + `nesting ${theirs.length > 1 ? 'them' : 'it'} now fixes the suppliers and delivery dates for `
            + `${theirs.length > 1 ? 'those projects' : 'that project'} too.`
        : `${nameList(mine)} will be nested into one batch and moved to Quoting.`;

    return {
        title: theirs.length > 0 ? "Nest your colleagues' projects too?" : "Start quoting?",
        message: message,
        /*
         * Steel is bought by the bar, so projects sharing a batch share the bars and the offcuts
         * they leave, and every one of them pays less for material. Whatever is nested after this
         * batch is cut cannot get any of that back, and nothing on either screen says so - the
         * button reads as the routine next step, not as the moment the batch closes. Its own
         * paragraph because it is advice, not what the button does.
         */
        note: "Projects nested together share bars and offcuts, so each one costs less in material. "
            + "If more projects are due in soon, it is worth waiting and nesting them all at once - "
            + "the saving on a bigger batch is significant.",
        confirmLabel: "Start quoting",
        tone: "primary",
        onConfirmed: onConfirmed,
    };
}
