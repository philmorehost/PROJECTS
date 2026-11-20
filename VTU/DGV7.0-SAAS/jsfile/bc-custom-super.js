
    /* \u00a730: order approvals moved to the step-up modal on the page itself
       (POST + CSRF + Security PIN + authenticator/email code). The old GET
       handler (order-status/order-ref) no longer has a server-side action. */
function updateVendorAccountStatus(status, userID, user){
    	// §33: vendor account actions are POST + CSRF + step-up now; a GET link
    	// can no longer activate/suspend/delete a vendor. Vendors.php defines
    	// sec33OpenVendorModal(); other pages must not call this at all.
    	var statusArray = ["1","2","3"];
    	var actionArray = {"1":"activate", "2":"suspend", "3":"delete"};
    	if(Number(status) && (statusArray.indexOf(status) !== -1)){
    		if(typeof sec33OpenVendorModal === "function"){
    			sec33OpenVendorModal('status', String(status), userID, user, 'Confirm vendor action', "Are you sure you want to "+actionArray[status]+" "+user+"'s account? This needs your Security PIN (plus an authenticator code to activate or delete) and is audited.", 'Confirm');
    		}else{
    			alert("Open the Vendors page to perform this action securely.");
    		}
    	}else{
    		alert("Invalid Account Status");
    	}
    }

    function loginVendorAccount(idNumber, user){
        if(Number(idNumber) && (idNumber >= 1)){
            if(user.trim() !== ""){
                // §33: impersonation is POST + CSRF + step-up (see Vendors.php).
                if(typeof sec33OpenVendorModal === "function"){
                    sec33OpenVendorModal('log', '', idNumber, user, 'Login as vendor?', "You will be logged into "+user+"'s dashboard. This needs your PIN + authenticator code and is audited.", 'Login as vendor');
                }else{
                    alert("Open the Vendors page to perform this action securely.");
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
                if(typeof sec33OpenVendorModal === "function"){
                    sec33OpenVendorModal('log', '', idNumber, user, 'Manage vendor API?', "You will be logged into "+user+"'s dashboard to manage their API. This needs your PIN + authenticator code and is audited.", 'Continue', 'manageapi');
                }else{
                    alert("Open the Vendors page to perform this action securely.");
                }
            }else{
                alert("Error: User is missing");
            }
    	}else{
    		alert("Error: Failed! Invalid Account ID");
    	}
    }