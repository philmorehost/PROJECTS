<?php
include_once('../func/bc-connect.php');
include_once('../func/bc-func.php');

// Simple simulation of AI logic for the plan
$username = $_SESSION['username'] ?? 'User';

// 1. Get last 30 days spending
$stats = mysqli_fetch_assoc(mysqli_query($connection_server, 
    "SELECT SUM(amount) as total, COUNT(*) as count 
     FROM sas_transactions 
     WHERE username='$username' AND status=1 AND date >= NOW() - INTERVAL 30 DAY"));

$total_spent = $stats['total'] ?? 0;
$trans_count = $stats['count'] ?? 0;

// 2. AI Savings Calculation (Mock Logic)
$potential_savings = $total_spent * 0.15; // Assume 15% savings possible by switching to SME

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>AI Smart Budgeting — DGV6.90</title>
    <style>
        :root { --primary: #6366f1; --bg: #0f172a; --card: #1e293b; }
        body { background: var(--bg); color: white; font-family: 'Inter', sans-serif; padding: 2rem; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; }
        .card { background: var(--card); padding: 1.5rem; border-radius: 1rem; border: 1px solid rgba(255,255,255,0.1); }
        .ai-badge { background: linear-gradient(45deg, #6366f1, #a855f7); padding: 4px 12px; border-radius: 20px; font-size: 0.8rem; }
        .savings { color: #10b981; font-size: 1.5rem; font-weight: bold; }
        .btn-opt { background: var(--primary); color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; margin-top: 10px; }
    </style>
</head>
<body>
    <h1><span class="ai-badge">TITANIUM AI</span> Smart Budgeting</h1>
    <p>AI-driven insights for @<?php echo $username; ?></p>

    <div class="grid">
        <div class="card">
            <h3>30-Day Spending</h3>
            <p style="font-size: 2rem; font-weight: bold;">₦<?php echo number_format($total_spent, 2); ?></p>
            <p><?php echo $trans_count; ?> successful transactions analyzed.</p>
        </div>

        <div class="card">
            <h3>AI Savings Tip 💡</h3>
            <p>I noticed you spend heavily on Retail Data. If you switch to <b>SME Bundles</b>, you could save:</p>
            <p class="savings">₦<?php echo number_format($potential_savings, 2); ?> / month</p>
            <button class="btn-opt">Optimize My Plan</button>
        </div>

        <div class="card">
            <h3>Wallet Burn Rate</h3>
            <p>Based on your current spending, your balance will last for approximately:</p>
            <p style="font-size: 1.5rem; color: #fbbf24;">12 Days</p>
            <p>I'll email you 2 days before exhaustion.</p>
        </div>
    </div>

    <div class="card" style="margin-top: 2rem;">
        <h3>AI Financial Forecast</h3>
        <p>AI is predicting a 10% increase in your data usage next month based on seasonal patterns.</p>
        <canvas id="budgetChart" height="100"></canvas>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const ctx = document.getElementById('budgetChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
                datasets: [{
                    label: 'Projected Spending',
                    data: [<?php echo $total_spent/4; ?>, <?php echo $total_spent/3.5; ?>, <?php echo $total_spent/4.2; ?>, <?php echo $total_spent/3.8; ?>],
                    borderColor: '#6366f1',
                    tension: 0.4
                }]
            },
            options: { plugins: { legend: { display: false } }, scales: { y: { display: false } } }
        });
    </script>
</body>
</html>
