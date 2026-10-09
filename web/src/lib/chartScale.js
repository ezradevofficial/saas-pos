// LAY-01: scale and shapes of the dashboard's SVG chart.

/** A round number at or above `max`, so gridlines fall on readable values. */
export function niceMax(max) {
  if (!(max > 0)) return 1
  const power = 10 ** Math.floor(Math.log10(max))
  const step = [1, 2, 2.5, 5, 10].find((candidate) => candidate * power >= max) ?? 10
  return step * power
}

/** Path of a bar whose top corners are rounded (the data end), anchored to the baseline. */
export function barPath(x, y, width, height, radius) {
  const r = Math.min(radius, width / 2, height)
  if (height <= 0) return ''
  return `M${x},${y + height}V${y + r}Q${x},${y} ${x + r},${y}H${x + width - r}Q${x + width},${y} ${x + width},${y + r}V${y + height}Z`
}
