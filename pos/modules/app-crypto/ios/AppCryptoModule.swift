import CommonCrypto
import ExpoModulesCore

/// AUTH-06: PBKDF2-HMAC-SHA256 for the offline PIN check, in native code
/// (Hermes has no JIT, so the pure-JS fallback takes seconds at 150,000
/// iterations). Salt in and key out are base64. The password is UTF-8.
public class AppCryptoModule: Module {
  public func definition() -> ModuleDefinition {
    Name("AppCrypto")

    AsyncFunction("pbkdf2Sha256") { (password: String, saltBase64: String, iterations: Int, keyLength: Int) throws -> String in
      guard iterations > 0, iterations <= 10_000_000, keyLength > 0, keyLength <= 64 else {
        throw Exception(name: "ERR_PBKDF2_ARGS", description: "iterations or keyLength out of range")
      }
      guard let salt = Data(base64Encoded: saltBase64) else {
        throw Exception(name: "ERR_PBKDF2_SALT", description: "salt is not base64")
      }
      let passwordBytes = Array(password.utf8)
      var derived = [UInt8](repeating: 0, count: keyLength)
      let status = salt.withUnsafeBytes { saltBuffer -> Int32 in
        CCKeyDerivationPBKDF(
          CCPBKDFAlgorithm(kCCPBKDF2),
          password, passwordBytes.count,
          saltBuffer.bindMemory(to: UInt8.self).baseAddress, salt.count,
          CCPseudoRandomAlgorithm(kCCPRFHmacAlgSHA256),
          UInt32(iterations),
          &derived, keyLength
        )
      }
      guard status == kCCSuccess else {
        throw Exception(name: "ERR_PBKDF2", description: "CCKeyDerivationPBKDF failed (\(status))")
      }
      return Data(derived).base64EncodedString()
    }
  }
}
