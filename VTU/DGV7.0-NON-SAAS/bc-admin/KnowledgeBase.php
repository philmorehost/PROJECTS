<?php session_start();
    include("../func/bc-admin-config.php");
?>
<!DOCTYPE html>
<head>
    <title>Knowledge Base | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="description" content="<?php echo substr($get_all_super_admin_site_details["site_desc"], 0, 160); ?>" />
    <meta http-equiv="Content-Type" content="text/html; " />
    <meta name="theme-color" content="black" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <meta name="author" content="Philmore Codes">
    <meta name="dc.creator" content="Philmore Codes">

  <!-- Vendor CSS Files -->
  <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/quill/quill.snow.css" rel="stylesheet">
  <link href="../assets-2/vendor/quill/quill.bubble.css" rel="stylesheet">
  <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">
  <link href="../assets-2/vendor/simple-datatables/style.css" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include("../func/bc-admin-header.php"); ?>

    <div class="pagetitle">
      <h1>Knowledge Base</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
          <li class="breadcrumb-item active">Knowledge Base</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row g-4">
        <div class="col-lg-12">
          <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
            <div class="card-header bg-white py-4 border-0 d-flex align-items-center">
                <div class="bg-primary bg-opacity-10 p-2 rounded-3 me-3"><i class="bi bi-book text-dark-primary fs-4"></i></div>
                <h5 class="fw-bold mb-0">Vendor Setup and Usage Guide</h5>
            </div>
            <div class="card-body p-4">

              <div class="accordion accordion-flush" id="knowledgeBaseAccordion">

                <!-- How to order VTU vendor website -->
                <div class="accordion-item">
                  <h2 class="accordion-header" id="headingOne">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne" aria-expanded="false" aria-controls="collapseOne">
                      How to order VTU vendor website
                    </button>
                  </h2>
                  <div id="collapseOne" class="accordion-collapse collapse" aria-labelledby="headingOne" data-bs-parent="#knowledgeBaseAccordion">
                    <div class="accordion-body">
                      To get your own VTU vendor website, you need to contact the super admin. They will provide you with the necessary information and guide you through the process of setting up your own website.
                    </div>
                  </div>
                </div>

                <!-- How to buy API in the market place -->
                <div class="accordion-item">
                  <h2 class="accordion-header" id="headingTwo">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo" aria-expanded="false" aria-controls="collapseTwo">
                      How to buy API in the market place
                    </button>
                  </h2>
                  <div id="collapseTwo" class="accordion-collapse collapse" aria-labelledby="headingTwo" data-bs-parent="#knowledgeBaseAccordion">
                    <div class="accordion-body">
                      Once your vendor website is activated, you can purchase APIs from the marketplace. Navigate to the "Marketplace" section in your admin panel. Here you will find a list of available APIs. You can add them to your cart and proceed to checkout.
                    </div>
                  </div>
                </div>

                <!-- How to pre-install all products -->
                <div class="accordion-item">
                  <h2 class="accordion-header" id="headingThree">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree" aria-expanded="false" aria-controls="collapseThree">
                      How to pre-install all products
                    </button>
                  </h2>
                  <div id="collapseThree" class="accordion-collapse collapse" aria-labelledby="headingThree" data-bs-parent="#knowledgeBaseAccordion">
                    <div class="accordion-body">
                        You can pre-install all products from the "Product Set Up" page. Navigate to <strong>Product Set Up</strong> in your admin panel. On this page, you will find a list of all available products. You can select the products you want to install and click the "Install" button.
                    </div>
                  </div>
                </div>

                <!-- How to install the API and add the API key -->
                <div class="accordion-item">
                  <h2 class="accordion-header" id="headingFour">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFour" aria-expanded="false" aria-controls="collapseFour">
                      How to install the API, add the API key, install products, and update prices/discounts
                    </button>
                  </h2>
                  <div id="collapseFour" class="accordion-collapse collapse" aria-labelledby="headingFour" data-bs-parent="#knowledgeBaseAccordion">
                    <div class="accordion-body">
                      <ol>
                        <li><strong>Install API:</strong> Go to the specific product page (e.g., Airtime, Data). Under "API SETTING", select the API you purchased from the marketplace.</li>
                        <li><strong>Add API Key:</strong> After selecting the API, you will see a field to add your API key. Enter the key and click "UPDATE KEY".</li>
                        <li><strong>Install Products:</strong> In the "PRODUCT INSTALLATION" section, you can select the products you want to offer. You can use the "SELECT ALL" button to install all products at once. After selecting the products and the API, choose the status (Enabled/Disabled) and click "INSTALL PRODUCT".</li>
                        <li><strong>Update Prices/Discounts:</strong> After installing a product, the pricing/discount fields will be displayed. You can set the prices for different user levels (Smart Earner, Agent Vendor, API Vendor). You can also upload prices in bulk using a CSV file. A sample CSV file can be downloaded from the page.</li>
                      </ol>
                    </div>
                  </div>
                </div>

                <!-- How to set the payment gateways -->
                <div class="accordion-item">
                  <h2 class="accordion-header" id="headingFive">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFive" aria-expanded="false" aria-controls="collapseFive">
                      How to set the payment gateways
                    </button>
                  </h2>
                  <div id="collapseFive" class="accordion-collapse collapse" aria-labelledby="headingFive" data-bs-parent="#knowledgeBaseAccordion">
                    <div class="accordion-body">
                      Navigate to the "Payment Gateway" page in your admin panel. Here you can configure the payment gateways you want to use. You will need to provide the necessary API keys and credentials for each gateway.
                    </div>
                  </div>
                </div>

                <!-- How to update the account settings -->
                <div class="accordion-item">
                  <h2 class="accordion-header" id="headingSix">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseSix" aria-expanded="false" aria-controls="collapseSix">
                      How to update the account settings
                    </button>
                  </h2>
                  <div id="collapseSix" class="accordion-collapse collapse" aria-labelledby="headingSix" data-bs-parent="#knowledgeBaseAccordion">
                    <div class="accordion-body">
                      Go to "Account Settings" to update your personal and business information. The sections include:
                      <ul>
                        <li><strong>Personal Information:</strong> Update your name, email, and phone number.</li>
                        <li><strong>Business Information:</strong> Update your business name, address, and other details.</li>
                        <li><strong>Bank Details:</strong> Update your bank account information for payouts.</li>
                        <li><strong>Security:</strong> Change your password and set up two-factor authentication.</li>
                      </ul>
                    </div>
                  </div>
                </div>

                <!-- How to use the blog -->
                <div class="accordion-item">
                  <h2 class="accordion-header" id="headingSeven">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseSeven" aria-expanded="false" aria-controls="collapseSeven">
                      How to use the blog
                    </button>
                  </h2>
                  <div id="collapseSeven" class="accordion-collapse collapse" aria-labelledby="headingSeven" data-bs-parent="#knowledgeBaseAccordion">
                    <div class="accordion-body">
                      The blog is a great way to communicate with your users. You can create new posts, manage categories, and edit existing posts from the "Blog" section in your admin panel.
                    </div>
                  </div>
                </div>



              </div>

            </div>
          </div>
        </div>
      </div>
    </section>

    <?php include("../func/bc-admin-footer.php"); ?>
</body>
</html>