import moment from "moment";

export default {
    sendSupplierBatchEmail(batchGroup) {
        // Email details
        const emailAddress = ""; //"example@example.com";
        const subject = ""; //todo
        let materialList = ""; // Headers

        Object.values(batchGroup).forEach(item => {
            //Meterage
            if(item.algo === 'METERAGE'){
                // Build the material list

                let description = item.product_derived_label;

                item.nested.orderList.forEach(bar => {
                    let text = " - " + description + ": " + bar.count + "x " + parseFloat(bar.result).toLocaleString() + "mm"; // + item.nominal_units.toLowerCase();
                    materialList += text + "\n"; // Rows
                });
            }
            //Area
            if(item.algo === 'AREA'){
                //todo
            }
            //Bundle
            if(item.algo === 'BUNDLE'){
                //todo
            }
        });

        // Create the mailto link
        let row1 = "Hi, I'm seeking a quote for the following:";
        let row2 = materialList;
        let row3 = "Thank you.";

        const body = encodeURIComponent(`${row1}\n\n${row2}\n\n${row3}`);
        const mailtoLink = `mailto:${emailAddress}?subject=${encodeURIComponent(subject)}&body=${body}`;

        // Open the email client
        window.location.href = mailtoLink;
    },
    capitalizeWords(input) {
        return input.replace(/\b\w/g, char => char.toUpperCase());
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
