	
	/*const selectPasswordInput = document.querySelectorAll('input[type="password"]');
    selectPasswordInput.forEach(element => {
        var newImg = document.createElement("img");
        newImg.src = "/asset/eye-show.svg";
        newImg.style = "width: auto; height: "+((element.clientHeight*35)/100)+"px; cursor: pointer; user-select: auto; position: absolute; margin: 0.98rem 0 0 -2rem;";
        newImg.addEventListener("click", () => {
            newSiblingElement = newImg.previousElementSibling;
            if(newSiblingElement.type == "password"){
                newSiblingElement.type = "text";
                newImg.src = "/asset/eye-hide.svg";
            }else{
                if(newSiblingElement.type == "text"){
                    newSiblingElement.type = "password";
                    newImg.src = "/asset/eye-show.svg";
                }
            }
        });
        element.parentNode.insertBefore(newImg, element.nextSibling);
    });*/

    function passwordToggle(toggledElement){
        alert(toggledElement.type );
    }
	
	function askPermissionSubBtn(elementProperty, dialogText){
		if(dialogText.trim().length >= 1){
			dialogWord = dialogText;
		}else{
			dialogWord = "Are you sure you want to continue?";
		}
		
		if(confirm(dialogWord)){
			elementProperty.type = "submit";
		}else{
			alert("Operation Cancelled");
		}
	}
	
	function copyTextFallback(text) {
		var textArea = document.createElement("textarea");
		textArea.value = text;
		textArea.style.position = "fixed";
		textArea.style.top = "0";
		textArea.style.left = "0";
		textArea.style.opacity = "0";
		document.body.appendChild(textArea);
		textArea.focus();
		textArea.select();
		var successful = false;
		try {
			successful = document.execCommand('copy');
		} catch (err) {
			console.error('Fallback copy failed', err);
		}
		document.body.removeChild(textArea);
		return successful;
	}

	function copyText(copyResponseText, textValue){
		if(textValue.trim().length >= 1){
			var copyResponse;
			if(copyResponseText.trim().length >= 1){
				copyResponse = copyResponseText;
			}else{
				copyResponse = "Content copied to clipboard";
			}
			
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(textValue).then(() => {
					alert(copyResponse);
				}).catch(err => {
					if (copyTextFallback(textValue)) {
						alert(copyResponse);
					} else {
						alert('Failed to copy: ' + err);
					}
				});
			} else {
				if (copyTextFallback(textValue)) {
					alert(copyResponse);
				} else {
					alert('Failed to copy: Clipboard API not supported');
				}
			}
		}else{
			alert("No text to copy");
		}
	}
	
    function tickAirtimeCarrier(networkName){
        var carrierMTN = ["803","702","703","704","903","806","706","707","813","810","814","816","906","916","913","903"];
        var carrierAirtel = ["701","708","802","808","812","901","902","904","907","911","912"];
        var carrierGlo = ["805","705","905","807","815","811","915"];
        var carrier9mobile = ["809","817","818","908","909"];
        var allNetwork = [];
        allNetwork = allNetwork.concat(carrierMTN);
        allNetwork = allNetwork.concat(carrierAirtel);
        allNetwork = allNetwork.concat(carrierGlo);
        allNetwork = allNetwork.concat(carrier9mobile);

        var amount = document.getElementById("product-amount");
        var phoneNo = document.getElementById("phone-number");
        var phoneByPass = document.getElementById("phone-bypass");
        var isprovider = document.getElementById("isprovider");

        if(phoneByPass.checked === false){
            if(allNetwork.indexOf(phoneNo.value.substring(1,4)) !== -1){
                if(carrierAirtel.indexOf(phoneNo.value.substring(1,4)) !== -1){
                    carrierAirtimeInfo("airtel",amount.value,phoneNo.value);
                }
                if(carrierMTN.indexOf(phoneNo.value.substring(1,4)) !== -1){
                    carrierAirtimeInfo("mtn",amount.value,phoneNo.value);
                }
                if(carrierGlo.indexOf(phoneNo.value.substring(1,4)) !== -1){
                    carrierAirtimeInfo("glo",amount.value,phoneNo.value);
                }
                if(carrier9mobile.indexOf(phoneNo.value.substring(1,4)) !== -1){
                    carrierAirtimeInfo("9mobile",amount.value,phoneNo.value);
                }
            }else{
                carrierAirtimeInfo("","","");
            }
        }else{
            isprovider.value = networkName;
            carrierAirtimeInfo(networkName,amount.value,phoneNo.value);
        }
    }

    function carrierAirtimeInfo(ispName,productAmount,phoneNo){
        var ispNames = ["airtel","mtn","glo","9mobile"];
        var proceedBtn = document.getElementById("proceedBtn");
        var isprovider = document.getElementById("isprovider");

        if (phoneNo && phoneNo.length === 11) {
            checkIdentifierLimit(phoneNo, 'airtime', 'phone-number', 'proceedBtn', 'product-status-span');
        }
        
        for(x=0; x < ispNames.length; x++){
            var serviceImg = document.getElementById(ispNames[x]+"-lg");
            if(!serviceImg) continue;
            
            serviceImg.classList.remove("selected");
            serviceImg.style.filter = "none";
            serviceImg.style.opacity = "1";

            if(ispName.trim().length >= 1){
                if(ispNames[x] === ispName){
                    serviceImg.classList.add("selected");
                }else{
                    serviceImg.style.filter = "grayscale(100%) opacity(0.4)";
                }
            }
        }

        var productStatus = "disabled";
        if(ispName.length >= 1){
            var ispImage = document.getElementById(ispName+"-lg");
            isprovider.value = ispName;
            if(ispImage.getAttribute("product-status") != "enabled"){
                productStatus = "disabled";
                ispImage.style.filter = "grayscale(100%)";
                document.getElementById("product-status-span").innerHTML = "Product unavailable!";
            }else{
                productStatus = "enabled";
                document.getElementById("product-status-span").innerHTML = "";
            }
        }else{
            document.getElementById("product-status-span").innerHTML = "";
        }
        
        if(Number(phoneNo) && (phoneNo.length === 11) && Number(productAmount) && (productAmount >= 100) && (isprovider.value.length >= 1) && (ispNames.indexOf(isprovider.value) !== -1) && (productStatus === "enabled") && identifierLimitOk){
            proceedBtn.style.pointerEvents = "auto";
            proceedBtn.classList.remove("btn-secondary");
            proceedBtn.classList.add("btn-success");
        }else{
            proceedBtn.style.pointerEvents = "none";
            proceedBtn.classList.add("btn-secondary");
            proceedBtn.classList.remove("btn-success");
        }
    }
	
    //Bulk Airtime Info
    function tickBulkAirtimeCarrier(networkName){
        var phoneNo = document.getElementById("phone-number");
        var splitPhoneNumbers = phoneNo.value.replaceAll("\n", ",").replaceAll(" ", "").split(",");
        var filteredNumbers = splitPhoneNumbers.filter(p => p.trim().length === 11 && Number(p));
        if (filteredNumbers.length > 0) {
            checkBulkIdentifierLimit(filteredNumbers, 'airtime', 'phone-number', 'proceedBtn', 'product-status-span');
        }

        var carrierMTN = ["803","702","703","704","903","806","706","707","813","810","814","816","906","916","913","903"];
        var carrierAirtel = ["701","708","802","808","812","901","902","904","907","911","912"];
        var carrierGlo = ["805","705","905","807","815","811","915"];
        var carrier9mobile = ["809","817","818","908","909"];
        var allNetwork = [];
        allNetwork = allNetwork.concat(carrierMTN);
        allNetwork = allNetwork.concat(carrierAirtel);
        allNetwork = allNetwork.concat(carrierGlo);
        allNetwork = allNetwork.concat(carrier9mobile);

        var amount = document.getElementById("product-amount");
        var phoneNo = document.getElementById("phone-number");
        var filteredPhoneNo = document.getElementById("filtered-phone-number");
        
        //Multiple Numbers
        //Split Numbers 
        var splitPhoneNumbers = phoneNo.value.replaceAll("\n", ",").replaceAll(" ", "").split(",");
    
        //Filter Numbers
        var filteredNumbers = splitPhoneNumbers.filter(phone => Number(phone) && phone.trim().length === 11);
        filteredNumbers = [... new Set(filteredNumbers)];
        //Update Filtered Phone Numbers
        filteredPhoneNo.value = filteredNumbers.join(",");
        
        document.getElementById("phone-numbers-span").innerHTML = "Phone Number Count: " + filteredNumbers.length;

        var phoneByPass = document.getElementById("phone-bypass");
        var isprovider = document.getElementById("isprovider");
        
        isprovider.value = "";
        var ispNames = ["airtel","mtn","glo","9mobile"];
        for(let x = 0; x < ispNames.length; x++){
            var ispImage = document.getElementById(ispNames[x]+"-lg");
            ispImage.src = "/asset/"+ispNames[x]+".png";
            ispImage.classList.remove("br-radius-5px");
            ispImage.classList.add("br-radius-100px");
            ispImage.style = "filter: grayscale(0%);";
        }

        if(phoneByPass.checked === false){
            var phoneNetworkArr = [];
            var filterFirstFourNumbers = filteredNumbers.map(phone => phone.trim().substring(1,4));
            
            if(allNetwork.includes(filterFirstFourNumbers) !== -1){
                if(carrierAirtel.some(phone => filterFirstFourNumbers.indexOf(phone) !== -1) == true){
                    if(phoneNetworkArr.indexOf("airtel") == -1){
                        phoneNetworkArr.push("airtel");
                    }
                    carrierBulkAirtimeInfo(phoneNetworkArr, "airtel",amount.value);
                }
                if(carrierMTN.some(phone => filterFirstFourNumbers.indexOf(phone) !== -1) == true){
                    if(phoneNetworkArr.indexOf("mtn") == -1){
                        phoneNetworkArr.push("mtn");
                    }
                    carrierBulkAirtimeInfo(phoneNetworkArr, "mtn",amount.value);
                }
                if(carrierGlo.some(phone => filterFirstFourNumbers.indexOf(phone) !== -1) == true){
                    if(phoneNetworkArr.indexOf("glo") == -1){
                        phoneNetworkArr.push("glo");
                    }
                    carrierBulkAirtimeInfo(phoneNetworkArr, "glo",amount.value);
                }
                if(carrier9mobile.some(phone => filterFirstFourNumbers.indexOf(phone) !== -1) == true){
                    if(phoneNetworkArr.indexOf("9mobile") == -1){
                        phoneNetworkArr.push("9mobile");
                    }
                    carrierBulkAirtimeInfo(phoneNetworkArr, "9mobile",amount.value);
                }
            }else{
                carrierBulkAirtimeInfo([""],"","");
            }
            console.log(phoneNetworkArr);
        }else{
            isprovider.value = networkName;
            carrierBulkAirtimeInfo([networkName], networkName,amount.value);
        }
    }

    function carrierBulkAirtimeInfo(ispNetworkArr, ispName, productAmount){
        var ispNames = ["airtel","mtn","glo","9mobile"];
        var proceedBtn = document.getElementById("proceedBtn");
        var isprovider = document.getElementById("isprovider");
        var productStatus = "enabled";

        for(let x = 0; x < ispNames.length; x++){
            var ispImage = document.getElementById(ispNames[x]+"-lg");
            if(!ispImage) continue;

            ispImage.classList.remove("selected");
            ispImage.style.filter = "none";
            ispImage.style.opacity = "1";

            if(ispNetworkArr && ispNetworkArr.length > 0){
                if(ispNetworkArr.includes(ispNames[x])){
                    ispImage.classList.add("selected");
                    if(ispImage.getAttribute("product-status") != "enabled") productStatus = "disabled";
                } else {
                    ispImage.style.filter = "grayscale(100%) opacity(0.4)";
                }
            }
        }
        
        if(ispNetworkArr.length > 0) isprovider.value = ispNetworkArr.join(",");

        if(ispNetworkArr.length > 0 && Number(productAmount) && productAmount >= 100 && productStatus === "enabled" && identifierLimitOk){
            proceedBtn.style.pointerEvents = "auto";
            proceedBtn.classList.remove("btn-secondary");
            proceedBtn.classList.add("btn-success");
        }else{
            proceedBtn.style.pointerEvents = "none";
            proceedBtn.classList.add("btn-secondary");
            proceedBtn.classList.remove("btn-success");
        }
    }
	
    function tickDataCarrier(networkName){
        var carrierMTN = ["803","702","703","704","903","806","706","707","813","810","814","816","906","916","913","903"];
        var carrierAirtel = ["701","708","802","808","812","901","902","904","907","911","912"];
        var carrierGlo = ["805","705","905","807","815","811","915"];
        var carrier9mobile = ["809","817","818","908","909"];
        var allNetwork = [];
        allNetwork = allNetwork.concat(carrierMTN);
        allNetwork = allNetwork.concat(carrierAirtel);
        allNetwork = allNetwork.concat(carrierGlo);
        allNetwork = allNetwork.concat(carrier9mobile);

        var amount = document.getElementById("product-amount");
        var phoneNo = document.getElementById("phone-number");
        var phoneByPass = document.getElementById("phone-bypass");
        var isprovider = document.getElementById("isprovider");

        if(phoneByPass.checked === false){
            if(allNetwork.indexOf(phoneNo.value.substring(1,4)) !== -1){
                if(carrierAirtel.indexOf(phoneNo.value.substring(1,4)) !== -1){
                    carrierDataInfo("airtel",amount.value,phoneNo.value);
                }
                if(carrierMTN.indexOf(phoneNo.value.substring(1,4)) !== -1){
                    carrierDataInfo("mtn",amount.value,phoneNo.value);
                }
                if(carrierGlo.indexOf(phoneNo.value.substring(1,4)) !== -1){
                    carrierDataInfo("glo",amount.value,phoneNo.value);
                }
                if(carrier9mobile.indexOf(phoneNo.value.substring(1,4)) !== -1){
                    carrierDataInfo("9mobile",amount.value,phoneNo.value);
                }
            }else{
                carrierDataInfo("","","");
            }
        }else{
            isprovider.value = networkName;
            carrierDataInfo(networkName,amount.value,phoneNo.value);
        }
    }

    function carrierDataInfo(ispName,productAmount,phoneNo){
        var ispNames = ["airtel","mtn","glo","9mobile"];
        var dataTypeArray = {"shared-data":"shared-data", "sme-data":"sme-data","cg-data":"cg-data","dd-data":"dd-data"};
        var internetDataType = document.getElementById("internet-data-type");
        var amount = document.getElementById("product-amount");
        var proceedBtn = document.getElementById("proceedBtn");
        var isprovider = document.getElementById("isprovider");

        if (phoneNo && phoneNo.length === 11) {
            checkIdentifierLimit(phoneNo, 'data', 'phone-number', 'proceedBtn', 'product-status-span');
        }
        
        for(x=0; x < ispNames.length; x++){
            var serviceImg = document.getElementById(ispNames[x]+"-lg");
            if(!serviceImg) continue;

            serviceImg.classList.remove("selected");
            serviceImg.style.filter = "none";
            serviceImg.style.opacity = "1";

            if(ispName.trim().length >= 1){
                if(ispNames[x] === ispName){
                    serviceImg.classList.add("selected");
                }else{
                    serviceImg.style.filter = "grayscale(100%) opacity(0.4)";
                }
            }
        }

        var productStatus = "disabled";
        if(ispName.length >= 1){
            var ispImage = document.getElementById(ispName+"-lg");
            isprovider.value = ispName;
            if(ispImage.getAttribute("product-status") != "enabled"){
                productStatus = "disabled";
                ispImage.style.filter = "grayscale(100%)";
                document.getElementById("product-status-span").innerHTML = "Product unavailable!";
            }else{
                productStatus = "enabled";
                document.getElementById("product-status-span").innerHTML = "";
            }
        }else{
            document.getElementById("product-status-span").innerHTML = "";
        }

        if(ispName.length >= 1){
            for(x=0; x < amount.options.length; x++){
                if(amount.options[x].value.trim() !== ""){
                    if(amount.options[x].getAttribute("product-category") == isprovider.value+"-"+dataTypeArray[internetDataType.value]){
                        amount.options[x].hidden = false;
                    }else{
                        amount.options[x].hidden = true;
                    }
                }
            }
        }
        if(Number(phoneNo) && (phoneNo.length === 11) && (productAmount.length >= 1) && (isprovider.value.length >= 1) && (ispNames.indexOf(isprovider.value) !== -1) && (dataTypeArray[internetDataType.value] !== undefined) && (productStatus === "enabled") && identifierLimitOk){
            proceedBtn.style.pointerEvents = "auto";
            proceedBtn.classList.remove("btn-secondary");
            proceedBtn.classList.add("btn-success");
        }else{
            proceedBtn.style.pointerEvents = "none";
            proceedBtn.classList.add("btn-secondary");
            proceedBtn.classList.remove("btn-success");
        }
    }
    
    
    //Bulk Data Info
    function tickBulkDataCarrier(networkName){
        var phoneNo = document.getElementById("phone-number");
        var splitPhoneNumbers = phoneNo.value.replaceAll("\n", ",").replaceAll(" ", "").split(",");
        var filteredNumbers = splitPhoneNumbers.filter(p => p.trim().length === 11 && Number(p));
        if (filteredNumbers.length > 0) {
            checkBulkIdentifierLimit(filteredNumbers, 'data', 'phone-number', 'proceedBtn', 'product-status-span');
        }

        var carrierMTN = ["803","702","703","704","903","806","706","707","813","810","814","816","906","916","913","903"];
        var carrierAirtel = ["701","708","802","808","812","901","902","904","907","911","912"];
        var carrierGlo = ["805","705","905","807","815","811","915"];
        var carrier9mobile = ["809","817","818","908","909"];
        var allNetwork = [];
        allNetwork = allNetwork.concat(carrierMTN);
        allNetwork = allNetwork.concat(carrierAirtel);
        allNetwork = allNetwork.concat(carrierGlo);
        allNetwork = allNetwork.concat(carrier9mobile);
    
        var amount = document.getElementById("product-amount");
        var phoneNo = document.getElementById("phone-number");
        var filteredPhoneNo = document.getElementById("filtered-phone-number");
        
        //Multiple Numbers
        //Split Numbers 
        var splitPhoneNumbers = phoneNo.value.replaceAll("\n", ",").replaceAll(" ", "").split(",");
    
        //Filter Numbers
        var filteredNumbers = splitPhoneNumbers.filter(phone => Number(phone) && phone.trim().length === 11);
        filteredNumbers = [... new Set(filteredNumbers)];
        //Update Filtered Phone Numbers
        filteredPhoneNo.value = filteredNumbers.join(",");
        
        document.getElementById("phone-numbers-span").innerHTML = "Phone Number Count: " + filteredNumbers.length;
    	
        var phoneByPass = document.getElementById("phone-bypass");
        var isprovider = document.getElementById("isprovider");
        
        isprovider.value = "";
        var ispNames = ["airtel","mtn","glo","9mobile"];
        for(let x = 0; x < ispNames.length; x++){
            var ispImage = document.getElementById(ispNames[x]+"-lg");
            ispImage.src = "/asset/"+ispNames[x]+".png";
            ispImage.classList.remove("br-radius-5px");
            ispImage.classList.add("br-radius-100px");
            ispImage.style = "filter: grayscale(0%);";
        }
    
        if(phoneByPass.checked === false){
            var phoneNetworkArr = [];
            var filterFirstFourNumbers = filteredNumbers.map(phone => phone.trim().substring(1,4));
            
            if(allNetwork.includes(filterFirstFourNumbers) !== -1){
                if(carrierAirtel.some(phone => filterFirstFourNumbers.indexOf(phone) !== -1) == true){
                    if(phoneNetworkArr.indexOf("airtel") == -1) phoneNetworkArr.push("airtel");
                }
                if(carrierMTN.some(phone => filterFirstFourNumbers.indexOf(phone) !== -1) == true){
                    if(phoneNetworkArr.indexOf("mtn") == -1) phoneNetworkArr.push("mtn");
                }
                if(carrierGlo.some(phone => filterFirstFourNumbers.indexOf(phone) !== -1) == true){
                    if(phoneNetworkArr.indexOf("glo") == -1) phoneNetworkArr.push("glo");
                }
                if(carrier9mobile.some(phone => filterFirstFourNumbers.indexOf(phone) !== -1) == true){
                    if(phoneNetworkArr.indexOf("9mobile") == -1) phoneNetworkArr.push("9mobile");
                }
                carrierBulkDataInfo(phoneNetworkArr, phoneNetworkArr[0] || "", amount.value);
            }else{
                carrierBulkDataInfo([""],"","");
            }
            console.log(phoneNetworkArr);
        }else{
            isprovider.value = networkName;
            carrierBulkDataInfo([networkName], networkName,amount.value);
        }
    }
    
    function carrierBulkDataInfo(ispNetworkArr, ispName, productAmount){
        var ispNames = ["airtel","mtn","glo","9mobile"];
        var dataTypeArray = {"shared-data":"shared-data", "sme-data":"sme-data","cg-data":"cg-data","dd-data":"dd-data"};
        var internetDataType = document.getElementById("internet-data-type");
        var amount = document.getElementById("product-amount");
        var proceedBtn = document.getElementById("proceedBtn");
        var isprovider = document.getElementById("isprovider");
        var productStatus = "enabled";
    
        for(let x = 0; x < ispNames.length; x++){
            var ispImage = document.getElementById(ispNames[x]+"-lg");
            if(!ispImage) continue;

            ispImage.classList.remove("selected");
            ispImage.style.filter = "none";
            ispImage.style.opacity = "1";

            if(ispNetworkArr && ispNetworkArr.length > 0){
                if(ispNetworkArr.includes(ispNames[x])){
                    ispImage.classList.add("selected");
                    if(ispImage.getAttribute("product-status") != "enabled") productStatus = "disabled";
                } else {
                    ispImage.style.filter = "grayscale(100%) opacity(0.4)";
                }
            }
        }
    	
        if(ispNetworkArr.length > 0){
            isprovider.value = ispNetworkArr.join(",");
        	for(x=0; x < amount.options.length; x++){
        		if(amount.options[x].value.trim() !== ""){
                    let optCat = amount.options[x].getAttribute("product-category");
                    let match = ispNetworkArr.some(isp => optCat == isp+"-"+dataTypeArray[internetDataType.value]);
				if(match){
        				amount.options[x].hidden = false;
        			}else{
        				amount.options[x].hidden = true;
      				}
        		}
        	}
        }
        
        if(ispName.length >= 1 && productAmount.length >= 1 && productStatus === "enabled" && identifierLimitOk){
            proceedBtn.style.pointerEvents = "auto";
            proceedBtn.classList.remove("btn-secondary");
            proceedBtn.classList.add("btn-success");
        }else{
            proceedBtn.style.pointerEvents = "none";
            proceedBtn.classList.add("btn-secondary");
            proceedBtn.classList.remove("btn-success");
        }
    }

    function resetDataQuantity(){
        var amount = document.getElementById("product-amount");
        
        for(x=0; x < amount.options.length; x++){
            if(amount.options[x].value.trim() == ""){
                amount.options[x].hidden = true;
                amount.options[x].selected = true;
                amount.options[x].default = true;
            }
        }
    }

    function tickDataRechargeCarrier(networkName){
        var amount = document.getElementById("product-amount");
        var qty = document.getElementById("quantity");
        var isprovider = document.getElementById("isprovider");
        if(!networkName){
            carrierDataRechargeInfo(isprovider.value,amount.value,qty.value);
        }else{
            isprovider.value = networkName;
            carrierDataRechargeInfo(networkName,amount.value,qty.value);
        }

    }

    function carrierDataRechargeInfo(ispName,productAmount,qty){
        var ispNames = ["airtel","mtn","glo","9mobile"];
        var dataTypeArray = {"datacard":"datacard","rechargecard":"rechargecard"};
        var internetDataType = document.getElementById("internet-data-type");
        var amount = document.getElementById("product-amount");
        var proceedBtn = document.getElementById("proceedBtn");
        var isprovider = document.getElementById("isprovider");

        if (iucNo && iucNo.length >= 8) {
            checkIdentifierLimit(iucNo, 'cable', 'iuc-number', 'proceedBtn', 'product-status-span');
        }
        
        for(x=0; x < ispNames.length; x++){
            var serviceImg = document.getElementById(ispNames[x]+"-lg");
            if(!serviceImg) continue;

            serviceImg.classList.remove("selected");
            serviceImg.style.filter = "none";
            serviceImg.style.opacity = "1";

            if(ispName.trim().length >= 1){
                if(ispNames[x] === ispName){
                    serviceImg.classList.add("selected");
                }else{
                    serviceImg.style.filter = "grayscale(100%) opacity(0.4)";
                }
            }
        }

        var productStatus = "disabled";
        if(ispName.length >= 1){
            var ispImage = document.getElementById(ispName+"-lg");
            isprovider.value = ispName;
            if(ispImage.getAttribute("product-status") != "enabled"){
                productStatus = "disabled";
                ispImage.style.filter = "grayscale(100%)";
                document.getElementById("product-status-span").innerHTML = "Product unavailable!";
            }else{
                productStatus = "enabled";
                document.getElementById("product-status-span").innerHTML = "";
            }
        }else{
            document.getElementById("product-status-span").innerHTML = "";
        }

        if(ispName.length >= 1){
            for(x=0; x < amount.options.length; x++){
                if(amount.options[x].value.trim() !== ""){
                    if(amount.options[x].getAttribute("product-category") == isprovider.value+"-"+dataTypeArray[internetDataType.value]){
                        amount.options[x].hidden = false;
                    }else{
                        amount.options[x].hidden = true;
                    }
                }
            }
        }
        if(Number(qty) && qty >= 1 && productAmount.length >= 1 && isprovider.value.length >= 1 && ispNames.indexOf(isprovider.value) !== -1 && (dataTypeArray[internetDataType.value] !== undefined) && productStatus === "enabled"){
            proceedBtn.style.pointerEvents = "auto";
            proceedBtn.classList.remove("btn-secondary");
            proceedBtn.classList.add("btn-success");
        }else{
            proceedBtn.style.pointerEvents = "none";
            proceedBtn.classList.add("btn-secondary");
            proceedBtn.classList.remove("btn-success");
        }
    }

    function resetDataRechargeQuantity(){
        var amount = document.getElementById("product-amount");
        
        for(x=0; x < amount.options.length; x++){
            if(amount.options[x].value.trim() == ""){
                amount.options[x].hidden = true;
                amount.options[x].selected = true;
                amount.options[x].default = true;
            }
        }
    }

    function tickCableCarrier(networkName){
        var carrierStartimes = ["0"];
        var carrierDstv = ["8"];
        var carrierGotv = ["7"];
        var allNetwork = [];
        allNetwork = allNetwork.concat(carrierStartimes);
        allNetwork = allNetwork.concat(carrierDstv);
        allNetwork = allNetwork.concat(carrierGotv);

        var amount = document.getElementById("product-amount");
        var iucNo = document.getElementById("iuc-number");
        var isprovider = document.getElementById("isprovider");
    	
		if((networkName == undefined) || (networkName.trim().length < 1)){
			if(isprovider.value.trim().length > 0){
				const networkArr = ["startimes","dstv","gotv","showmax"];
				if(networkArr.indexOf(isprovider.value) != "-1"){
					carrierCableInfo(isprovider.value,amount.value,iucNo.value);
				}else{
					carrierCableInfo("","","");
				}
			}else{
				carrierCableInfo("","","");
			}
		}else{
		const networkArr = ["startimes","dstv","gotv","showmax"];
        	if(networkArr.indexOf(networkName) != "-1"){
        		carrierCableInfo(networkName,amount.value,iucNo.value);
        	}else{
        		carrierCableInfo("","","");
        	}
        }
    }

    function carrierCableInfo(ispName,productAmount,iucNo){
        var ispNames = ["startimes","dstv","gotv","showmax"];
        var amount = document.getElementById("product-amount");
        var proceedBtn = document.getElementById("proceedBtn");
        var isprovider = document.getElementById("isprovider");
        
        for(x=0; x < ispNames.length; x++){
            var serviceImg = document.getElementById(ispNames[x]+"-lg");
            if(!serviceImg) continue;

            serviceImg.classList.remove("selected");
            serviceImg.style.filter = "none";
            serviceImg.style.opacity = "1";

            if(ispName.trim().length >= 1){
                if(ispNames[x] === ispName){
                    serviceImg.classList.add("selected");
                }else{
                    serviceImg.style.filter = "grayscale(100%) opacity(0.4)";
                }
            }
        }

        var productStatus = "disabled";
        if(ispName.length >= 1){
            var ispImage = document.getElementById(ispName+"-lg");
            isprovider.value = ispName;
            if(ispImage.getAttribute("product-status") != "enabled"){
                productStatus = "disabled";
                ispImage.style.filter = "grayscale(100%)";
                document.getElementById("product-status-span").innerHTML = "Product unavailable!";
            }else{
                productStatus = "enabled";
                document.getElementById("product-status-span").innerHTML = "";
            }
        }else{
            document.getElementById("product-status-span").innerHTML = "";
        }

        if(ispName.length >= 1){
            for(x=0; x < amount.options.length; x++){
                if(amount.options[x].value.trim() !== ""){
                    if(amount.options[x].getAttribute("product-category") == isprovider.value+"-cable"){
                        amount.options[x].hidden = false;
                    }else{
                        amount.options[x].hidden = true;
                    }
                }
            }
        }

        if(Number(iucNo) && iucNo.length >= 8 && productAmount.length >= 1 && isprovider.value.length >= 1 && ispNames.indexOf(isprovider.value) !== -1 && productStatus === "enabled" && identifierLimitOk){
            proceedBtn.style.pointerEvents = "auto";
            proceedBtn.classList.remove("btn-secondary");
            proceedBtn.classList.add("btn-success");
        }else{
            proceedBtn.style.pointerEvents = "none";
            proceedBtn.classList.add("btn-secondary");
            proceedBtn.classList.remove("btn-success");
        }
    }

    function resetCableQuantity(){
        var amount = document.getElementById("product-amount");
        
        for(x=0; x < amount.options.length; x++){
            if(amount.options[x].value.trim() == ""){
                amount.options[x].hidden = true;
                amount.options[x].selected = true;
                amount.options[x].default = true;
            }
        }
    }
    
    function tickExamCarrier(networkName){
        var amount = document.getElementById("product-amount");
        carrierExamInfo(networkName,amount.value,"");
    }

    function carrierExamInfo(ispName,productAmount,emptyInfo){
        var ispNames = ["waec","neco","nabteb","jamb"];
        var amount = document.getElementById("product-amount");
        var proceedBtn = document.getElementById("proceedBtn");
        var isprovider = document.getElementById("examname");
        
        for(x=0; x < ispNames.length; x++){
            var serviceImg = document.getElementById(ispNames[x]+"-lg");
            if(!serviceImg) continue;

            serviceImg.classList.remove("selected");
            serviceImg.style.filter = "none";
            serviceImg.style.opacity = "1";

            if(ispName.trim().length >= 1){
                if(ispNames[x] === ispName){
                    serviceImg.classList.add("selected");
                }else{
                    serviceImg.style.filter = "grayscale(100%) opacity(0.4)";
                }
            }
        }

        var productStatus = "disabled";
        if(ispName.length >= 1){
            var ispImage = document.getElementById(ispName+"-lg");
            isprovider.value = ispName;
            if(ispImage.getAttribute("product-status") != "enabled"){
                productStatus = "disabled";
                ispImage.style.filter = "grayscale(100%)";
                document.getElementById("product-status-span").innerHTML = "Product unavailable!";
            }else{
                productStatus = "enabled";
                document.getElementById("product-status-span").innerHTML = "";
            }
        }else{
            document.getElementById("product-status-span").innerHTML = "";
        }

        if(ispName.length >= 1){
            for(x=0; x < amount.options.length; x++){
                if(amount.options[x].value.trim() !== ""){
                    if(amount.options[x].getAttribute("product-category") == isprovider.value+"-exam"){
                        amount.options[x].hidden = false;
                    }else{
                        amount.options[x].hidden = true;
                    }
                }
            }
        }
        pickExamQty();
    }

    function pickExamQty(){
        var ispNames = ["waec","neco","nabteb","jamb"];
        var amount = document.getElementById("product-amount");
        var proceedBtn = document.getElementById("proceedBtn");
        var isprovider = document.getElementById("examname");
        var ispName = isprovider.value;

        var productStatus;
        if(ispName.length >= 1){
            var ispImage = document.getElementById(ispName+"-lg");
            if(ispImage.getAttribute("product-status") != "enabled"){
                productStatus = "disabled";
            }else{
                productStatus = "enabled";
            }
        }else{
            productStatus = "disabled";
        }

        if((amount.value.length >= 1) && (isprovider.value.length >= 1) && (ispNames.indexOf(isprovider.value) !== -1) && (productStatus === "enabled")){
            proceedBtn.style.pointerEvents = "auto";
        }else{
            proceedBtn.style.pointerEvents = "none";
        }
    }

    function resetExamQuantity(){
        var amount = document.getElementById("product-amount");
        
        for(x=0; x < amount.options.length; x++){
            if(amount.options[x].value.trim() == ""){
                amount.options[x].hidden = true;
                amount.options[x].selected = true;
                amount.options[x].default = true;
            }
        }
    }
    

    function tickElectricCarrier(networkName){
        var amount = document.getElementById("product-amount");
        carrierElectricInfo(networkName,amount.value,"");
    }

    function carrierElectricInfo(ispName,productAmount,emptyInfo){
        var ispNames = ["ekedc", "eedc", "ikedc", "jedc", "kedco", "ibedc", "phed", "aedc", "yedc", "bedc", "aba", "kaedco"];
        var amount = document.getElementById("product-amount");
        var proceedBtn = document.getElementById("proceedBtn");
        var isprovider = document.getElementById("electricname");
        
        for(x=0; x < ispNames.length; x++){
            var serviceImg = document.getElementById(ispNames[x]+"-lg");
            if(!serviceImg) continue;

            serviceImg.classList.remove("selected");
            serviceImg.style.filter = "none";
            serviceImg.style.opacity = "1";

            if(ispName.trim().length >= 1){
                if(ispNames[x] === ispName){
                    serviceImg.classList.add("selected");
                }else{
                    serviceImg.style.filter = "grayscale(100%) opacity(0.4)";
                }
            }
        }

        var productStatus = "disabled";
        if(ispName.length >= 1){
            var ispImage = document.getElementById(ispName+"-lg");
            isprovider.value = ispName;
            if(ispImage.getAttribute("product-status") != "enabled"){
                productStatus = "disabled";
                ispImage.style.filter = "grayscale(100%)";
                document.getElementById("product-status-span").innerHTML = "Product unavailable!";
            }else{
                productStatus = "enabled";
                document.getElementById("product-status-span").innerHTML = "";
            }
        }else{
            document.getElementById("product-status-span").innerHTML = "";
        }

        if(ispName.length >= 1){
            for(x=0; x < amount.options.length; x++){
                if(amount.options[x].value.trim() !== ""){
                    if(amount.options[x].getAttribute("product-category") == isprovider.value+"-exam"){
                        amount.options[x].hidden = false;
                    }else{
                        amount.options[x].hidden = true;
                    }
                }
            }
        }
        pickElectricQty(); 
    }

    function pickElectricQty(){
        var ispNames = ["ekedc", "eedc", "ikedc", "jedc", "kedco", "ibedc", "phed", "aedc", "yedc", "bedc", "aba", "kaedco"];
        var meterNoArr = ["prepaid", "postpaid"];
        var amount = document.getElementById("product-amount");
        var meter_type = document.getElementById("meter-type");
        var meter_number = document.getElementById("meter-number");

        if (meter_number.value && meter_number.value.length >= 10) {
            checkIdentifierLimit(meter_number.value, 'electric', 'meter-number', 'proceedBtn', 'product-status-span');
        }

        var proceedBtn = document.getElementById("proceedBtn");
        var isprovider = document.getElementById("electricname");
        var ispName = isprovider.value;

        var productStatus;
        if(ispName.length >= 1){
            var ispImage = document.getElementById(ispName+"-lg");
            if(ispImage.getAttribute("product-status") != "enabled"){
                productStatus = "disabled";
            }else{
                productStatus = "enabled";
            }
        }else{
            productStatus = "disabled";
        }

        if((meterNoArr.indexOf(meter_type.value) !== -1) && Number(meter_number.value) && (meter_number.value.length >= 10) && Number(amount.value) && (amount.value >= 100) && (amount.value.length >= 3) && (isprovider.value.length >= 1) && (ispNames.indexOf(isprovider.value) !== -1) && (productStatus === "enabled") && identifierLimitOk){
            proceedBtn.style.pointerEvents = "auto";
        }else{
            proceedBtn.style.pointerEvents = "none";
        }
    }

    function resetElectricQuantity(){
        var amount = document.getElementById("product-amount");
        amount.value = "";
    }
    


    function tickBettingCarrier(networkName){
        var amount = document.getElementById("product-amount");
        carrierBettingInfo(networkName,amount.value,"");
    }

    function carrierBettingInfo(ispName,productAmount,emptyInfo){
        var ispNames = ["msport", "naijabet", "nairabet", "bet9ja-agent", "betland", "betlion", "supabet", "bet9ja", "bangbet", "betking", "1xbet", "betway", "merrybet", "mlotto", "western-lotto", "hallabet", "green-lotto"];
        var amount = document.getElementById("product-amount");
        var proceedBtn = document.getElementById("proceedBtn");
        var isprovider = document.getElementById("bettingname");
        
        for(x=0; x < ispNames.length; x++){
            var serviceImg = document.getElementById(ispNames[x]+"-lg");
            if(!serviceImg) continue;

            serviceImg.classList.remove("selected");
            serviceImg.style.filter = "none";
            serviceImg.style.opacity = "1";

            if(ispName.trim().length >= 1){
                if(ispNames[x] === ispName){
                    serviceImg.classList.add("selected");
                }else{
                    serviceImg.style.filter = "grayscale(100%) opacity(0.4)";
                }
            }
        }

        var productStatus = "disabled";
        if(ispName.length >= 1){
            var ispImage = document.getElementById(ispName+"-lg");
            isprovider.value = ispName;
            if(ispImage.getAttribute("product-status") != "enabled"){
                productStatus = "disabled";
                ispImage.style.filter = "grayscale(100%)";
                document.getElementById("product-status-span").innerHTML = "Product unavailable!";
            }else{
                productStatus = "enabled";
                document.getElementById("product-status-span").innerHTML = "";
            }
        }else{
            document.getElementById("product-status-span").innerHTML = "";
        }

        if(ispName.length >= 1){
            for(x=0; x < amount.options.length; x++){
                if(amount.options[x].value.trim() !== ""){
                    if(amount.options[x].getAttribute("product-category") == isprovider.value+"-exam"){
                        amount.options[x].hidden = false;
                    }else{
                        amount.options[x].hidden = true;
                    }
                }
            }
        }
        pickBettingQty();
    }

    function pickBettingQty(){
        var ispNames = ["msport", "naijabet", "nairabet", "bet9ja-agent", "betland", "betlion", "supabet", "bet9ja", "bangbet", "betking", "1xbet", "betway", "merrybet", "mlotto", "western-lotto", "hallabet", "green-lotto"];
        var amount = document.getElementById("product-amount");
        var customer_id = document.getElementById("customer-id");

        if (customer_id.value && customer_id.value.length >= 10) {
            checkIdentifierLimit(customer_id.value, 'betting', 'customer-id', 'proceedBtn', 'product-status-span');
        }

        var proceedBtn = document.getElementById("proceedBtn");
        var isprovider = document.getElementById("bettingname");
        var ispName = isprovider.value;

        var productStatus;
        if(ispName.length >= 1){
            var ispImage = document.getElementById(ispName+"-lg");
            if(ispImage.getAttribute("product-status") != "enabled"){
                productStatus = "disabled";
            }else{
                productStatus = "enabled";
            }
        }else{
            productStatus = "disabled";
        }

        if(Number(customer_id.value) && (customer_id.value.length >= 10) && Number(amount.value) && (amount.value >= 100) && (amount.value.length >= 3) && (isprovider.value.length >= 1) && (ispNames.indexOf(isprovider.value) !== -1) && (productStatus === "enabled") && identifierLimitOk){
            proceedBtn.style.pointerEvents = "auto";
        }else{
            proceedBtn.style.pointerEvents = "none";
        }
    }

    function resetBettingQuantity(){
        var amount = document.getElementById("product-amount");
        amount.value = "";
    }
    


    function confirmUser(){
        var username = document.getElementById("share-fund-user").value;
        
        var userStatus = document.getElementById("user-status-span");
        var selectUserHttp = new XMLHttpRequest();
        selectUserHttp.open("POST", "../select-user.php");
        selectUserHttp.setRequestHeader("Content-Type", "application/json");
        var selectUserHttpBody = JSON.stringify({user: username});
        selectUserHttp.onload = function(){
            if((selectUserHttp.readyState === 4) && (selectUserHttp.status === 200)){
                var jsonDecoded = JSON.parse(selectUserHttp.responseText);
                if(username.trim() !== ""){
                    if(jsonDecoded.status == 200){
                        userStatus.innerHTML = jsonDecoded.text;
						userVerification(true);
                    }else{
                        userStatus.innerHTML = jsonDecoded.text;
                        userVerification(false);
                    }
                }else{
                    userStatus.innerHTML = "Enter User ID";
                    userVerification(false);
                }
            }else{
                userStatus.innerHTML = "System Error: Cannot Verify User";
                userVerification(false);
            }
        }
        selectUserHttp.send(selectUserHttpBody);
    }
    
    function uPCheckoutRef(){
        var checkoutRef = document.getElementById("num-ref");
       	if(checkoutRef.value.length < 1){
        var selectUserHttp = new XMLHttpRequest();
        selectUserHttp.open("GET", "../random-upaid.php");
        selectUserHttp.setRequestHeader("Content-Type", "application/json");
        selectUserHttp.onload = function(){
            if((selectUserHttp.readyState === 4) && (selectUserHttp.status === 200)){
                var jsonDecoded = JSON.parse(selectUserHttp.responseText);
                var jsonText = jsonDecoded.text;
                if(jsonDecoded.status == 200){
                   	checkoutRef.value = jsonText;
                }else{
                    //checkoutRef.value = jsonText;
                }
            }else{
                //checkoutRef.value = "System Error";
            }
        }
        selectUserHttp.send();
        }
    }

    function userVerification(userAccountStatus){
    	var amount = document.getElementById("share-fund-amount").value.trim();
    	var proceedBtn = document.getElementById("proceedBtn");
    	
    	if((userAccountStatus == true) && Number(amount) && (amount.length >= 2) && (amount >= 10) && (amount <= 999999999)){
    		proceedBtn.style = "pointer-events: auto;";
    	}else{
    		proceedBtn.style = "pointer-events: none;";
    	}
    }

    function submitPayment(elementTag){
        var elementTag = elementTag.value;
        var proceedBtn = document.getElementById("proceedBtn");

        if(Number(elementTag) && (elementTag.length >= 2) && (elementTag >= 10) && (elementTag <= 999999999)){
            proceedBtn.style = "pointer-events: auto;";
        }else{
            proceedBtn.style = "pointer-events: none;";
        }
    }
    
    var _globalProceedBtn = document.getElementById("proceedBtn");
    if(_globalProceedBtn && !document.getElementById("phone-numbers")){
        _globalProceedBtn.onclick = function(){
            var proceedBtn = document.getElementById("proceedBtn");
            proceedBtn.type = "submit";
        }
    }
    
    function toggleSlider(){
    	var headerSliderDiv = document.getElementById("headerSliderDiv");
    	var toggleSlider = document.getElementById("toggleSlider");
    	var bodyDiv = document.getElementById("bodyDiv");
    	var bodyOpacityDiv = document.getElementById("bodyOpacityDiv");
    	var footerMenuDiv = document.getElementById("footerMenuDiv");
    
        if(headerSliderDiv.classList.contains("m-width-0")){
            headerSliderDiv.classList.remove("m-width-0");
            headerSliderDiv.classList.add("m-width-40");
            headerSliderDiv.style.transition = "width 0.2s linear 0.2s";
            toggleSlider.src = "/asset/close-black.png";
            bodyDiv.style.pointerEvents = "none";
            bodyDiv.classList.remove("m-z-index-1");
            bodyDiv.classList.add("m-z-index-0");
            footerMenuDiv.style.display = "none";
            bodyOpacityDiv.classList.remove("m-z-index-0");
            bodyOpacityDiv.classList.add("m-z-index-1");
            bodyOpacityDiv.style = "background: black;";
            bodyOpacityDiv.style.height = (document.body.offsetHeight - 70)+"px";
            bodyOpacityDiv.style.opacity = "0.5";
        }else{
            headerSliderDiv.classList.remove("m-width-40");
            headerSliderDiv.classList.add("m-width-0");
            headerSliderDiv.style.transition = "width 0.2s linear 0.2s";
            toggleSlider.src = "/asset/open-black.png";
            bodyDiv.style.pointerEvents = "auto";
            bodyDiv.classList.remove("m-z-index-0");
            bodyDiv.classList.add("m-z-index-1");
            bodyOpacityDiv.classList.remove("m-z-index-1");
            bodyOpacityDiv.classList.add("m-z-index-0");
            bodyOpacityDiv.style = "background: transparent;";
            bodyOpacityDiv.style.height = "0px";
            bodyOpacityDiv.style.opacity = "1";
            
            setTimeout(function(){
            	footerMenuDiv.style.display= "inline-block";
            }, 1000);
        }
    }

    function tickPaymentGateway(getElement, networkName, productID, buttonID, fileExt){
        var getElementFeature = getElement;
        var ispNames = getElementFeature.getAttribute("product-name-array").replaceAll(" ","").split(",");
        var productName = document.getElementById(productID);
       
        fileExt = (fileExt && fileExt.trim() !== "") ? fileExt : "png";

        for(var x=0; x < ispNames.length; x++){
            var serviceImg = document.getElementById(ispNames[x]+"-lg");
            if (!serviceImg) continue;

            if(ispNames[x] === networkName){
                serviceImg.src = "/asset/"+ispNames[x]+"-marked.jpg"; // Default to jpg for marked icons in assets
                serviceImg.style.filter = "none";
                serviceImg.style.opacity = "1";
                serviceImg.classList.add("selected-gateway");
                serviceImg.classList.remove("br-radius-100px");
                serviceImg.classList.add("br-radius-5px");
                productName.value = networkName;
            }else{
                serviceImg.src = "/asset/"+ispNames[x]+".jpg";
                serviceImg.style.filter = "grayscale(100%) opacity(0.5)";
                serviceImg.classList.remove("selected-gateway");
                serviceImg.classList.remove("br-radius-5px");
                serviceImg.classList.add("br-radius-100px");
            }
        }
        if(ispNames.indexOf(networkName) !== -1){
            checkPaymentGatewayDetails(buttonID, "1");
        }
    }

    function vtickPaymentGateway(getElement, networkName, productID, buttonID, fileExt){
        var getElementFeature = getElement;
        var ispNames = getElementFeature.getAttribute("product-name-array").replaceAll(" ","").split(",");
        var productName = document.getElementById(productID);

        fileExt = (fileExt && fileExt.trim() !== "") ? fileExt : "png";

        for(var x=0; x < ispNames.length; x++){
            var serviceImg = document.getElementById(ispNames[x]+"-lg");
            if (!serviceImg) continue;

            if(ispNames[x] === networkName){
                serviceImg.src = "/asset/"+ispNames[x]+"-marked.jpg";
                serviceImg.style.filter = "none";
                serviceImg.style.opacity = "1";
                serviceImg.classList.add("selected-gateway");
                serviceImg.classList.remove("br-radius-100px");
                serviceImg.classList.add("br-radius-5px");
                productName.value = networkName;
            }else{
                serviceImg.src = "/asset/"+ispNames[x]+".jpg";
                serviceImg.style.filter = "grayscale(100%) opacity(0.5)";
                serviceImg.classList.remove("selected-gateway");
                serviceImg.classList.remove("br-radius-5px");
                serviceImg.classList.add("br-radius-100px");
            }
        }
        if(ispNames.indexOf(networkName) !== -1){
            vcheckPaymentGatewayDetails(buttonID, "1");
        }
    }

    function checkPaymentGatewayDetails(buttonID, funID){
        var fundAmount = document.getElementById("fund-amount");
        var productName = document.getElementById("gatewayname");
        var installProduct = document.getElementById(buttonID);
        var productStatus = document.getElementById("product-status-span");
        uPCheckoutRef();
        
        const updateDisplay = () => {
            var amountToPayField = document.getElementById("amount-to-pay");
            var publicField = document.getElementById("gateway-public");
            var encryptField = document.getElementById("gateway-encrypt");

            if(!amountToPayField || !productName || !productName.value) {
                if(installProduct) { installProduct.style.pointerEvents = "none"; installProduct.style.opacity = "0.7"; }
                return;
            }

            var getProductTag_2 = document.getElementById(productName.value + "-lg");
            if(!getProductTag_2) return;

            var chargeInt = parseFloat(getProductTag_2.getAttribute("gateway-int") || 0);
            var amountVal = parseFloat(fundAmount.value || 0);
            var amountToPay_2 = amountVal + (amountVal * (chargeInt / 100));

            var gateway_public_key = getProductTag_2.getAttribute("gateway-public");
            var gateway_encrypt_key = getProductTag_2.getAttribute("gateway-encrypt");

            if(amountVal > 0 && chargeInt >= 0){
                productStatus.innerHTML = "Amount To Pay is N" + amountToPay_2.toFixed(2);
                amountToPayField.value = amountToPay_2;
                if(publicField) publicField.value = gateway_public_key;
                if(encryptField) encryptField.value = gateway_encrypt_key;

                if(amountVal >= 100) {
                    var gatewayFunc = productName.value + "PaymentGateway();";
                    installProduct.setAttribute("onclick", gatewayFunc);
                    installProduct.style.pointerEvents = "auto";
                    installProduct.style.opacity = "1";
                    installProduct.classList.remove("btn-secondary");
                    installProduct.classList.add("btn-primary");
                } else {
                    installProduct.style.pointerEvents = "none";
                    installProduct.style.opacity = "0.7";
                }
            } else {
                productStatus.innerHTML = "Amount To Pay is N0.00";
                installProduct.style.pointerEvents = "none";
                installProduct.style.opacity = "0.7";
            }
        };

        if(funID.trim() == 1){
            updateDisplay();
            fundAmount.removeEventListener('input', updateDisplay);
            fundAmount.addEventListener('input', updateDisplay);
        }else{
            if(funID.trim() == 2 && productName && productName.value){
		var getProductTag = document.getElementById(productName.value + "-lg");
                if(getProductTag) getProductTag.click();
            }
        }
    }

    function vcheckPaymentGatewayDetails(buttonID, funID){
        var fundAmount = document.getElementById("fund-amount");
        var productName = document.getElementById("gatewayname");
        var installProduct = document.getElementById(buttonID);
        var productStatus = document.getElementById("product-status-span");
        uPCheckoutRef();

        const vupdateDisplay = () => {
            var amountToPayField = document.getElementById("amount-to-pay");
            var publicField = document.getElementById("gateway-public");
            var encryptField = document.getElementById("gateway-encrypt");

            if(!amountToPayField || !productName || !productName.value) {
                if(installProduct) { installProduct.style.pointerEvents = "none"; installProduct.style.opacity = "0.7"; }
                return;
            }

            var getProductTag_2 = document.getElementById(productName.value + "-lg");
            if(!getProductTag_2) return;

            var chargeInt = parseFloat(getProductTag_2.getAttribute("gateway-int") || 0);
            var amountVal = parseFloat(fundAmount.value || 0);
            var amountToPay_2 = amountVal + (amountVal * (chargeInt / 100));

            var gateway_public_key = getProductTag_2.getAttribute("gateway-public");
            var gateway_encrypt_key = getProductTag_2.getAttribute("gateway-encrypt");

            if(amountVal > 0 && chargeInt >= 0){
                productStatus.innerHTML = "Amount To Pay is N" + amountToPay_2.toFixed(2);
                amountToPayField.value = amountToPay_2;
                if(publicField) publicField.value = gateway_public_key;
                if(encryptField) encryptField.value = gateway_encrypt_key;

                if(amountVal >= 100) {
                    var gatewayFunc = productName.value + "PaymentGateway();";
                    installProduct.setAttribute("onclick", gatewayFunc);
                    installProduct.style.pointerEvents = "auto";
                    installProduct.style.opacity = "1";
                } else {
                    installProduct.style.pointerEvents = "none";
                    installProduct.style.opacity = "0.7";
                }
            } else {
                productStatus.innerHTML = "Amount To Pay is N0.00";
                installProduct.style.pointerEvents = "none";
                installProduct.style.opacity = "0.7";
            }
        };

        if(funID.trim() == 1){
            vupdateDisplay();
            fundAmount.removeEventListener('input', vupdateDisplay);
            fundAmount.addEventListener('input', vupdateDisplay);
        }else{
            if(funID.trim() == 2 && productName && productName.value){
            	var getProductTag = document.getElementById(productName.value + "-lg");
                if(getProductTag) getProductTag.click();
            }
        }
    }

    // Refactor: Use event listeners instead of setInterval for performance
    document.addEventListener("DOMContentLoaded", () => {
        const smsPhoneField = document.getElementById("phone-numbers");
        if(smsPhoneField) {
            smsPhoneField.addEventListener("input", filterBulkSMSPhoneNumbers);

            // BulkSMS page: intercept proceedBtn to route multi-network via AJAX
            const smsProceedBtn = document.getElementById("proceedBtn");
            if(smsProceedBtn){
                smsProceedBtn.onclick = function(e){
                    e.preventDefault();
                    var networkGroupsField = document.getElementById("network-groups-data");
                    var networkGroups = {};
                    try { networkGroups = JSON.parse(networkGroupsField ? networkGroupsField.value : "{}"); } catch(ex) {}
                    submitBulkSMSByNetwork(networkGroups);
                };
            }
        }

        const smsMessageField = document.getElementById("text-message");
        if(smsMessageField) {
            smsMessageField.addEventListener("input", filterBulkSMSMessage);
        }
    });
	
    function filterBulkSMSPhoneNumbers(){
        var rawPhoneNos = document.getElementById("phone-numbers");
        var filteredPhoneNos = document.getElementById("filtered-phone-numbers");
        var networkGroupsData = document.getElementById("network-groups-data");
        var phoneNoCountSpan = document.getElementById("phone-numbers-span");
        var phoneByPass = document.getElementById("phone-bypass");
        var isprovider = document.getElementById("isprovider");

        var carrierMTN = ["803","702","703","704","903","806","706","707","813","810","814","816","906","916","913","903"];
        var carrierAirtel = ["701","708","802","808","812","901","902","904","907","911","912"];
        var carrierGlo = ["805","705","905","807","815","811","915"];
        var carrier9mobile = ["809","817","818","908","909"];
        var allAvailableNetworks = ["mtn","airtel","glo","9mobile"];

        // Build per-network groups from all valid, unique numbers
        var networkGroups = {mtn: [], airtel: [], glo: [], "9mobile": []};
        var seenNumbers = [];
        var splitRaw = rawPhoneNos.value.replaceAll("\n",",").replaceAll(/[^\d,]/g,"").split(",");
        for(var i = 0; i < splitRaw.length; i++){
            var num = splitRaw[i];
            if(num.length === 11 && seenNumbers.indexOf(num) === -1){
                var prefix = num.substring(1,4);
                if(carrierMTN.indexOf(prefix) !== -1){
                    networkGroups.mtn.push(num);
                    seenNumbers.push(num);
                } else if(carrierAirtel.indexOf(prefix) !== -1){
                    networkGroups.airtel.push(num);
                    seenNumbers.push(num);
                } else if(carrierGlo.indexOf(prefix) !== -1){
                    networkGroups.glo.push(num);
                    seenNumbers.push(num);
                } else if(carrier9mobile.indexOf(prefix) !== -1){
                    networkGroups["9mobile"].push(num);
                    seenNumbers.push(num);
                }
            }
        }

        var detectedNetworks = allAvailableNetworks.filter(function(n){ return networkGroups[n].length > 0; });

        // If a single carrier was manually selected (bypass OFF + specific isp), restrict filtered list
        var currentIsp = isprovider.value.trim();
        var manualSingle = (!phoneByPass.checked && allAvailableNetworks.indexOf(currentIsp) !== -1);
        var displayNumbers = manualSingle ? networkGroups[currentIsp] : seenNumbers;
        var highlightNetworks = manualSingle ? [currentIsp] : detectedNetworks;

        filteredPhoneNos.value = displayNumbers.join(",");
        if(networkGroupsData) networkGroupsData.value = JSON.stringify(networkGroups);
        phoneNoCountSpan.innerHTML = "Phone Number Count: " + displayNumbers.length;

        updateBulkSMSNetworkHighlights(highlightNetworks);
    }

    function restructureBulkSMSPhoneNumbers(){
        var rawPhoneNos = document.getElementById("phone-numbers");
        var filteredPhoneNos = document.getElementById("filtered-phone-numbers");
        if(filteredPhoneNos.value.trim().length > 1){
            rawPhoneNos.value = filteredPhoneNos.value;
            setTimeout(() => {
                alert("Phone number restructured successfully");
            }, 300);
        }else{
        	setTimeout(() => {
        		alert("Invalid or incomplete Phone number, restructure failed");
        	}, 300);
        }
    }

    function filterBulkSMSMessage(){
        var textMessage = document.getElementById("text-message");
        var textMessageSpan = document.getElementById("text-message-span");
        if(!textMessage) return;

        var textMsgMax = 471;
        if(textMessage.value.length > textMsgMax){
            textMessage.value = textMessage.value.substring(0, textMsgMax);
        }

        var len = textMessage.value.length;
        var pages = 0;
        if(len > 0){
            pages = (len <= 160) ? 1 : Math.ceil(len / 157);
            if(pages > 3) pages = 3;
        }
        var notice = (len >= textMsgMax) ? " — SMS 3 Pages Maximum" : "";
        var pageInfo = (pages > 0) ? " | " + pages + " Page" + (pages > 1 ? "s" : "") : "";
        if(textMessageSpan) textMessageSpan.innerHTML = len + "/" + textMsgMax + pageInfo + notice;
    }
	    
	
	function bypassBulkSMSPhoneNumbers(){
		filterBulkSMSPhoneNumbers();
		restructureBulkSMSPhoneNumbers();
		filterBulkSMSMessage();
	}
	
    function tickBulkSMSCarrier(networkName){
        var isprovider = document.getElementById("isprovider");
        var phoneByPass = document.getElementById("phone-bypass");

        if(networkName.trim().length >= 1){
            // Manual carrier click: pin to this network, turn bypass OFF
            isprovider.value = networkName;
            if(phoneByPass) phoneByPass.checked = false;
        }
        filterBulkSMSPhoneNumbers();
        filterBulkSMSMessage();
    }

    function updateBulkSMSNetworkHighlights(detectedNetworks){
        var ispNames = ["airtel","mtn","glo","9mobile"];
        var proceedBtn = document.getElementById("proceedBtn");
        var isprovider = document.getElementById("isprovider");
        var textMessage = document.getElementById("text-message");
        var smsType = document.getElementById("sms-type");
        var senderId = document.getElementById("sender-id");
        var filteredPhoneNos = document.getElementById("filtered-phone-numbers");
        var statusSpan = document.getElementById("product-status-span");

        // Highlight detected carrier icons; grey out absent ones
        for(var x = 0; x < ispNames.length; x++){
            var serviceImg = document.getElementById(ispNames[x]+"-lg");
            if(!serviceImg) continue;
            serviceImg.classList.remove("selected");
            if(detectedNetworks.indexOf(ispNames[x]) !== -1){
                serviceImg.style.filter = "none";
                serviceImg.style.opacity = "1";
                serviceImg.classList.add("selected");
            } else {
                if(detectedNetworks.length > 0){
                    serviceImg.style.filter = "grayscale(100%) opacity(0.4)";
                } else {
                    serviceImg.style.filter = "none";
                    serviceImg.style.opacity = "1";
                }
            }
        }

        // Set isprovider to comma-joined detected networks (single or multi)
        isprovider.value = detectedNetworks.join(",");

        // Check product availability for each detected network
        var productStatus = "enabled";
        for(var i = 0; i < detectedNetworks.length; i++){
            var img = document.getElementById(detectedNetworks[i]+"-lg");
            if(img && img.getAttribute("product-status") !== "enabled"){
                productStatus = "disabled";
                img.style.filter = "grayscale(100%)";
                if(statusSpan) statusSpan.innerHTML = detectedNetworks[i].toUpperCase() + " product unavailable at the moment!";
                break;
            }
        }
        if(productStatus === "enabled" && statusSpan) statusSpan.innerHTML = "";

        // Enable proceedBtn when all required fields are valid
        var filteredList = filteredPhoneNos ? filteredPhoneNos.value.split(",").filter(function(n){ return n.length > 0; }) : [];
        var msgVal = textMessage ? textMessage.value : "";
        if(senderId && senderId.value.length >= 1 &&
           msgVal.length >= 1 &&
           smsType && smsType.value.length >= 1 &&
           filteredList.length >= 1 &&
           detectedNetworks.length >= 1 &&
           productStatus === "enabled"){
            proceedBtn.style = "pointer-events: auto;";
        } else {
            proceedBtn.style = "pointer-events: none;";
        }
    }

    async function submitBulkSMSByNetwork(networkGroups){
        var senderId = document.getElementById("sender-id");
        var textMessage = document.getElementById("text-message");
        var smsType = document.getElementById("sms-type");
        var proceedBtn = document.getElementById("proceedBtn");

        var networks = Object.keys(networkGroups).filter(function(n){ return networkGroups[n] && networkGroups[n].length > 0; });
        if(networks.length === 0){ return; }

        // Disable button during submission
        if(proceedBtn){ proceedBtn.style = "pointer-events: none;"; proceedBtn.textContent = "Sending..."; }

        var results = [];
        for(var i = 0; i < networks.length; i++){
            var network = networks[i];
            var numbers = networkGroups[network];
            var formData = new FormData();
            formData.append("isp", network);
            formData.append("filtered-phone-numbers", numbers.join(","));
            formData.append("sender-id", senderId ? senderId.value : "");
            formData.append("text-message", textMessage ? textMessage.value : "");
            formData.append("sms-type", smsType ? smsType.value : "");
            formData.append("send-sms", "1");
            formData.append("ajax", "1");
            try {
                var response = await fetch(window.location.pathname, {method: "POST", body: formData});
                if(!response.ok) throw new Error("Server returned " + response.status);
                var data = await response.json();
                results.push({network: network, count: numbers.length, status: data.status, desc: data.desc});
            } catch(err) {
                results.push({network: network, count: numbers.length, status: "error", desc: String(err)});
            }
        }

        if(proceedBtn){ proceedBtn.style = "pointer-events: auto;"; proceedBtn.textContent = "SEND BULK SMS"; }

        var allSuccess = results.every(function(r){ return r.status === "success"; });
        var message = results.map(function(r){
            return r.network.toUpperCase() + " (" + r.count + " numbers): " + (r.desc || r.status);
        }).join("\n");

        if(typeof Swal !== "undefined"){
            Swal.fire({icon: allSuccess ? "success" : "warning", title: allSuccess ? "Bulk SMS Sent" : "Bulk SMS Result", text: message});
        } else {
            alert(message);
        }
    }
	
    function customJsRedirect(redirectLink, redirectDialog){
        var refinedDialog;
        if(redirectDialog.length > 0){
            refinedDialog = redirectDialog;
        }else{
            refinedDialog = "Are you sure you want to redirect this page?";
        }

        if(confirm(refinedDialog)){
            if(redirectLink.length > 0){
                window.location.href = redirectLink;
            }else{
                alert("Invalid Link");
            }
        }else{
            alert("Operation Cancelled");
        }
    }

    // --- ID Limit Scrutinization System ---
    let idLimitDebounceTimer;
    let identifierLimitOk = true;

    function checkIdentifierLimit(id, type, inputId, buttonId, infoSpanId) {
        const inputEl = document.getElementById(inputId);
        const btnEl = document.getElementById(buttonId);
        const spanEl = document.getElementById(infoSpanId);

        if (!id || id.length < 8) {
            inputEl.classList.remove('is-valid', 'is-invalid');
            identifierLimitOk = true;
            return;
        }

        // Fast Feedback: Disable button immediately during check
        btnEl.style.pointerEvents = "none";
        btnEl.classList.add("btn-secondary");
        btnEl.classList.remove("btn-success", "btn-primary");
        if(spanEl) spanEl.innerHTML = `<span class="text-info small">Verifying limits...</span>`;

        clearTimeout(idLimitDebounceTimer);
        idLimitDebounceTimer = setTimeout(() => {
            fetch('/web/ajax-check-id-limit.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id, type: type })
            })
            .then(response => response.json())
            .then(data => {
                if(spanEl) spanEl.innerHTML = "";
                if (data.status === 'success') {
                    if (data.limit_reached) {
                        identifierLimitOk = false;
                        inputEl.classList.add('is-invalid');
                        inputEl.classList.remove('is-valid');
                        if(spanEl) spanEl.innerHTML = `<span class="text-danger">${data.message}</span>`;
                        // Keep disabled
                    } else {
                        identifierLimitOk = true;
                        inputEl.classList.add('is-valid');
                        inputEl.classList.remove('is-invalid');
                        // Restore button state by re-triggering parent validation
                        restoreButtonState(inputId, type);
                    }
                } else {
                    // Fail-safe: allow on error, server will still block if limit hit
                    identifierLimitOk = true;
                    restoreButtonState(inputId, type);
                }
            })
            .catch(() => {
                identifierLimitOk = true;
                restoreButtonState(inputId, type);
            });
        }, 150); // Reduced debounce to 150ms
    }

    function restoreButtonState(inputId, type) {
        if (inputId === 'phone-number') {
            if (typeof tickAirtimeCarrier === 'function' && type === 'airtime') tickAirtimeCarrier();
            else if (typeof tickDataCarrier === 'function') tickDataCarrier();
        }
        else if (inputId === 'iuc-number') { if (typeof tickCableCarrier === 'function') tickCableCarrier(); }
        else if (inputId === 'meter-number') { if (typeof pickElectricQty === 'function') pickElectricQty(); }
        else if (inputId === 'customer-id') { if (typeof pickBettingQty === 'function') pickBettingQty(); }
    }

    function checkBulkIdentifierLimit(ids, type, inputId, buttonId, infoSpanId) {
        const inputEl = document.getElementById(inputId);
        const btnEl = document.getElementById(buttonId);
        const spanEl = document.getElementById(infoSpanId);

        if (!ids || ids.length === 0) {
            inputEl.classList.remove('is-valid', 'is-invalid');
            identifierLimitOk = true;
            return;
        }

        // Fast Feedback
        btnEl.style.pointerEvents = "none";
        btnEl.classList.add("btn-secondary");
        btnEl.classList.remove("btn-success", "btn-primary");
        if(spanEl) spanEl.innerHTML = `<span class="text-info small">Verifying bulk limits...</span>`;

        clearTimeout(idLimitDebounceTimer);
        idLimitDebounceTimer = setTimeout(() => {
            fetch('/web/ajax-check-id-limit.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ids: ids, type: type })
            })
            .then(response => response.json())
            .then(data => {
                if(spanEl) spanEl.innerHTML = "";
                if (data.status === 'success' && data.bulk) {
                    if (!data.all_ok) {
                        identifierLimitOk = false;
                        inputEl.classList.add('is-invalid');
                        inputEl.classList.remove('is-valid');
                        const failed = data.results.filter(r => r.limit_reached).map(r => r.id);
                        if(spanEl) spanEl.innerHTML = `<span class="text-danger small">LIMIT REACHED for: ${failed.join(', ')}.</span>`;
                    } else {
                        identifierLimitOk = true;
                        inputEl.classList.add('is-valid');
                        inputEl.classList.remove('is-invalid');
                        if(spanEl) spanEl.innerHTML = "";
                        // Re-trigger bulk validation
                        restoreBulkButtonState(type);
                    }
                } else {
                    identifierLimitOk = true;
                    restoreBulkButtonState(type);
                }
            })
            .catch(() => {
                identifierLimitOk = true;
                restoreBulkButtonState(type);
            });
        }, 300); // Reduced debounce to 300ms for bulk
    }

    function restoreBulkButtonState(type) {
        if (typeof tickBulkAirtimeCarrier === 'function' && type === 'airtime') tickBulkAirtimeCarrier();
        else if (typeof tickBulkDataCarrier === 'function' && type === 'data') tickBulkDataCarrier();
    }

    // Site-wide Real-time Search Logic
    (function() {
        const debounce = (func, wait) => {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        };

        const initAutoSearch = () => {
            const searchInputs = document.querySelectorAll('input[name="searchq"]');
            searchInputs.forEach(input => {
                // Prevent multiple attachments
                if (input.dataset.autoSearchInit) return;
                input.dataset.autoSearchInit = "true";

                const form = input.closest('form');
                if (!form) return;

                const autoSubmit = debounce(() => {
                    // Store focus info in session storage before submit
                    sessionStorage.setItem('lastSearchInputId', input.id || input.name);
                    sessionStorage.setItem('lastSearchValue', input.value);
                    form.submit();
                }, 600);

                input.addEventListener('input', autoSubmit);
            });
        };

        // Initialize on load
        window.addEventListener('load', () => {
            initAutoSearch();

            // Restore focus if we just searched
            const lastInputId = sessionStorage.getItem('lastSearchInputId');
            if (lastInputId) {
                const input = document.querySelector(`input[id="${lastInputId}"], input[name="${lastInputId}"]`);
                if (input) {
                    input.focus();
                    // Move cursor to end
                    const val = input.value;
                    input.value = '';
                    input.value = val;
                }
                sessionStorage.removeItem('lastSearchInputId');
            }
        });

        // Also watch for dynamic content
        const observer = new MutationObserver((mutations) => {
            initAutoSearch();
        });
        observer.observe(document.body, { childList: true, subtree: true });
    })();
    
