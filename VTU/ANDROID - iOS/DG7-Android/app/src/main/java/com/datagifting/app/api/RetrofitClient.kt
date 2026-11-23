package com.datagifting.app.api

import com.datagifting.app.util.Constants
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.logging.HttpLoggingInterceptor
import okio.Buffer
import org.json.JSONObject
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit

/**
 * Holds the transaction PIN entered for the CURRENT purchase only.
 *
 * The request interceptor appends it to the outgoing JSON so every purchase endpoint
 * (data, airtime, cable, electric, exam, betting, print hub) satisfies the vendor's
 * "force transaction PIN" setting without each screen having to build the body twice.
 * Cleared as soon as it has been attached to a request.
 */
object TransactionPin {
    @Volatile var current: String? = null
}

object RetrofitClient {
    @Volatile private var sessionContext: android.content.Context? = null
    fun install(context: android.content.Context) { sessionContext = context.applicationContext }


    @Volatile private var retrofit: Retrofit? = null

    fun getService(): VtuApiService {
        if (retrofit == null) {
            synchronized(this) {
                if (retrofit == null) {
                    retrofit = buildRetrofit()
                }
            }
        }
        return retrofit!!.create(VtuApiService::class.java)
    }

    /** Call when BASE_URL changes (first-run setup) */
    fun reset() { retrofit = null }

    private fun buildRetrofit(): Retrofit {
        val baseUrl = Constants.BASE_URL

        // Do NOT log bodies: they contain the api_key, the transaction PIN and OTP codes.
        // The previous BODY level wrote all three to logcat on every request.
        val logging = HttpLoggingInterceptor().apply {
            level = HttpLoggingInterceptor.Level.NONE
        }

        val client = OkHttpClient.Builder()
            // System trust store + hostname verification. The previous trust-all
            // TrustManager/hostnameVerifier accepted ANY certificate, so anyone on the
            // same Wi-Fi could transparently MITM the app and read the api_key.
            .connectTimeout(30, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .writeTimeout(30, TimeUnit.SECONDS)
            .addInterceptor { chain ->
                val original = chain.request()
                var builder = original.newBuilder()
                    .addHeader("X-App-Source", Constants.APP_SOURCE_HEADER)
                    .addHeader("Accept", "application/json")

                // Resolve the current secure session for EVERY call (login/logout can rotate it).
                val token = sessionContext?.let { com.datagifting.app.util.PreferenceManager(it).getApiKey() }
                val publicAuth = original.url.encodedPath.substringAfterLast('/') in setOf("login.php", "login-otp.php", "register.php", "resend-register-otp.php", "resend-login-otp.php", "password-recovery.php")
                if (!publicAuth && !token.isNullOrBlank()) builder.header("Authorization", "Bearer $token")

                val pin = TransactionPin.current
                val body = original.body
                if (!pin.isNullOrEmpty() && body != null && original.method.equals("POST", true)) {
                    try {
                        val buffer = Buffer()
                        body.writeTo(buffer)
                        val text = buffer.readUtf8()
                        if (text.trimStart().startsWith("{")) {
                            val json = JSONObject(text)
                            if (!json.has("pin")) {
                                json.put("pin", pin)
                                builder = builder.method(
                                    original.method,
                                    json.toString().toRequestBody("application/json".toMediaType())
                                )
                                TransactionPin.current = null
                            }
                        }
                    } catch (_: Exception) {
                        // Never break a request because the PIN could not be attached;
                        // the server will simply answer pin_required.
                    }
                }

                chain.proceed(builder.build()).also { com.datagifting.app.util.RequiredSetup.inspect(it) }
            }
            .addInterceptor(logging)
            .build()

        return Retrofit.Builder()
            .baseUrl(baseUrl)
            .client(client)
            .addConverterFactory(GsonConverterFactory.create())
            .build()
    }
}
