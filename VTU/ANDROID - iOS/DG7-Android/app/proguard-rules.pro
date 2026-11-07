# Gson uses reflection to (de)serialize model fields by name — keep field names intact
# or every API response silently fails to parse into these classes after obfuscation.
-keep class com.datagifting.app.data.model.** { *; }
-keep class com.datagifting.app.api.** { *; }
-keepattributes Signature
-keepattributes *Annotation*
-keepattributes EnclosingMethod
-keepattributes InnerClasses

# Gson
-keep class com.google.gson.stream.** { *; }
-keep class sun.misc.Unsafe { *; }
-dontwarn sun.misc.**

# TypeToken: R8's classic "java.lang.Class cannot be cast to java.lang.reflect.ParameterizedType"
# failure mode. These variants (Gson's documented R8 fix) let R8 rename/remove as normal but
# forbid the structural changes that break generic lookups.
-keep,allowobfuscation,allowshrinking class com.google.gson.reflect.TypeToken
-keep,allowobfuscation,allowshrinking class * extends com.google.gson.reflect.TypeToken

# Retrofit / OkHttp
-dontwarn okhttp3.**
-dontwarn okio.**
-dontwarn retrofit2.**
-keepattributes Exceptions
-keepclasseswithmembers class * {
    @retrofit2.http.* <methods>;
}

# Retrofit suspend functions: keep Continuation generics from being erased.
-keep,allowobfuscation,allowshrinking class kotlin.coroutines.Continuation

# Preserve generic type signatures on Retrofit interface methods (Response<T>). Without these
# R8 strips them and Retrofit throws "Response must include generic type", and Gson hits
# "Class cannot be cast to ParameterizedType" — release-only crashes.
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
-keep,allowobfuscation,allowshrinking class retrofit2.Response { *; }

# Kotlin metadata for generic info restoration
-keepattributes RuntimeVisibleAnnotations
-keepattributes RuntimeVisibleParameterAnnotations
-keepattributes RuntimeVisibleTypeAnnotations

# Glide
-keep class com.bumptech.glide.GeneratedAppGlideModule

# Firebase Cloud Messaging
-keep class com.google.firebase.messaging.** { *; }
