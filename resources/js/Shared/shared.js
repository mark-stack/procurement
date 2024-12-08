export default {
    formatProduct(product, size, grade, surface, length) {
        //Size
        let actualSize = size;
        if (product === "PLATE") {
            actualSize = size + "PL";
        }
        if (product === "BOLT") {
            if (length) {
                actualSize = "M" + size + "x" + length;
            } else {
                actualSize = "M" + size;
            }
        }
        if (size.includes("X")) {
            actualSize = size.toLowerCase();
        }

        //Product
        let actualProduct = product;
        if (product === "PLATE") {
            actualProduct = "";
        }
        if (product === "BOLT") {
            actualProduct = "";
        }
        if (product === "LVL") {
            actualProduct = " " + product;
        }

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
            actualSurface = "";
        }
        if (surface === "GALVANISED") {
            actualSurface = " GALV";
        }
        if (surface === "TREATED_H2") {
            actualSurface = " H2";
        }

        return actualSize + actualProduct + " " + actualGrade + actualSurface;
    }
}
