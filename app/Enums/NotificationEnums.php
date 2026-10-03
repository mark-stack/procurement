<?php

namespace App\Enums;

/**
 * The notification types this application has ever intended to send.
 *
 * Read it as a wishlist, not a switchboard - nothing dispatches on these values. What actually
 * exists is NotificationService::implementations(), and the two lists disagree on purpose:
 *
 *  Built, hourly:      TENTATIVE_MATERIALS_DATE_CORRECT, QUOTE_DUE, QUOTE_OVERDUE
 *  Built, event-driven: COLLEAGUE_JOINED, A_COLLEAGUE_QUOTED_YOUR_MATERIALS,
 *                       A_COLLEAGUE_ORDERED_YOUR_MATERIALS, BATCH_READY_TO_QUOTE,
 *                       A_COLLEAGUE_IS_ORDERING_THIS_BATCH_TODAY
 *
 * Deliberately not built:
 *
 *  ORDER_APPROVAL_REQUIRED - asking for approval implies the order waits for each project manager's
 *      answer, and per-manager approval was explicitly not built: "Sent order" settles the approval
 *      for every project on the batch on one press. A notification that asks for something the app
 *      will not then wait for is worse than none. A_COLLEAGUE_ORDERED_YOUR_MATERIALS reports the
 *      press after the fact instead, which is what actually happens.
 *
 *  DID_YOU_SEND_QUOTE, DID_YOU_RECEIVE_QUOTE_RESPONSE, DID_YOU_PLACE_THE_ORDER,
 *  DID_YOU_RECEIVE_ORDER_CONFIRMATION - each one duplicates a column the board already shows, and
 *      the screen that shows it is where you would go to answer. QUOTE_DUE/QUOTE_OVERDUE already
 *      chase the deadline behind all four.
 *
 *  ORDER_DUE, ORDER_OVERDUE - the critical path is quote time plus delivery time and is chased as
 *      one deadline, because there is no separate date to be late against. See
 *      Project::criticalPathDays().
 */
enum NotificationEnums: string
{
    //A colleague joined
    case COLLEAGUE_JOINED = 'COLLEAGUE_JOINED';

    /**
     * Projects
     */
    //Is the tentative materials date still correct?
    case TENTATIVE_MATERIALS_DATE_CORRECT = 'TENTATIVE_MATERIALS_DATE_CORRECT';

    /**
     * Quotes
     */
    //Quote due
    case QUOTE_DUE = 'QUOTE_DUE';

    //Quote overdue
    case QUOTE_OVERDUE = 'QUOTE_OVERDUE';

    //Did you send the quote?
    case DID_YOU_SEND_QUOTE = 'DID_YOU_SEND_QUOTE';

    //Did you receive & file the quote response?
    case DID_YOU_RECEIVE_QUOTE_RESPONSE = 'DID_YOU_RECEIVE_QUOTE_RESPONSE';

    //Quote by a colleague
    case A_COLLEAGUE_QUOTED_YOUR_MATERIALS = 'A_COLLEAGUE_QUOTED_YOUR_MATERIALS';

    /*
     * The fabrication deadline warnings: a project in the Nesting column is close enough to its
     * fabrication start date that waiting any longer costs it the critical path, so the whole column
     * has to be taken into one batch today. The first goes to the manager of the project that forces
     * it, who is the one who can press "Start quoting"; the second to every other project manager in
     * the column, whose work goes with it.
     *
     * Both case names are the tense these were written in, when the schedule pressed the button
     * itself. It only asks now - see App\Services\FabricationDeadlineQuoting - and the names are left
     * alone because the notification class names they shadow are the `type` column of every row
     * already sitting in somebody's bell.
     */
    case BATCH_READY_TO_QUOTE = 'BATCH_READY_TO_QUOTE';

    case A_COLLEAGUE_IS_ORDERING_THIS_BATCH_TODAY = 'A_COLLEAGUE_IS_ORDERING_THIS_BATCH_TODAY';

    //Order due
    case ORDER_DUE = 'ORDER_DUE';

    //Order overdue
    case ORDER_OVERDUE = 'ORDER_OVERDUE';

    //Did you place the order?
    case DID_YOU_PLACE_THE_ORDER = 'DID_YOU_PLACE_THE_ORDER';

    //Did you receive order confirmation?
    case DID_YOU_RECEIVE_ORDER_CONFIRMATION = 'DID_YOU_RECEIVE_ORDER_CONFIRMATION';

    //Order by a colleague
    case A_COLLEAGUE_ORDERED_YOUR_MATERIALS = 'A_COLLEAGUE_ORDERED_YOUR_MATERIALS';

    //Order approval required
    case ORDER_APPROVAL_REQUIRED = 'ORDER_APPROVAL_REQUIRED';
}
