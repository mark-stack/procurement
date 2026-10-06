import {computed} from "vue";

/**
 * The single source of truth for the landing page savings/ROI figures.
 *
 * The hero headline, the pricing comparison table and the SavingsCalculator widget all
 * showed the same numbers from three copy-pasted implementations, so a change to one
 * left the others quietly disagreeing on the same screen. Everything now derives from
 * here instead.
 *
 * @param {{annualSpendMillions: number, currentWastePct: number, wasteReductionPct: number}} inputs reactive, driven by the sliders
 * @param {{years: number, whichPlan: string, fullPriceMultiYear: number, multiYearTermYears: ?number, fullPriceAnnual: number, fullPriceMonthly: number, fullPriceWeekly: number, firstYearDiscount: number}} plan fixed for the page
 */
export default function useSavings(inputs, plan){
    //Above this the ratio stops reading as a real number and starts reading as a sales pitch
    const maxCredibleRoi = 50;

    const weeksPerYear = 52;
    const monthsPerYear = 12;

    /*
     * What the merchant pays to weigh an offcut back in, as a share of what the steel cost.
     *
     * A constant rather than a slider. It is a figure out of the reader's own merchant agreement that
     * they will not have to hand on a landing page, and asking them for it put a control next to the
     * saving that only ever made the saving smaller. Thirteen points is the rate the application's own
     * cost model defaults to, so the calculator and the product agree on it.
     */
    const scrapRefundPct = 13;

    //What is left after the first year discount, e.g. 10% off = 0.9
    const fractionalPrice = (100-plan.firstYearDiscount)/100;

    /**
     * What the saw still wastes once the cut list is nested here, e.g. 10% of spend at a 50%
     * reduction becomes 5%.
     */
    const nestedWastePct = computed(() => round1(inputs.currentWastePct * (1 - (inputs.wasteReductionPct/100))));

    /**
     * The share of annual spend that stops being waste and becomes structure - the part of today's
     * waste the nest wins back, and the only thing the saving is struck on.
     *
     * Derived from the two figures the reader sets rather than entered beside them. The slider used to
     * ask for this directly, as "extra material yield", which left them to work backwards to what it
     * meant about their own waste.
     */
    const materialRecoveredPct = computed(() => round1(inputs.currentWastePct - nestedWastePct.value));

    /**
     * Material no longer bought, less the scrap value those offcuts would have been sold
     * for anyway. The scrap refund is a deduction because it is money the yard already
     * recovers today, so counting it as a saving would bill for it twice.
     */
    const savings = computed(() => {
        const annualSpend = inputs.annualSpendMillions * 1000000;
        const annualMaterialSaved = annualSpend * (materialRecoveredPct.value/100);
        const annualScrapRefund = annualMaterialSaved * (scrapRefundPct/100);

        return plan.years * (annualMaterialSaved - annualScrapRefund);
    });

    /**
     * What the software costs across the same period the savings are measured over, so the
     * two sides of the ratio always cover the same number of years.
     */
    const priceOverSavingsPeriod = computed(() => {
        /*
         * A single up front payment covers its OWN term, which is not the period the savings are
         * measured over and must not be assumed to be. It used to return the lump sum whole against
         * however many years the page was showing - so a three year licence compared against one
         * year of savings read as three years of software against one of steel - while costPerMonth
         * divided the same lump by plan.years, which is the savings period rather than the term.
         * Two different wrong readings of one number, and both are a single constant away from
         * being published.
         */
        if(plan.whichPlan === "MULTI_YEAR"){
            const term = multiYearTermYears();

            return term === null ? null : plan.fullPriceMultiYear*(plan.years/term);
        }

        const annualPrice = annualPriceForPlan();

        if(annualPrice === null){
            return null;
        }

        //Only the first year is discounted, the rest renew at full price
        return (annualPrice*fractionalPrice) + ((plan.years - 1) * annualPrice);
    });

    /**
     * Return on investment proper: what the savings are worth over and above the software,
     * divided by the software. Reported as "1:31", i.e. every dollar spent returns 31 on top.
     *
     * Null whenever there is no honest ratio to print - an unrecognised plan, a zero price,
     * or savings that have not yet covered the cost - so the caller can hide the line rather
     * than render "1:Infinity" or "1:-1".
     */
    const roi = computed(() => {
        const price = priceOverSavingsPeriod.value;

        if(!price || price <= 0 || !Number.isFinite(price)){
            return null;
        }

        const netReturn = savings.value - price;

        if(netReturn <= 0){
            return null;
        }

        return netReturn/price;
    });

    /**
     * "1:31", capped so the top of the slider range cannot claim "1:185".
     */
    const roiDisplay = computed(() => {
        if(roi.value === null){
            return null;
        }

        if(roi.value > maxCredibleRoi){
            return "1:" + maxCredibleRoi + "+";
        }

        return "1:" + Math.round(roi.value);
    });

    /**
     * "$65K", matching the period termDisplay names.
     */
    const savingsDisplay = computed(() => formatMoney(savings.value));

    /**
     * "per year" / "over 3 years", so no figure is ever shown without its period.
     */
    const termDisplay = computed(() => {
        if(plan.years === 1){
            return "per year";
        }

        return "over " + plan.years + " years";
    });

    /**
     * First year cost per month, for comparing against competitors who all quote monthly.
     * Null for an unrecognised plan.
     */
    const costPerMonth = computed(() => {
        /*
         * Over the term the lump actually buys, and with no first year discount applied: an up
         * front multi-year price IS the discount, so taking another slice off it here quoted a
         * monthly figure nobody could ever pay.
         */
        if(plan.whichPlan === "MULTI_YEAR"){
            const term = multiYearTermYears();

            return term === null ? null : Math.round(plan.fullPriceMultiYear/term/monthsPerYear);
        }

        const annualPrice = annualPriceForPlan();

        if(annualPrice === null){
            return null;
        }

        return Math.round((annualPrice*fractionalPrice)/monthsPerYear);
    });

    /**
     * How many years the up front multi-year price covers.
     *
     * Null when the page has not said, which is the only safe answer: a lump sum is not a price
     * until you know what it buys, and every reading of it that guesses the term is wrong in a
     * direction nobody will notice on the page. The callers hide their figure rather than print
     * one, exactly as they do for an unrecognised plan.
     */
    function multiYearTermYears(){
        const term = Number(plan.multiYearTermYears);

        return Number.isFinite(term) && term > 0 ? term : null;
    }

    /**
     * The recurring plans expressed as one comparable yearly figure. Null when whichPlan is
     * not one of the recognised values, rather than silently reading as free.
     */
    function annualPriceForPlan(){
        if(plan.whichPlan === "ANNUAL"){
            return plan.fullPriceAnnual;
        }
        if(plan.whichPlan === "MONTHLY"){
            return monthsPerYear * plan.fullPriceMonthly;
        }
        if(plan.whichPlan === "WEEKLY"){
            return weeksPerYear * plan.fullPriceWeekly;
        }

        return null;
    }

    /**
     * One decimal place, as a number rather than a string, so half a point of waste reads as "5.5"
     * and a whole one as "6" rather than "6.0".
     */
    function round1(value){
        return Math.round(value*10)/10;
    }

    /**
     * "$65K" under a million, "$1.4M" above it. Compares on the absolute value so a negative
     * amount cannot fall through to the wrong branch.
     */
    function formatMoney(amount){
        if(Math.abs(amount) < 1000000){
            return Math.round(amount/1000) + "K";
        }

        return (amount/1000000).toFixed(1) + "M";
    }

    return {
        scrapRefundPct,
        nestedWastePct,
        materialRecoveredPct,
        savings,
        savingsDisplay,
        termDisplay,
        priceOverSavingsPeriod,
        roi,
        roiDisplay,
        costPerMonth,
    };
}
