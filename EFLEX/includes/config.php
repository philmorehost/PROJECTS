<?php
// ==========================================================================================
// APPLICATION CONFIGURATION
// ==========================================================================================

// --- Stripe API Keys ---
// Replace these placeholder keys with your actual keys from the Stripe Dashboard.
//
// Publishable Key: This is used on the client-side (in JavaScript) to initialize Stripe.js.
// It's safe to be public.
//
// Secret Key: This is used on the server-side (in PHP) to make API calls.
// IMPORTANT: Keep this key secret and secure. Do not expose it on the client-side.

define('STRIPE_PUBLISHABLE_KEY', 'pk_test_YOUR_PUBLISHABLE_KEY');
define('STRIPE_SECRET_KEY', 'sk_test_YOUR_SECRET_KEY');

?>
