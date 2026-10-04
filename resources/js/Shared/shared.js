import moment from "moment";

export default {
    /**
     * What to buy from one supplier group, a line per stock length.
     *
     * Read by the Nesting page's Order list modal, whose Copy button is how the list leaves the
     * screen and reaches a merchant.
     *
     * AREA and BUNDLE produce nothing yet - the nest has no order list for them - so they are left
     * out rather than printed as empty lines.
     */
    orderListLines(batchGroup) {
        const lines = [];

        Object.values(batchGroup ?? {}).forEach(item => {
            if(item.algo !== 'METERAGE'){
                return;
            }

            const description = item.product_derived_label;

            (item.nested?.orderList ?? []).forEach(bar => {
                lines.push(" - " + description + ": " + bar.count + "x " + parseFloat(bar.result).toLocaleString() + "mm");
            });
        });

        return lines;
    },
    capitalizeWords(input) {
        return input.replace(/\b\w/g, char => char.toUpperCase());
    },
    supplierGroupLabel(supplierGroup) {
        /**
         * A supplier group reaches the front end as its enum name - STEEL_MERCHANT - which is the
         * key everything else is stored and posted under, so it stays as it is. This is purely how
         * it gets read: "Steel merchant", in the sentence case the rest of the app labels in.
         */
        if(!supplierGroup){
            return "";
        }

        let words = supplierGroup.replace(/_/g, " ").trim().toLowerCase();

        return words.charAt(0).toUpperCase() + words.slice(1);
    },
    certificateDetail(certificate) {
        /**
         * One entry of a certificate trail as a line of text.
         *
         * Both trails - the new stock a batch bought, and the ancestry behind a reused offcut -
         * arrive in the same shape from Batch: a written reference, attached certificate files, or
         * both. Either on its own is a complete answer, so an order certified only by an attached
         * PDF has to read as certified here rather than as a supplier name against nothing.
         */
        const parts = [];

        if(certificate?.material_cert_numbers){
            parts.push(certificate.material_cert_numbers);
        }

        (certificate?.material_cert_files ?? []).forEach(file => parts.push(file.filename));

        return parts.join(", ");
    },
    cropText(text, maxLength = 25) {
        if (text.length > maxLength) {
            return text.substring(0, maxLength) + "..";
        }
        return text;
    },
    daysUntilNearestCriticalPathDeadline(projects){
        /**
         Integer days until quote deadline of earliest project.
         Can be negative if it's passed.
         */
        let arrayOfTimestamps = [];
        Object.values(projects).forEach(project => {
            arrayOfTimestamps.push(project.criticalPathDeadline);
        });

        const moments = arrayOfTimestamps.map(ts => moment(ts));
        const earliestDate = moment.min(moments);

        return earliestDate.startOf('day').diff(moment().startOf('day'), 'days');
    },
    criticalPathDeadlineMessage(projects,nestingStage){
        let message = "";

        let daysUntilNearestCriticalPathDeadline = this.daysUntilNearestCriticalPathDeadline(projects);

        //In the past
        if(daysUntilNearestCriticalPathDeadline < 0){
            message = "The critical path deadline was " + (0 - daysUntilNearestCriticalPathDeadline) + " day"+ (daysUntilNearestCriticalPathDeadline > 1 ? 's' : '') +" ago. You should quote/order this batch today.";
        }
        //Today
        else if(daysUntilNearestCriticalPathDeadline === 0){
            message = "The critical path deadline is today. Quote/order this batch.";
        }
        //Future
        if(daysUntilNearestCriticalPathDeadline > 0){
            if(nestingStage){
                message = "Wait " + daysUntilNearestCriticalPathDeadline + " days to allow for more possible materials (Wait for critical path)";
            }
        }

        return message;
    },
    getUniqueRef(index,total){
        /**
         * Reference like (A).
         * Only display if multiple projects
         */

        let ref = "";
        let letter = "";

        switch (index) {
            case 0:
                letter = "A";
                break;
            case 1:
                letter = "B";
                break;
            case 2:
                letter = "C";
                break;
            case 3:
                letter = "D";
                break;
            case 4:
                letter = "E";
                break;
            case 5:
                letter = "F";
                break;
            case 6:
                letter = "G";
                break;
            case 7:
                letter = "H";
                break;
            case 8:
                letter = "I";
                break;
            case 9:
                letter = "J";
                break;
            case 10:
                letter = "K";
                break;
        }

        if(total > 0){
            ref = "("+letter+")";
        }

        return ref;
    },
    isYourProject(project,userId){
        return project.user_id == userId;
    },
    atLeastOneProjectIsYours(projects,userId){
        let atLeastOneProjectIsYours = false;

        Object.values(projects).forEach(project => {
            if(project.user_id == userId){
                atLeastOneProjectIsYours = true;
            }
        });

        return atLeastOneProjectIsYours;
    },
}
