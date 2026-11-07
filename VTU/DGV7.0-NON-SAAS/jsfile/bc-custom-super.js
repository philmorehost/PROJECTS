
    function superAdminPaymentOrderStatus(status, reference, user){
    	var statusArray = ["1","2"];
    	var actionArray = {"1":"reject", "2":"accept"};
    	if(Number(status) && (statusArray.indexOf(status) !== -1)){
    		if(confirm("Are you sure you want to "+actionArray[status]+" "+user+"'s payment order?")){
    			window.location.href = "/bc-spadmin/PaymentOrders.php?order-status="+status+"&order-ref="+reference;
    		}else{
    			alert("Action Cancelled");
    		}
    	}else{
    		alert("Invalid Payment Status");
    	}
    }

    function updateVendorAccountStatus(status, userID, user){
    	var statusArray = ["1","2","3"];
    	var actionArray = {"1":"activate", "2":"block", "3":"delete"};
    	if(Number(status) && (statusArray.indexOf(status) !== -1)){
    		if(confirm("Are you sure you want to "+actionArray[status]+" "+user+"'s account?")){
    			window.location.href = "/bc-spadmin/Vendors.php?account-status="+status+"&account-username="+user+"&account-id="+userID;
    		}else{
    			alert("Action Cancelled");
    		}
    	}else{
    		alert("Invalid Account Status");
    	}
    }

    function loginVendorAccount(idNumber, user){
        if(Number(idNumber) && (idNumber >= 1)){
            if(user.trim() !== ""){
                if(confirm("Are you sure you want to Login "+user+"'s account?")){
                    window.location.href = "/bc-spadmin/Vendors.php?account-log="+idNumber;
                }else{
                    alert("Action Cancelled");
                }
            }else{
                alert("Error: User is missing");
            }
    	}else{
    		alert("Error: Login Failed! Invalid Account ID");
    	}
    }

    function manageVendorApi(idNumber, user){
        if(Number(idNumber) && (idNumber >= 1)){
            if(user.trim() !== ""){
                if(confirm("Are you sure you want to Manage "+user+"'s API?")){
                    window.location.href = "/bc-spadmin/Vendors.php?account-log="+idNumber+"&&type=manageapi";
                }else{
                    alert("Action Cancelled");
                }
            }else{
                alert("Error: User is missing");
            }
    	}else{
    		alert("Error: Failed! Invalid Account ID");
    	}
    }