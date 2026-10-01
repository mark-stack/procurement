<?php

namespace App\Enums;

/**
 * Why an upload that matched no template did not end with one being recorded.
 *
 * There is no case for the attempt that worked. A successful one records a Template and imports the
 * file, and that template is the record of it - an attempts table that also held the successes
 * would be a second, worse copy of the templates table.
 */
enum TemplateLearningEnums: string
{
    /**
     * The template was proposed and tested, and the test failed. The one case where there is
     * something for an admin to pick up: the proposal is in the row, and it is wrong in a way the
     * checklist names.
     */
    case REFUSED = 'REFUSED';

    /**
     * Nothing could be proposed. No API key, OpenAI unreachable, a file that is not a spreadsheet -
     * all of them mean the attempt never got as far as having an opinion to test.
     */
    case UNREADABLE = 'UNREADABLE';

    /**
     * This business has already had its hour's worth of attempts. Recorded rather than passed over
     * in silence, because a customer uploading the same unreadable file ten times is worth seeing.
     */
    case THROTTLED = 'THROTTLED';

    /**
     * What the customer is told. Deliberately the same sentence for all three: the difference
     * between them is ours to act on, and none of it is anything they can do something about.
     */
    public function customerMessage(): string
    {
        return 'We could not read this spreadsheet format automatically. Our team has been sent it and will have it importing shortly - there is no need to email it to anyone.';
    }
}
