export default {
    formatProduct(product, nominal_length, nominal_width, nominal_height, grade, surface) {
        //Grade
        let actualGrade = grade;
        if (grade === "NONE") {
            actualGrade = "";
        }
        if (grade === "GR_4_6") {
            actualGrade = "GR4.6"
        }
        if (grade === "GR_8_8") {
            actualGrade = "GR8.8"
        }

        //Surface
        let actualSurface = ' ' + surface;
        if (surface === "NONE") {
            actualSurface = " ";
        }
        if (surface === "GALVANISED") {
            actualSurface = " GALV";
        }
        if (surface === "TREATED_H2") {
            actualSurface = " H2";
        }

        let result = "";

        if(product === "BOLT"){
            result = this.formatBOLT(product, nominal_length, nominal_width, nominal_height, actualGrade, actualSurface);
        }
        else if(product === "UB"){
            result = this.formatUB(product, nominal_length, nominal_width, nominal_height, actualGrade, actualSurface);
        }
        else if(product === "UC"){
            result = this.formatUC(product, nominal_length, nominal_width, nominal_height, actualGrade, actualSurface);
        }
        else if(product === "PFC"){
            result = this.formatPFC(product, nominal_length, nominal_width, nominal_height, actualGrade, actualSurface);
        }
        else if(product === "PLATE"){
            result = this.formatPLATE(product, nominal_length, nominal_width, nominal_height, actualGrade, actualSurface);
        }
        else if(product === "LVL"){
            result = this.formatLVL(product, nominal_length, nominal_width, nominal_height, actualGrade, actualSurface);
        }
        else if(product === "SHS"){
            result = this.formatSHS(product, nominal_length, nominal_width, nominal_height, actualGrade, actualSurface);
        }
        else if(product === "RHS"){
            result = this.formatRHS(product, nominal_length, nominal_width, nominal_height, actualGrade, actualSurface);
        }
        else{
            result = this.formatDefault(product, nominal_length, nominal_width, nominal_height, actualGrade, actualSurface);
        }

        return result;
    },
    formatDefault(product, nominal_length, nominal_width, nominal_height, actualGrade, actualSurface){
        return "Nominal " + product + " " + actualGrade + actualSurface;
    },
    formatBOLT(product, nominal_length, nominal_width, nominal_height, actualGrade, actualSurface){
        //Size
        let actualSize = "";
        if (nominal_length) {
            actualSize = "M" + nominal_width + "x" + nominal_length;
        } else {
            actualSize = "M" + nominal_width;
        }

        return actualSize + " " + actualGrade + actualSurface;
    },
    formatUB(product, nominal_length, nominal_width, nominal_height, actualGrade, actualSurface){
        let actualSize = nominal_height;
        return actualSize + " " + actualGrade + actualSurface;
    },
    formatUC(product, nominal_length, nominal_width, nominal_height, actualGrade, actualSurface){
        let actualSize = nominal_height;
        return actualSize + " " + actualGrade + actualSurface;
    },
    formatPFC(product, nominal_length, nominal_width, nominal_height, actualGrade, actualSurface){
        let actualSize = nominal_height;
        return actualSize + product + " " + actualGrade + actualSurface;
    },
    formatPLATE(product, nominal_length, nominal_width, nominal_height, actualGrade, actualSurface){
        let actualSize = nominal_height + "PL";
        return actualSize + " " + actualGrade + actualSurface;
    },
    formatLVL(product, nominal_length, nominal_width, nominal_height, actualGrade, actualSurface){
        let actualSize = nominal_height + "x" + nominal_width;
        return actualSize + " " + actualGrade + actualSurface;
    },
    formatSHS(product, nominal_length, nominal_width, nominal_height, actualGrade, actualSurface){
        let actualSize = nominal_height + "x" + nominal_width;
        return actualSize + " " + actualGrade + actualSurface;
    },
    formatRHS(product, nominal_length, nominal_width, nominal_height, actualGrade, actualSurface){
        let actualSize = nominal_height + "x" + nominal_width;
        return actualSize + " " + actualGrade + actualSurface;
    },
}
