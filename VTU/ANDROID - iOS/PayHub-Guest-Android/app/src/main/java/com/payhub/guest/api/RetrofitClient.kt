package com.payhub.guest.api

import com.payhub.guest.BuildConfig
import com.google.gson.GsonBuilder
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.ResponseBody.Companion.toResponseBody
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit

object RetrofitClient {

    @Volatile private var retrofit: Retrofit? = null

    fun getService(): GuestApiService {
        if (retrofit == null) {
            synchronized(this) {
                if (retrofit == null) {
                    retrofit = buildRetrofit()
                }
            }
        }
        return retrofit!!.create(GuestApiService::class.java)
    }

    private fun buildRetrofit(): Retrofit {
        val logging = HttpLoggingInterceptor().apply {
            level = if (BuildConfig.DEBUG) HttpLoggingInterceptor.Level.BODY else HttpLoggingInterceptor.Level.NONE
        }

        // Deliberately uses the platform default TLS trust manager/hostname verifier — no
        // trust-all override. See the PayHub-Android RetrofitClient.kt fix from this same
        // security pass for the bug this avoids repeating.
        val client = OkHttpClient.Builder()
            .connectTimeout(30, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .writeTimeout(30, TimeUnit.SECONDS)
            .addInterceptor { chain ->
                val req = chain.request().newBuilder()
                    .addHeader("X-App-Source", "payhub-guest-android")
                    .addHeader("Accept", "application/json")
                    .addHeader("Content-Type", "application/json")
                    .build()
                chain.proceed(req)
            }
            // Safety interceptor: convert non-JSON server responses (HTML error pages, PHP notices)
            // into a well-formed JSON error so Gson never crashes with setLenient errors.
            .addInterceptor { chain ->
                val response = chain.proceed(chain.request())
                val contentType = response.header("Content-Type") ?: ""
                if (!contentType.contains("application/json", ignoreCase = true)) {
                    val rawBody = response.body?.string() ?: ""
                    val stripped = rawBody
                        .replace(Regex("<[^>]*>"), " ")
                        .replace(Regex("\\s+"), " ")
                        .trim()
                        .take(200)
                    val safeJson = """{"status":"failed","desc":"Server returned an unexpected response. Please try again.","debug":"${stripped.replace("\"", "'")}"}"""
                    val jsonMediaType = "application/json; charset=utf-8".toMediaType()
                    response.newBuilder()
                        .body(safeJson.toResponseBody(jsonMediaType))
                        .header("Content-Type", "application/json; charset=utf-8")
                        .build()
                } else {
                    response
                }
            }
            .addInterceptor(logging)
            .build()

        val gson = GsonBuilder().setLenient().create()

        return Retrofit.Builder()
            .baseUrl(BuildConfig.BASE_URL)
            .client(client)
            .addConverterFactory(GsonConverterFactory.create(gson))
            .build()
    }
}
