<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>License Manager - Manage Your Licenses</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        
        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            max-width: 800px;
            width: 100%;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 3rem 2rem;
            text-align: center;
        }
        
        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }
        
        .header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
        .content {
            padding: 3rem 2rem;
        }
        
        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }
        
        .card {
            background: #f7fafc;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .card:hover {
            border-color: #667eea;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.2);
            transform: translateY(-4px);
        }
        
        .card-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        
        .card h3 {
            color: #2d3748;
            font-size: 1.4rem;
            margin-bottom: 0.5rem;
        }
        
        .card p {
            color: #718096;
            line-height: 1.6;
            margin-bottom: 1.5rem;
        }
        
        .card a {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 0.8rem 1.5rem;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 500;
            transition: transform 0.2s ease;
        }
        
        .card a:hover {
            transform: scale(1.05);
        }
        
        .divider {
            height: 1px;
            background: #e2e8f0;
            margin: 2rem 0;
        }
        
        .info-section {
            background: #f0f4ff;
            border-left: 4px solid #667eea;
            padding: 1.5rem;
            border-radius: 6px;
            margin-top: 2rem;
        }
        
        .info-section h4 {
            color: #667eea;
            margin-bottom: 0.5rem;
        }
        
        .info-section p {
            color: #718096;
            line-height: 1.6;
        }
        
        .footer {
            background: #f7fafc;
            padding: 2rem;
            border-top: 1px solid #e2e8f0;
            text-align: center;
            color: #718096;
            font-size: 0.95rem;
        }

        @media (max-width: 600px) {
            .header h1 {
                font-size: 1.8rem;
            }
            
            .cards {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔐 License Manager</h1>
            <p>Manage Your Licenses with Ease</p>
        </div>
        
        <div class="content">
            <div class="cards">
                <div class="card">
                    <div class="card-icon">📦</div>
                    <h3>Purchase License</h3>
                    <p>Buy a new license for your domain and get instant activation.</p>
                    <a href="order.php">Order Now</a>
                </div>
                
                <div class="card">
                    <div class="card-icon">📚</div>
                    <h3>API Documentation</h3>
                    <p>Integrate license validation into your website with our public API.</p>
                    <a href="api-docs.php">View Docs</a>
                </div>

                <div class="card">
                    <div class="card-icon">👤</div>
                    <h3>My Account</h3>
                    <p>Access your licenses and manage your account details.</p>
                    <a href="user/login.php">Login Now</a>
                </div>
            </div>
            
            <div class="info-section">
                <h4>💡 Quick Links</h4>
                <p>
                    <a href="order.php" style="color: #667eea; text-decoration: none; font-weight: 500;">Purchase a License</a> • 
                    <a href="api-docs.php" style="color: #667eea; text-decoration: none; font-weight: 500;">API Documentation</a> • 
                    <a href="user/login.php" style="color: #667eea; text-decoration: none; font-weight: 500;">My Account</a> • 
                    <a href="admin/index.php" style="color: #667eea; text-decoration: none; font-weight: 500;">Admin Login</a>
                </p>
            </div>
        </div>
        
        <div class="footer">
            <p>&copy; License Manager. All rights reserved.</p>
        </div>
    </div>
</body>
</html>

