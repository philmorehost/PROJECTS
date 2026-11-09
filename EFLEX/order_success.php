<?php
// Include the header
include 'includes/header.php';
?>

<div class="container mt-5 text-center">
    <div id="message-container">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
        <h4>Processing your order...</h4>
        <p>Please do not close this window.</p>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const messageContainer = document.getElementById('message-container');

    // Retrieve the Payment Intent ID from the URL
    const urlParams = new URLSearchParams(window.location.search);
    const paymentIntentId = urlParams.get('payment_intent');
    const paymentIntentClientSecret = urlParams.get('payment_intent_client_secret');

    if (!paymentIntentId) {
        messageContainer.innerHTML = `
            <div class="alert alert-danger">
                <h4 class="alert-heading">Error!</h4>
                <p>No payment information was found. Your order cannot be confirmed.</p>
                <hr>
                <p class="mb-0">Please contact support or return to the <a href="index.php">homepage</a>.</p>
            </div>`;
        return;
    }

    // NOTE: In a real application, you would first use the client secret to retrieve
    // the PaymentIntent from Stripe's API to get the latest status before sending
    // it to your server to finalize. For this scaffold, we send it directly.

    // Call your backend to finalize the order
    fetch('finalize_order.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ payment_intent: paymentIntentId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            messageContainer.innerHTML = `
                <div class="alert alert-success">
                    <h4 class="alert-heading">Thank You!</h4>
                    <p>Your order has been placed successfully.</p>
                    <hr>
                    <p class="mb-0">Your Order ID is: <strong>${data.orderId}</strong></p>
                </div>
                <a href="index.php" class="btn btn-primary">Go to Homepage</a>`;
        } else {
            messageContainer.innerHTML = `
                <div class="alert alert-danger">
                    <h4 class="alert-heading">Order Failed</h4>
                    <p>There was a problem processing your order. Please contact support.</p>
                    <p class="mb-0">Details: ${data.message || 'An unknown error occurred.'}</p>
                </div>`;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        messageContainer.innerHTML = `
            <div class="alert alert-danger">
                <h4 class="alert-heading">Error!</h4>
                <p>A network error occurred while finalizing your order. Please check your order history or contact support.</p>
            </div>`;
    });
});
</script>

<?php
// Include the footer
include 'includes/footer.php';
?>
