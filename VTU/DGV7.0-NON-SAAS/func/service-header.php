<div class="row mb-4">
    <div class="col-12">
        <div class="card bg-primary text-white shadow-sm border-0 rounded-4">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div>
                    <div class="small fw-semibold opacity-75 mb-1 text-uppercase ls-1">Available Balance</div>
                    <div class="h4 fw-bold mb-0">₦<?php echo toDecimal($get_logged_user_details["balance"], "2"); ?></div>
                </div>
                <div class="bg-white bg-opacity-20 p-2 rounded-circle">
                    <i class="bi bi-wallet2 fs-5 text-white"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<?php 
    $current_page = basename($_SERVER['PHP_SELF']);
    $ai_suggestion = "";
    $ai_service_label = "";
    
    switch($current_page) {
        case "Airtime.php":
            $ai_suggestion = "Buy MTN 500 airtime for 08012345678";
            $ai_service_label = "Airtime";
            break;
        case "Data.php":
            $ai_suggestion = "Buy Glo 1GB SME data for 08123456789";
            $ai_service_label = "Data";
            break;
        case "Cable.php":
            $ai_suggestion = "Pay for DSTV Compact on 1023456789";
            $ai_service_label = "Cable TV";
            break;
        case "Electric.php":
            $ai_suggestion = "Pay 2000 for IKEDC Prepaid 14123456789";
            $ai_service_label = "Electricity";
            break;
        case "Betting.php":
            $ai_suggestion = "Fund Sportybet with 1000 on 78945612";
            $ai_service_label = "Betting";
            break;
        case "Exam.php":
            $ai_suggestion = "Buy 1 WAEC pin";
            $ai_service_label = "Exam Pins";
            break;
    }
    
    // AI Assistant Shortcut removed from service pages to keep service UI clean.
?>

<style>
    .ls-1 { letter-spacing: 1px; }
    .rounded-4 { border-radius: 1rem !important; }
    .rounded-5 { border-radius: 1.5rem !important; }
    .form-control, .form-select {
        border-radius: 0.75rem;
        padding: 0.75rem 1.25rem;
        border: 1px solid #e2e8f0;
    }
    .btn {
        border-radius: 0.75rem;
        padding: 0.75rem 1.5rem;
        font-weight: 600;
    }
    .btn-lg {
        border-radius: 1rem;
    }
    .card {
        border-radius: 1.25rem;
    }
    .carrier-grid {
        display: flex;
        justify-content: center;
        gap: 1rem;
        flex-wrap: nowrap;
        overflow-x: auto;
        padding-bottom: 1rem;
        -webkit-overflow-scrolling: touch;
    }
    .carrier-grid img {
        transition: all 0.2s;
        cursor: pointer;
        opacity: 1;
        border: 2px solid transparent !important;
        width: 150px;
        height: 150px;
        object-fit: contain;
        flex-shrink: 0;
    }
    @media (max-width: 767px) {
        .carrier-grid img {
            width: 100px;
            height: 100px;
        }
    }
    .carrier-grid img.selected {
        opacity: 1;
        border-color: #287bff !important;
        transform: scale(1.05);
        box-shadow: 0 4px 12px rgba(40, 123, 255, 0.2);
    }
</style>
