package com.mzeevtu.api

import com.mzeevtu.util.Constants
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit

object RetrofitClient {

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

        val logging = HttpLoggingInterceptor().apply {
            level = HttpLoggingInterceptor.Level.BODY
        }

        val client = OkHttpClient.Builder()
            .connectTimeout(30, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .writeTimeout(30, TimeUnit.SECONDS)
            .addInterceptor { chain ->
                val req = chain.request().newBuilder()
                    .addHeader("X-App-Source", Constants.APP_SOURCE_HEADER)
                    .addHeader("Accept", "application/json")
                    .addHeader("Content-Type", "application/json")
                    .build()
                chain.proceed(req)
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
