# Android Studio Local Development Guide (VTU APK)

This guide provides developers using **Android Studio** on their local computer with a standard architecture and code snippets to build the VTU Mobile Application.

## 1. Project Architecture (Recommended)
- **Pattern:** MVVM (Model-View-ViewModel).
- **Networking:** Retrofit2 with Gson Converter.
- **Dependency Injection:** Hilt or Koin (Optional).
- **Image Loading:** Glide or Coil.
- **Asynchronous Work:** Kotlin Coroutines.

## 2. API Interface (Retrofit)

```kotlin
interface VtuApiService {
    @POST("web/api/login.php")
    suspend fun login(@Body request: LoginRequest): Response<BaseResponse<UserData>>

    @POST("web/api/register.php")
    suspend fun register(@Body request: RegisterRequest): Response<BaseResponse<RegData>>

    @POST("web/api/profile.php")
    suspend fun getProfile(@Body apiKey: ApiKeyRequest): Response<BaseResponse<ProfileData>>

    @POST("web/api/services.php")
    suspend fun getServices(@Body apiKey: ApiKeyRequest): Response<ServiceResponse>

    @POST("web/api/airtime.php")
    suspend fun purchaseAirtime(@Body request: AirtimeRequest): Response<TransactionResponse>

    @POST("web/api/data.php")
    suspend fun purchaseData(@Body request: DataRequest): Response<TransactionResponse>

    @POST("web/api/identify-network.php")
    suspend fun identifyNetwork(@Body request: IdentifyRequest): Response<IdentifyResponse>

    @POST("web/api/check-limit.php")
    suspend fun checkLimit(@Body request: LimitRequest): Response<LimitResponse>

    @POST("web/api/batch-status.php")
    suspend fun getBatchStatus(@Body request: BatchRequest): Response<BatchResponse>
}
```

## 3. Implementation Samples

### Network Client
```kotlin
object RetrofitClient {
    private const val BASE_URL = "https://yourdomain.com/"

    private val okHttpClient = OkHttpClient.Builder()
        .addInterceptor { chain ->
            val request = chain.request().newBuilder()
                .addHeader("Accept", "application/json")
                .build()
            chain.proceed(request)
        }.build()

    val instance: VtuApiService by lazy {
        Retrofit.Builder()
            .baseUrl(BASE_URL)
            .client(okHttpClient)
            .addConverterFactory(GsonConverterFactory.create())
            .build()
            .create(VtuApiService::class.java)
    }
}
```

## 4. Design Guidelines (Bootstrap 5 Fintech Style)
To maintain consistency with the web portal:
- **Buttons:** Use `#0d6efd` for Primary, `radius: 12dp`.
- **Cards:** Use `Elevation: 2dp`, `CornerRadius: 16dp`.
- **Inputs:** Soft background `#f8f9fa`, Border color `#e9ecef`.
- **Icons:** Use Bootstrap Icons (SVGs) or FontAwesome.

## 5. Advanced Implementation Guidance

### A. Professional Background Processing
The App should use **WorkManager** (Android) or **Background Fetch** (iOS) for bulk transactions.
1. Call `airtime.php` with comma-separated numbers.
2. Store the returned `batch_number`.
3. Periodically call `batch-status.php` in the background to update individual transaction bubbles in the UI.

### B. Real-Time Limit Check
Implement a `TextWatcher` on the phone number/IUC field. Once 11 digits are reached, call `check-limit.php`. If the limit is hit, disable the "Proceed" button immediately to prevent user frustration.

## 6. Security Protocols
1. **Local Storage:** Store the `api_key` and `BASE_URL` securely using `EncryptedSharedPreferences`.
2. **SSL Pinning:** Highly recommended to prevent MITM attacks.
3. **PIN Enforcement:** While the API is PIN-less for service purchases to support speed, the App **SHOULD** still prompt for the local 4-digit PIN for 'Share Fund' and 'Wallet Withdrawal' as an extra layer of security.

## 6. Endpoints Documentation
Refer to `web/api/MOBILE_APP_SPEC.md` for full payload schemas and response formats.
