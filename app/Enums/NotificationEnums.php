<?php

namespace App\Enums;

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
