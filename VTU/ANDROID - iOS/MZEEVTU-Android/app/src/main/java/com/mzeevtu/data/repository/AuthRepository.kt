package com.mzeevtu.data.repository

import android.content.Context
import com.mzeevtu.api.RetrofitClient
import com.mzeevtu.data.model.ApiResult
import com.mzeevtu.util.PreferenceManager

/**
 * Result of the password step. With user login OTP enabled the server no longer returns the
 * account payload on a correct password - it returns a challenge, and the api_key is only
 * handed over once the emailed code is confirmed by [AuthRepository.loginWithOtp].
 */
sealed class LoginOutcome {
    data class Success(val data: Map<String, Any>) : LoginOutcome()
    data class OtpRequired(val challenge: String, val message: String) : LoginOutcome()
    data class Failure(val message: String) : LoginOutcome()
}

/** Result of submitting the registration form. */
sealed class RegisterOutcome {
    data class Success(val message: String) : RegisterOutcome()
    data class OtpRequired(val token: String, val message: String) : RegisterOutcome()
    data class Failure(val message: String) : RegisterOutcome()
}

class AuthRepository(private val context: Context) {

    private val api = RetrofitClient.getService()
    private val prefs = PreferenceManager(context)

    /** Step 1: username + password. May answer with OtpRequired instead of a session. */
    suspend fun loginStart(username: String, password: String): LoginOutcome {
        return try {
            val response = api.login(mapOf("user" to username, "pass" to password))
            if (!response.isSuccessful) return LoginOutcome.Failure(LoginHttpError.message(response.code(), response.errorBody()?.string()))

            val body = response.body() ?: return LoginOutcome.Failure("Empty response")
            when ((body["status"] as? String ?: "error").lowercase()) {
                "success" -> {
                    @Suppress("UNCHECKED_CAST")
                    val data = body["data"] as? Map<String, Any> ?: emptyMap()
                    persistLogin(data)
                    LoginOutcome.Success(data)
                }
                "otp_required" -> LoginOutcome.OtpRequired(
                    challenge = body["challenge"] as? String ?: "",
                    message = body["message"] as? String ?: "Enter the verification code sent to your email."
                )
                else -> LoginOutcome.Failure(body["message"] as? String ?: "Login failed")
            }
        } catch (e: Exception) {
            LoginOutcome.Failure(e.message ?: "Network error")
        }
    }

    /** Step 2: challenge + emailed code. This is what actually returns the api_key. */
    suspend fun loginWithOtp(challenge: String, otp: String): LoginOutcome {
        return try {
            val response = api.loginOtp(mapOf("challenge" to challenge, "otp" to otp))
            if (!response.isSuccessful) return LoginOutcome.Failure(LoginHttpError.message(response.code(), response.errorBody()?.string()))

            val body = response.body() ?: return LoginOutcome.Failure("Empty response")
            if ((body["status"] as? String ?: "error") == "success") {
                @Suppress("UNCHECKED_CAST")
                val data = body["data"] as? Map<String, Any> ?: emptyMap()
                persistLogin(data)
                LoginOutcome.Success(data)
            } else {
                LoginOutcome.Failure(body["message"] as? String ?: "Verification failed")
            }
        } catch (e: Exception) {
            LoginOutcome.Failure(e.message ?: "Network error")
        }
    }

    /** Submits the registration form. Returns OtpRequired when the server emailed a code. */
    suspend fun register(params: Map<String, Any>): RegisterOutcome {
        return try {
            val response = api.register(params)
            if (!response.isSuccessful) return RegisterOutcome.Failure("Server error: ${response.code()}")

            val body = response.body() ?: return RegisterOutcome.Failure("Empty response")
            when ((body["status"] as? String ?: "error").lowercase()) {
                "success" -> RegisterOutcome.Success(body["message"] as? String ?: "Registration successful")
                "otp_required" -> RegisterOutcome.OtpRequired(
                    token = body["registration_token"] as? String ?: "",
                    message = body["message"] as? String ?: "Enter the verification code sent to your email."
                )
                else -> RegisterOutcome.Failure(body["message"] as? String ?: "Registration failed")
            }
        } catch (e: Exception) {
            RegisterOutcome.Failure(e.message ?: "Network error")
        }
    }

    /** Confirms the emailed registration code and creates the account. */
    suspend fun registerVerify(token: String, otp: String): ApiResult<String> {
        return try {
            val response = api.register(mapOf("registration_token" to token, "otp" to otp))
            if (!response.isSuccessful) return ApiResult.Error("Server error: ${response.code()}")

            val body = response.body() ?: return ApiResult.Error("Empty response")
            if ((body["status"] as? String ?: "error") == "success") {
                ApiResult.Success(body["message"] as? String ?: "Registration successful")
            } else {
                ApiResult.Error(body["message"] as? String ?: "Verification failed")
            }
        } catch (e: Exception) {
            ApiResult.Error(e.message ?: "Network error")
        }
    }

    /** Re-sends the registration code for a pending sign-up. */
    suspend fun resendRegisterOtp(token: String): ApiResult<String> {
        return try {
            val response = api.resendRegisterOtp(mapOf("registration_token" to token))
            val body = response.body() ?: return ApiResult.Error("Empty response")
            if ((body["status"] as? String ?: "error") == "success") {
                ApiResult.Success(body["message"] as? String ?: "Code sent")
            } else {
                ApiResult.Error(body["message"] as? String ?: "Could not resend the code")
            }
        } catch (e: Exception) {
            ApiResult.Error(e.message ?: "Network error")
        }
    }

    private fun persistLogin(data: Map<String, Any>) {
        prefs.saveLoginData(
            apiKey = data["api_key"] as? String ?: "",
            username = data["username"] as? String ?: "",
            firstname = data["firstname"] as? String ?: "",
            lastname = data["lastname"] as? String ?: "",
            email = data["email"] as? String ?: "",
            phone = data["phone"] as? String ?: "",
            balance = (data["balance"] as? String)?.toDoubleOrNull() ?: 0.0,
            accountLevel = (data["account_level"] as? Double)?.toInt() ?: 1,
            pinSet = data["security_pin_set"] as? Boolean ?: false
        )
    }

    fun logout() = prefs.clear()
}