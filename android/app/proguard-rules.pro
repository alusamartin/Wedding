# kotlinx.serialization keeps its generated serializers via the plugin; the
# @Serializable classes in this app need their companion serializers kept.
-keepclassmembers class ke.everafter.couple.data.** {
    *** Companion;
}
-keepclasseswithmembers class ke.everafter.couple.data.** {
    kotlinx.serialization.KSerializer serializer(...);
}
-keepattributes *Annotation*, InnerClasses
-dontwarn kotlinx.serialization.**

# Tink (the crypto engine behind EncryptedSharedPreferences) carries
# Error Prone's compile-time annotations, which are not on the runtime
# classpath. R8 treats each missing annotation as a hard error in release
# builds, so they are silenced here — they are annotations, not code.
-dontwarn com.google.errorprone.annotations.**
-dontwarn javax.annotation.**
