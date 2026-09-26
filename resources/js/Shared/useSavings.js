import {computed} from "vue";

/**
 * The single source of truth for the landing page savings/ROI figures.
 *
 * The hero headline, the pricing comparison table and the SavingsCalculator widget all
 * showed the same numbers from three copy-pasted implementations, so a change to one
 * left the others quietly disagreeing on the same screen. Everything now derives from
 * here instead.
 *
 * @param {{annualSpendMillions: number, yieldGainPct: number, scrapRefundPct: number}} inputs reactive, driven by the sliders
 * @param {{years: number, whichPlan: string, fullPriceMultiYear: number, fullPriceAnnual: number, fullPriceMonthly: number, fullPriceWeekly: number, firstYearDiscount: number}} plan fixed for the page
 */
export default function useSavings(inputs, plan){
    //Above this the ratio stops reading as a real number and starts reading as a sales pitch
    const maxCredibleRoi = 50;

    const weeksPerYear = 52;
    const monthsPerYear = 12;

    //What is left after the first year discount, e.g. 10% off = 0.9
    const fractionalPrice = (100-plan.firstYearDiscount)/100;

    /**
     * Material no longer bought, less the scrap value those offcuts would have been sold
     * for anyway. The scrap refund is a deduction because it is money the yard already
     * recovers today, so counting it as a saving would bill for it twice.
     */
    const savings = computed(() => {
        const annualSpend = inputs.annualSpendMillions * 1000000;
        const yieldGain = inputs.yieldGainPct/100; //e.g 5% = 0.05
        const annualMaterialSaved = annualSpend * yieldGain;
        const annualScrapRefund = annualMaterialSaved * (inputs.scrapRefundPct/100);

        return plan.years * (annualMaterialSaved - annualScrapRefund);
    });

    /**
     * What the software costs across the same period the savings are measured over, so the
     * two sides of the ratio always cover the same number of years.
     */
    const priceOverSavingsPeriod = computed(() => {
        //A single up front payment already covers the whole term
        if(plan.whichPlan === "MULTI_YEAR"){
            return plan.fullPriceMultiYear;
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
        if(plan.whichPlan === "MULTI_YEAR"){
            return Math.round((plan.fullPriceMultiYear*fractionalPrice)/plan.years/monthsPerYear);
        }

        const annualPrice = annualPriceForPlan();

        if(annualPrice === null){
            return null;
        }

        return Math.round((annualPrice*fractionalPrice)/monthsPerYear);
    });

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
     * "$65K" under a million, "$1.4M" above it. Compares on the absolute value so a negative
     * amount cannot fall through to the wrong branch.
     */
    function formatMoney(amount){
        if(Math.abs(amount) < 1000000){
            return Math.round(amount/1000) + "K";
        }

        return (amount/1000000).toFixed(1) + "M";
    }

    return {savings, savingsDisplay, termDisplay, priceOverSavingsPeriod, roi, roiDisplay, costPerMonth};
}
