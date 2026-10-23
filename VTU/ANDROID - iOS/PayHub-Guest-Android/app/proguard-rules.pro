# Gson uses reflection to (de)serialize model fields by name — keep field names intact
# or every API response silently fails to parse into these classes after obfuscation.
-keep class com.payhub.guest.data.model.** { *; }
-keep class com.payhub.guest.api.** { *; }
-keepattributes Signature
-keepattributes *Annotation*
-keepattributes EnclosingMethod
-keepattributes InnerClasses

# Gson
-keep class com.google.gson.stream.** { *; }
-keep class sun.misc.Unsafe { *; }
-dontwarn sun.misc.**

# "java.lang.Class cannot be cast to java.lang.reflect.ParameterizedType" is R8's classic
# TypeToken failure mode: a plain `-keep class * extends TypeToken` still lets R8 merge/simplify
# the anonymous subclass in ways that erase its generic superclass signature at runtime. The
# `,allowobfuscation,allowshrinking` variants below (Gson's own documented R8 fix) let R8 rename
# and remove-if-unused as normal, but forbid the structural changes that break the generic lookup.
-keep,allowobfuscation,allowshrinking class com.google.gson.reflect.TypeToken
-keep,allowobfuscation,allowshrinking class * extends com.google.gson.reflect.TypeToken

# Retrofit / OkHttp (bundled consumer-rules cover most of this; kept for older AGP/R8 configs)
-dontwarn okhttp3.**
-dontwarn okio.**
-dontwarn retrofit2.**
-keepattributes Exceptions
-keepclasseswithmembers class * {
    @retrofit2.http.* <methods>;
}

# Retrofit's suspend-fun call adapter inspects the Continuation parameter's generic signature to
# find the real response type — the other classic source of the same ParameterizedType crash,
# specific to Kotlin coroutine endpoints (every method in GuestApiService.kt). Official Retrofit
# R8 rule: https://github.com/square/retrofit/blob/master/retrofit/src/main/resources/META-INF/proguard/retrofit2.pro
-keep,allowobfuscation,allowshrinking class kotlin.coroutines.Continuation

# ─── CRITICAL FIX: Preserve generic type signatures on Retrofit interface methods ──────────────
# R8 strips the generic method signatures (Response<AirtimeCatalogResponse>, etc.) from the
# Retrofit service interface at the bytecode level. Retrofit reads these signatures at RUNTIME
# using reflection to know how to deserialize the response body. Without them you get:
#   "Response must include generic type (e.g., Response<String>) for method getAirtimeCatalog"
# This was the root cause of all VTU services failing in the release build while debug worked fine.
# The `-keepclasseswithmembers` above only keeps classes with annotated methods; the rule below
# explicitly preserves the generic-signature metadata on every method of every Retrofit interface.
-keep,allowobfuscation,allowshrinking interface * {
    @retrofit2.http.GET <methods>;
    @retrofit2.http.POST <methods>;
    @retrofit2.http.PUT <methods>;
    @retrofit2.http.PATCH <methods>;
    @retrofit2.http.DELETE <methods>;
    @retrofit2.http.HEAD <methods>;
    @retrofit2.http.OPTIONS <methods>;
    @retrofit2.http.HTTP <methods>;
}

# Keep the Response wrapper's generic type parameter so R8 cannot collapse/simplify it.
-keep,allowobfuscation,allowshrinking class retrofit2.Response { *; }

# Kotlin Metadata: R8 uses this to restore some generic info, but keeping it explicitly
# ensures suspend functions' Continuation<T> generics are not erased.
-keepattributes RuntimeVisibleAnnotations
-keepattributes RuntimeVisibleParameterAnnotations
-keepattributes RuntimeVisibleTypeAnnotations
