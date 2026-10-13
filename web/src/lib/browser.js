/** Send the browser to a URL (e.g. a download link): one place, so tests can watch it. */
export const browser = {
  go: (url) => window.location.assign(url),
}
