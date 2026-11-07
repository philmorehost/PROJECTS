/**
 * Helper to convert Base64URL string to Uint8Array
 */
function base64urlToUint8Array(base64url) {
    if (!base64url || typeof base64url !== 'string') {
        console.error('Biometric: Invalid base64url input:', base64url);
        return new Uint8Array();
    }

    try {
        // Add padding if necessary
        const padding = '='.repeat((4 - base64url.length % 4) % 4);
        const base64 = (base64url + padding)
            .replace(/\-/g, '+')
            .replace(/_/g, '/');

        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    } catch (e) {
        console.error('Biometric: Failed to decode base64url string:', base64url);
        console.error('Error details:', e);
        throw e;
    }
}

/**
 * Helper to convert Uint8Array to Base64URL string
 */
function uint8ArrayToBase64url(uint8array) {
    const base64 = btoa(String.fromCharCode(...uint8array));
    return base64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
}

// Dynamically determine the AJAX endpoint path
let biometricAjaxUrl = 'biometric-ajax.php';
(function() {
    const scripts = document.getElementsByTagName('script');
    for (let i = 0; i < scripts.length; i++) {
        if (scripts[i].src.includes('biometric-handler.js')) {
            const src = scripts[i].src;
            const parts = src.split('asset/biometric-handler.js');
            if (parts.length > 1) {
                biometricAjaxUrl = parts[0] + 'web/biometric-ajax.php';
            }
            break;
        }
    }
})();

async function registerBiometric() {
    if (!window.PublicKeyCredential) {
        Swal.fire('Error', 'Biometric registration is not supported by this browser.', 'error');
        return;
    }

    try {
        const response = await fetch(biometricAjaxUrl + '?action=get_registration_options');
        const options = await response.json();
        console.log('Biometric: Raw registration options:', options);

        if (options.status === 'error') {
            Swal.fire('Error', options.message || 'Failed to get registration options', 'error');
            return;
        }

        console.log('Biometric: Registration options received', options);

        // Convert base64url strings to ArrayBuffers
        options.challenge = base64urlToUint8Array(options.challenge);
        options.user.id = base64urlToUint8Array(options.user.id);

        const credential = await navigator.credentials.create({
            publicKey: options
        });

        console.log('Biometric: Credential created', credential);

        const credentialResponse = {
            id: credential.id,
            rawId: uint8ArrayToBase64url(new Uint8Array(credential.rawId)),
            type: credential.type,
            response: {
                attestationObject: uint8ArrayToBase64url(new Uint8Array(credential.response.attestationObject)),
                clientDataJSON: uint8ArrayToBase64url(new Uint8Array(credential.response.clientDataJSON))
            }
        };

        const verificationResponse = await fetch(biometricAjaxUrl + '?action=verify_registration', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(credentialResponse)
        });

        const verificationResult = await verificationResponse.json();
        if (verificationResult.status === 'success') {
            Swal.fire('Success', 'Biometric registered successfully', 'success');
        } else {
            Swal.fire('Error', verificationResult.message || 'Registration failed', 'error');
        }
    } catch (error) {
        console.error('Biometric registration error:', error);
        Swal.fire('Error', 'An unexpected error occurred during biometric registration', 'error');
    }
}

async function loginWithBiometric() {
    if (!window.PublicKeyCredential) {
        Swal.fire('Error', 'Biometric authentication is not supported by this browser.', 'error');
        return;
    }

    try {
        console.log('Biometric: Attempting login...');
        const response = await fetch(biometricAjaxUrl + '?action=get_authentication_options');
        const options = await response.json();
        console.log('Biometric: Raw authentication options:', options);

        if (options.status === 'error') {
            Swal.fire('Error', options.message || 'Failed to get login options', 'error');
            return;
        }

        console.log('Biometric: Authentication options received', options);

        options.challenge = base64urlToUint8Array(options.challenge);
        if (options.allowCredentials) {
            options.allowCredentials = options.allowCredentials.map(c => ({
                ...c,
                id: base64urlToUint8Array(c.id)
            }));
        }

        const assertion = await navigator.credentials.get({
            publicKey: options
        });

        console.log('Biometric: Assertion received', assertion);

        const assertionResponse = {
            id: assertion.id,
            rawId: uint8ArrayToBase64url(new Uint8Array(assertion.rawId)),
            type: assertion.type,
            response: {
                authenticatorData: uint8ArrayToBase64url(new Uint8Array(assertion.response.authenticatorData)),
                clientDataJSON: uint8ArrayToBase64url(new Uint8Array(assertion.response.clientDataJSON)),
                signature: uint8ArrayToBase64url(new Uint8Array(assertion.response.signature)),
                userHandle: assertion.response.userHandle ? uint8ArrayToBase64url(new Uint8Array(assertion.response.userHandle)) : null
            }
        };

        const verificationResponse = await fetch(biometricAjaxUrl + '?action=verify_authentication', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(assertionResponse)
        });

        const verificationResult = await verificationResponse.json();
        if (verificationResult.status === 'success') {
            window.location.href = 'Dashboard.php';
        } else {
            Swal.fire('Error', verificationResult.message || 'Authentication failed', 'error');
        }
    } catch (error) {
        console.error('Biometric login error:', error);
        if (error.name === 'NotAllowedError') {
             // User cancelled or timeout
        } else {
            Swal.fire('Error', 'An unexpected error occurred during biometric login', 'error');
        }
    }
}
