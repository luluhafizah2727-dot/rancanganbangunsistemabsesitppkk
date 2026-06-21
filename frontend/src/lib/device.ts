export function browserFingerprint() {
  const screenText = typeof window === 'undefined'
    ? ''
    : `${window.screen.width}x${window.screen.height}x${window.screen.colorDepth}`
  return [
    navigator.userAgent,
    navigator.language,
    Intl.DateTimeFormat().resolvedOptions().timeZone,
    screenText,
  ].join('|')
}
