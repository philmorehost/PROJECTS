import Foundation

class AIService {
    static let shared = AIService()

    private var baseURL: String {
        return "https://dgv6.com/"
    }

    func fetchAIResponse(prompt: String, completion: @escaping (Result<[String: Any], Error>) -> Void) {
        guard let url = URL(string: baseURL + "web/ai-handler.php?context=user") else {
            completion(.failure(NSError(domain: "Invalid URL", code: 0, userInfo: nil)))
            return
        }

        var request = URLRequest(url: url)
        request.httpMethod = "POST"

        let apiKey = UserDefaults.standard.string(forKey: "user_api_key") ?? ""
        let body: [String: Any] = [
            "prompt": prompt,
            "api_key": apiKey
        ]

        guard let jsonData = try? JSONSerialization.data(withJSONObject: body, options: []) else {
            completion(.failure(NSError(domain: "Serialization Error", code: 0, userInfo: nil)))
            return
        }

        request.httpBody = jsonData
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")

        URLSession.shared.dataTask(with: request) { data, response, error in
            if let error = error {
                DispatchQueue.main.async { completion(.failure(error)) }
                return
            }

            guard let data = data else {
                DispatchQueue.main.async { completion(.failure(NSError(domain: "No data", code: 0, userInfo: nil))) }
                return
            }

            do {
                if let json = try JSONSerialization.jsonObject(with: data, options: []) as? [String: Any] {
                    DispatchQueue.main.async { completion(.success(json)) }
                } else {
                    DispatchQueue.main.async { completion(.failure(NSError(domain: "Invalid JSON response", code: 0, userInfo: nil))) }
                }
            } catch {
                let friendlyError = NSError(domain: "AIService", code: 1, userInfo: [NSLocalizedDescriptionKey: "Something went wrong. Please try again."])
                DispatchQueue.main.async { completion(.failure(friendlyError)) }
            }
        }.resume()
    }
}
