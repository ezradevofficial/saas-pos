package expo.modules.appcrypto

import android.util.Base64
import expo.modules.kotlin.modules.Module
import expo.modules.kotlin.modules.ModuleDefinition
import javax.crypto.SecretKeyFactory
import javax.crypto.spec.PBEKeySpec

/**
 * AUTH-06: PBKDF2-HMAC-SHA256 for the offline PIN check, in native code
 * (Hermes has no JIT, so the pure-JS fallback takes seconds at 150,000
 * iterations). Salt in and key out are base64 (no padding needed on input).
 * PINs and card codes are ASCII, so the platform's password encoding
 * (UTF-8 for PBKDF2WithHmacSHA256) gives the same bytes as the server.
 */
class AppCryptoModule : Module() {
  override fun definition() = ModuleDefinition {
    Name("AppCrypto")

    AsyncFunction("pbkdf2Sha256") { password: String, saltBase64: String, iterations: Int, keyLength: Int ->
      require(iterations in 1..10_000_000) { "iterations out of range" }
      require(keyLength in 1..64) { "keyLength out of range" }
      val salt = Base64.decode(saltBase64, Base64.NO_WRAP)
      val spec = PBEKeySpec(password.toCharArray(), salt, iterations, keyLength * 8)
      try {
        val key = SecretKeyFactory.getInstance("PBKDF2WithHmacSHA256").generateSecret(spec).encoded
        Base64.encodeToString(key, Base64.NO_WRAP)
      } finally {
        spec.clearPassword()
      }
    }
  }
}
