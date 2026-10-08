Pod::Spec.new do |s|
  s.name           = 'AppCrypto'
  s.version        = '0.1.0'
  s.summary        = 'PBKDF2-HMAC-SHA256 for the offline PIN check (local Expo module).'
  s.description    = 'PBKDF2-HMAC-SHA256 through CommonCrypto for the offline PIN check.'
  s.license        = 'UNLICENSED'
  s.author         = 'platform'
  s.homepage       = 'https://localhost'
  s.platforms      = { :ios => '16.4' }
  s.swift_version  = '5.9'
  s.source         = { git: '' }
  s.static_framework = true

  s.dependency 'ExpoModulesCore'

  s.source_files = '**/*.{h,m,swift}'
  s.pod_target_xcconfig = {
    'DEFINES_MODULE' => 'YES',
    'SWIFT_COMPILATION_MODE' => 'wholemodule'
  }
end
