<?php
require_once 'includes/config.php';

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Ensure the featured_image column exists before inserting
    try {
        $pdo->exec("ALTER TABLE blog_posts ADD COLUMN featured_image VARCHAR(255) DEFAULT NULL AFTER excerpt");
        echo "Added missing 'featured_image' column to database.<br>";
    } catch (PDOException $e) {
        // Column likely already exists, continue safely
    }

    $posts = [
        [
            'slug' => 'getting-started-integration',
            'title' => 'Getting Started with Payhub Integration',
            'content' => "Welcome to Payhub! Our APIs are designed to be simple, powerful, and easy to integrate into any platform. Whether you are building a custom e-commerce site, a mobile application, or a SaaS platform, Payhub provides the tools you need to start accepting payments in minutes.\n\n### Why Choose Payhub?\nPayhub offers a robust infrastructure that ensures high success rates, top-tier security, and seamless developer experience. Our comprehensive documentation covers everything from basic payment links to complex recurring billing setups.\n\n### First Steps\n1. **Create an Account:** Sign up on the Payhub dashboard to get your test and live API keys.\n2. **Read the Docs:** Familiarize yourself with our API endpoints.\n3. **Test Mode:** Use our sandbox environment to simulate transactions safely.\n4. **Go Live:** Swap out your test keys for live keys and start accepting real money!\n\nIntegration shouldn't be a hassle. With our SDKs and libraries available for PHP, Node.js, Python, and more, you can focus on building your core product while we handle the payments.",
            'excerpt' => 'Learn how to easily integrate Payhub into your platform and start accepting payments in minutes.',
            'featured_image' => 'payhub_integration.png'
        ],
        [
            'slug' => 'understanding-fees',
            'title' => 'Understanding Transaction Fees',
            'content' => "At Payhub, we believe in transparent pricing to help your business thrive without hidden costs. Understanding how our fees work allows you to plan your finances better and price your products accurately.\n\n### Local Transactions\nFor local transactions, Payhub charges a competitive rate of **1.5% + NGN 100** per transaction. We also cap the fees at NGN 2,000, meaning no matter how large the transaction is, you will never pay more than NGN 2,000 in fees.\n\n### International Transactions\nFor international cards, the fee is slightly higher at **3.9% + NGN 100** to account for the increased processing costs and currency conversion risks associated with cross-border payments.\n\n### Payout Fees\nWhen you withdraw your settled funds to your local bank account, a flat fee of **NGN 50** applies per payout request. \n\nOur pricing is designed to scale with your business. There are no setup fees, no monthly minimums, and no hidden charges. You only pay for what you process.",
            'excerpt' => 'A comprehensive breakdown of Payhub\'s transparent pricing model for local and international payments.',
            'featured_image' => 'transaction_fees.png'
        ],
        [
            'slug' => 'virtual-accounts-setup',
            'title' => 'Setting up Virtual Bank Accounts',
            'content' => "Bank transfers remain one of the most popular payment methods. Payhub's Virtual Accounts feature allows you to issue dedicated, automated bank accounts to your customers, making reconciliation a breeze.\n\n### How Virtual Accounts Work\nWhen you create a virtual account for a customer, they receive a unique bank account number tied specifically to them. Any transfer made to this account automatically triggers a webhook to your system, marking their invoice or wallet as paid instantly.\n\n### Key Benefits\n- **Automated Reconciliation:** No more checking bank statements to confirm who paid.\n- **Always Available:** Customers can transfer funds 24/7, even on weekends.\n- **Professionalism:** Provide a seamless, corporate payment experience.\n\n### Integration\nSetting up virtual accounts is as easy as making a single API call to our `/virtual-accounts` endpoint with the customer's details. Once provisioned, the account remains active for as long as you need it.",
            'excerpt' => 'Learn how to issue dedicated virtual bank accounts to automate bank transfer reconciliation.',
            'featured_image' => 'virtual_accounts.png'
        ],
        [
            'slug' => 'managing-payouts',
            'title' => 'Managing Your Payouts',
            'content' => "Cash flow is the lifeblood of any business. Payhub ensures that your settled funds are easily accessible through our robust payout management system.\n\n### Settlement Cycles\nBy default, Payhub settles local transactions on a T+1 schedule (the day after the transaction occurs). Once funds are settled, they appear in your Available Balance.\n\n### Manual vs Automated Payouts\nYou can choose how you want to receive your money:\n- **Manual Payouts:** Leave funds in your Payhub wallet and request a withdrawal to your linked bank account whenever you need it via the dashboard or API.\n- **Automated Payouts:** Configure Payhub to automatically sweep your Available Balance to your bank account daily, weekly, or monthly.\n\n### Managing Payouts via API\nFor platforms managing sub-merchants, our Transfers API allows you to programmatically route funds to any bank account, splitting payments and managing commissions with ease.",
            'excerpt' => 'A guide to managing your cash flow, settlement cycles, and automated payouts with Payhub.',
            'featured_image' => 'managing_payouts.png'
        ]
    ];

    foreach ($posts as $post) {
        // Check if the post exists
        $stmt = $pdo->prepare("SELECT id FROM blog_posts WHERE slug = ?");
        $stmt->execute([$post['slug']]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Update existing
            $update = $pdo->prepare("UPDATE blog_posts SET title = ?, content = ?, excerpt = ?, featured_image = ? WHERE slug = ?");
            $update->execute([$post['title'], $post['content'], $post['excerpt'], $post['featured_image'], $post['slug']]);
            echo "Updated post: {$post['title']}<br>";
        } else {
            // Insert new
            $insert = $pdo->prepare("INSERT INTO blog_posts (title, slug, content, excerpt, featured_image) VALUES (?, ?, ?, ?, ?)");
            $insert->execute([$post['title'], $post['slug'], $post['content'], $post['excerpt'], $post['featured_image']]);
            echo "Inserted post: {$post['title']}<br>";
        }
    }

    echo "<br><strong>Blog posts seeded successfully! You can delete this file after running it once on your live server.</strong>";

} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage();
}
?>
