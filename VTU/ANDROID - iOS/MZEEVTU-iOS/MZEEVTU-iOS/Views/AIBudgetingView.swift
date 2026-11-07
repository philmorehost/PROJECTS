import SwiftUI

struct BudgetStatsResponse: Codable {
    let status: String
    let total_spent: Double
    let trans_count: Int
    let potential_savings: Double
    let burn_rate_days: Int
    let forecast: [Double]
}

struct AIBudgetingView: View {
    @State private var stats: BudgetStatsResponse? = nil
    @State private var isLoading = false
    @State private var feedbackMessage = ""

    var body: some View {
        ScrollView {
            VStack(spacing: 20) {
                if isLoading {
                    ProgressView("Analyzing spending patterns...")
                        .padding()
                } else if let budget = stats {
                    // Spending statistics card
                    VStack(alignment: .leading, spacing: 12) {
                        Text("30-DAY SPENDING")
                            .font(.caption)
                            .fontWeight(.bold)
                            .foregroundColor(.gray)
                        
                        Text("₦\(String(format: "%.2f", budget.total_spent))")
                            .font(.system(size: 32, weight: .bold))
                            .foregroundColor(.white)
                        
                        Text("\(budget.trans_count) successful transactions analyzed.")
                            .font(.caption)
                            .foregroundColor(.secondary)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .padding()
                    .background(Color(.systemGray6).opacity(0.15))
                    .background(Color.black.opacity(0.4))
                    .cornerRadius(16)
                    .overlay(
                        RoundedRectangle(cornerRadius: 16)
                            .stroke(Color.white.opacity(0.1), lineWidth: 1)
                    )
                    .padding(.horizontal)

                    // AI Savings suggestion
                    VStack(alignment: .leading, spacing: 12) {
                        Text("AI SAVINGS SUGGESTION 💡")
                            .font(.caption)
                            .fontWeight(.bold)
                            .foregroundColor(.green)
                        
                        Text("Switch to SME data packages to save on monthly costs:")
                            .font(.subheadline)
                            .foregroundColor(.primary)
                        
                        Text("₦\(String(format: "%.2f", budget.potential_savings)) / month")
                            .font(.title2)
                            .fontWeight(.bold)
                            .foregroundColor(.green)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .padding()
                    .background(Color.green.opacity(0.1))
                    .cornerRadius(16)
                    .overlay(
                        RoundedRectangle(cornerRadius: 16)
                            .stroke(Color.green.opacity(0.2), lineWidth: 1)
                    )
                    .padding(.horizontal)

                    // Wallet Burn Rate Card
                    VStack(alignment: .leading, spacing: 12) {
                        Text("WALLET BURN RATE")
                            .font(.caption)
                            .fontWeight(.bold)
                            .foregroundColor(.yellow)
                        
                        Text("\(budget.burn_rate_days) Days Remaining")
                            .font(.title2)
                            .fontWeight(.bold)
                            .foregroundColor(.yellow)
                        
                        Text("AI forecasts that your balance will last based on standard patterns.")
                            .font(.caption)
                            .foregroundColor(.secondary)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .padding()
                    .background(Color.yellow.opacity(0.1))
                    .cornerRadius(16)
                    .overlay(
                        RoundedRectangle(cornerRadius: 16)
                            .stroke(Color.yellow.opacity(0.2), lineWidth: 1)
                    )
                    .padding(.horizontal)

                    // Weekly Forecast custom Line Graph
                    VStack(alignment: .leading, spacing: 12) {
                        Text("WEEKLY FORECAST PREDICTION")
                            .font(.caption)
                            .fontWeight(.bold)
                            .foregroundColor(.gray)
                        
                        // Custom Drawn line graph
                        BudgetLineChart(points: budget.forecast)
                            .frame(height: 140)
                            .padding(.vertical)
                        
                        HStack {
                            ForEach(["W1", "W2", "W3", "W4"], id: \.self) { week in
                                Spacer()
                                Text(week)
                                    .font(.system(size: 10, weight: .bold))
                                    .foregroundColor(.secondary)
                                Spacer()
                            }
                        }
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .padding()
                    .background(Color(.systemGray6).opacity(0.15))
                    .cornerRadius(16)
                    .padding(.horizontal)

                } else if !feedbackMessage.isEmpty {
                    Text(feedbackMessage)
                        .foregroundColor(.red)
                        .padding()
                }
            }
            .padding(.top)
        }
        .navigationTitle("AI Smart Budgeting")
        .onAppear(perform: fetchStats)
    }

    private func fetchStats() {
        isLoading = true
        AppNetworkService.shared.request("ai-budgeting.php", params: [:]) { (result: Result<BudgetStatsResponse, Error>) in
            isLoading = false
            switch result {
            case .success(let response):
                if response.status == "success" {
                    self.stats = response
                } else {
                    self.feedbackMessage = "Failed to fetch budgeting analytics."
                }
            case .failure(let error):
                self.feedbackMessage = error.localizedDescription
            }
        }
    }
}

// Custom simple Line graph using SwiftUI shapes
struct BudgetLineChart: View {
    let points: [Double]

    var body: some View {
        GeometryReader { geo in
            let w = geo.size.width
            let h = geo.size.height
            
            if points.count >= 2 {
                let maxVal = points.max() ?? 100.0
                let minVal = points.min() ?? 0.0
                let delta = maxVal - minVal == 0 ? 1.0 : maxVal - minVal
                
                let stepX = w / CGFloat(points.count - 1)
                
                Path { path in
                    for (idx, val) in points.enumerated() {
                        let pct = CGFloat((val - minVal) / delta)
                        let py = h - (pct * (h * 0.7) + (h * 0.15))
                        let px = CGFloat(idx) * stepX
                        
                        if idx == 0 {
                            path.move(to: CGPoint(x: px, y: py))
                        } else {
                            path.addLine(to: CGPoint(x: px, y: py))
                        }
                    }
                }
                .stroke(Color.blue, lineWidth: 4)
                
                // Draw dots
                ForEach(Array(points.enumerated()), id: \.offset) { idx, val in
                    let pct = CGFloat((val - minVal) / delta)
                    let py = h - (pct * (h * 0.7) + (h * 0.15))
                    let px = CGFloat(idx) * stepX
                    
                    Circle()
                        .fill(Color.green)
                        .frame(width: 10, height: 10)
                        .position(x: px, y: py)
                }
            }
        }
    }
}
