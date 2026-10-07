/** Joins the truthy class names. Variants are written so they never conflict. */
export function cn(...classes) {
  return classes.filter(Boolean).join(' ');
}
