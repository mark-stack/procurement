import moment from "moment";
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
 * @param {?string} orderingTriggerDate The batch's "Order by" day, which decides whether waiting is still advice
 * @returns {{title: string, message: string, note: ?string, confirmLabel: string, tone: string, onConfirmed: function}}
 */
export default function startQuotingDialog(projects, onConfirmed, orderingTriggerDate = null){
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

    /*
     * How much longer this batch is allowed to sit there - the same day the board's Order by pill
     * counts down to, read the same way it reads it (OrderByPill), so the dialog cannot say "wait"
     * on a day the pill beside the button has already turned red. Deliberately not the Nesting
     * page's "Required by" date, which is when the steel has to be at the workshop rather than when it has
     * to be bought: waiting until then is waiting several days too long.
     *
     * Null when no project on the batch has a fabrication date: nothing is chasing it, so there is
     * no number of days to wait and no deadline to be early for.
     */
    const daysToOrderBy = orderingTriggerDate
        ? moment(orderingTriggerDate).startOf('day').diff(moment().startOf('day'), 'days')
        : null;

    return {
        title: theirs.length > 0 ? "Nest your colleagues' projects too?" : "Start quoting?",
        message: message,
        /*
         * Steel is bought by the bar, so projects sharing a batch share the bars and the offcuts
         * they leave, and every one of them pays less for material. Whatever is nested after this
         * batch is cut cannot get any of that back, and nothing on either screen says so - the
         * button reads as the routine next step, not as the moment the batch closes. Its own
         * paragraph because it is advice, not what the button does.
         *
         * Only while there is still room to take it. On the Order by day and after it, waiting is
         * not the cheaper choice any more - it is the one that puts the earliest job on this batch
         * behind its fabrication date, and advice to wait sitting next to a pill reading "now" is
         * the dialog arguing with the page. The days are named rather than implied, because "soon"
         * is the whole question the presser is trying to answer.
         */
        note: daysToOrderBy > 0
            ? "Projects nested together share bars and offcuts, so each one costs less in material. "
                + `This batch has ${daysToOrderBy === 1 ? 'one more day' : daysToOrderBy + ' more days'} `
                + "before it has to be quoted, so if more projects are due in soon it is worth waiting "
                + "and nesting them all at once - the saving on a bigger batch is significant."
            : null,
        confirmLabel: "Start quoting",
        tone: "primary",
        onConfirmed: onConfirmed,
    };
}
